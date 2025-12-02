<?php
// ============================================
// CONFIG.PHP — Twilio SMS Integration (Fixed Authentication)
// ============================================

date_default_timezone_set('Asia/Ulaanbaatar');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -----------------------
// Database Configuration
// -----------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'hospital_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        echo '❌ DB connection failed: ' . htmlspecialchars($e->getMessage());
        exit;
    }
    return $pdo;
}

// -----------------------
// URL Helper
// -----------------------
function app_url($path = '') {
    return '/booking/public/' . ltrim($path, '/');
}

// -----------------------
// Auth Functions
// -----------------------
function require_login() {
    if (empty($_SESSION['uid'])) {
        header('Location: ' . app_url('login.php'));
        exit;
    }
}

function current_user() {
    return [
        'id'        => $_SESSION['uid'] ?? 0,
        'name'      => $_SESSION['name'] ?? '',
        'role'      => $_SESSION['role'] ?? '',
        'clinic_id' => $_SESSION['clinic_id'] ?? ''
    ];
}

function require_role($roles) {
    $r = $_SESSION['role'] ?? '';
    if (!in_array($r, (array)$roles)) {
        http_response_code(403);
        echo "<h3 style='color:red;text-align:center;margin-top:2rem'>🚫 Хандалт хориглосон</h3>";
        exit;
    }
}

// -----------------------
// Menu Highlight
// -----------------------
function active($file) {
    return basename($_SERVER['SCRIPT_NAME']) === $file ? 'active' : '';
}

// -----------------------
// JSON Output Helper
// -----------------------
function json_out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------
// .ENV Loader
// -----------------------
function get_env($key) {
    static $env = null;
    if ($env === null) {
        $envFile = __DIR__ . '/.env';
        if (!file_exists($envFile)) {
            error_log("❌ .env file not found at: " . $envFile);
            return null;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) continue;
            
            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }
    
    return $env[$key] ?? null;
}

// -----------------------
// Twilio Credentials Validator
// -----------------------
function validateTwilioCredentials() {
    $sid = get_env('TWILIO_SID');
    $token = get_env('TWILIO_TOKEN');
    $from = get_env('TWILIO_FROM');
    
    $errors = [];
    
    // Check SID format: ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (34 chars)
    if (empty($sid)) {
        $errors[] = 'TWILIO_SID is empty';
    } elseif (!preg_match('/^AC[a-f0-9]{32}$/i', $sid)) {
        $errors[] = 'TWILIO_SID format invalid (should be AC followed by 32 hex chars)';
    }
    
    // Check Token format: 32 hex characters
    if (empty($token)) {
        $errors[] = 'TWILIO_TOKEN is empty';
    } elseif (strlen($token) !== 32) {
        $errors[] = 'TWILIO_TOKEN length invalid (should be 32 characters, currently ' . strlen($token) . ')';
    }
    
    // Check From number format
    if (empty($from)) {
        $errors[] = 'TWILIO_FROM is empty';
    } elseif (!preg_match('/^\+\d{10,15}$/', $from)) {
        $errors[] = 'TWILIO_FROM format invalid (should be +1234567890)';
    }
    
    if (!empty($errors)) {
        error_log("⚠️ Twilio Configuration Errors:\n" . implode("\n", $errors));
        return ['valid' => false, 'errors' => $errors];
    }
    
    return ['valid' => true, 'sid' => $sid, 'from' => $from];
}

// -----------------------
// Skytel WEB2SMS API Sender
// -----------------------
function sendSMS($phone, $message, $booking_id = null) {
    error_log("🔔 sendSMS called: phone=$phone, booking_id=$booking_id, message_len=" . strlen($message));
    
    $status = 'logged';
    $httpCode = 0;
    $response = null;
    $errorDetail = '';
    
    // Skytel WEB2SMS API credentials
    $skytelToken = '4d5a863d5a97a5f56d01a4e3912caafa356f2311';
    
    // Check if SMS is disabled
    $smsDisabled = get_env('SMS_DISABLED') === 'true';
    if ($smsDisabled) {
        error_log("⚠️ SMS is disabled - logging only");
        $errorDetail = 'SMS disabled';
        $status = 'logged';
        logSMS($booking_id, $phone, $message, $status, 0, null, $errorDetail);
        return [
            'ok' => true,
            'status' => $status,
            'http_code' => 0,
            'response' => null,
            'error' => $errorDetail,
            'message' => 'SMS logged (disabled - not sent)'
        ];
    }
    
    // Format phone number for Skytel (just digits, 8 characters for Mongolian numbers)
    $phoneClean = preg_replace('/\D/', '', $phone);
    // Remove country code if present
    if (substr($phoneClean, 0, 3) === '976') {
        $phoneClean = substr($phoneClean, 3);
    }
    
    if (strlen($phoneClean) !== 8) {
        error_log("❌ Invalid phone number format: $phone -> $phoneClean");
        $errorDetail = 'Invalid phone format (must be 8 digits)';
        $status = 'failed';
        logSMS($booking_id, $phone, $message, $status, 0, null, $errorDetail);
        return [
            'ok' => false,
            'status' => $status,
            'http_code' => 0,
            'response' => null,
            'error' => $errorDetail,
            'message' => 'Invalid phone number'
        ];
    }
    
    // Build Skytel API URL
    $url = "http://web2sms.skytel.mn/apiSend?" . http_build_query([
        'token' => $skytelToken,
        'sendto' => $phoneClean,
        'message' => $message
    ]);
    
    error_log("📤 Skytel API URL: " . substr($url, 0, 100) . "...");
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    error_log("📥 Skytel Response: HTTP $httpCode, Body: $response");
    
    // Check response
    if ($curlError) {
        $errorDetail = 'cURL Error: ' . $curlError;
        $status = 'failed';
    } elseif ($httpCode === 200) {
        // Parse Skytel JSON response
        $jsonResponse = @json_decode($response, true);
        
        if (is_array($jsonResponse)) {
            // Skytel returns {"status":1,"sent_count":1} on success
            // or {"status":0,"sent_count":0,"message":"error"} on failure
            if (isset($jsonResponse['status']) && $jsonResponse['status'] == 1 && isset($jsonResponse['sent_count']) && $jsonResponse['sent_count'] > 0) {
                $status = 'sent';
                error_log("✅ SMS Sent to $phoneClean via Skytel");
            } else {
                $status = 'failed';
                $errorDetail = $jsonResponse['message'] ?? 'Unknown Skytel error';
                error_log("❌ SMS failed: $errorDetail");
            }
        } else {
            // Non-JSON response, check for simple success indicators
            if (stripos($response, 'success') !== false || $response === '1') {
                $status = 'sent';
                error_log("✅ SMS Sent to $phoneClean via Skytel");
            } else {
                $status = 'failed';
                $errorDetail = "Unexpected response: $response";
                error_log("❌ SMS failed: $errorDetail");
            }
        }
    } else {
        $errorDetail = "HTTP $httpCode: $response";
        $status = 'failed';
        error_log("❌ SMS failed: $errorDetail");
    }
    
    // Log to database
    logSMS($booking_id, $phone, $message, $status, $httpCode, $response, $errorDetail);
    
    return [
        'ok' => $status === 'sent',
        'status' => $status,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $errorDetail,
        'message' => $status === 'sent' ? 'SMS sent successfully via Skytel' : 'SMS failed'
    ];
}

// -----------------------
// SMS Logger
// -----------------------
function logSMS($booking_id, $phone, $message, $status, $httpCode, $response, $error) {
    try {
        $pdo = db();
        error_log("📝 Logging SMS: booking_id=$booking_id, phone=$phone, status=$status, http_code=$httpCode");
        
        $stmt = $pdo->prepare("
            INSERT INTO sms_log (booking_id, phone, message, status, http_code, response, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $result = $stmt->execute([$booking_id, $phone, $message, $status, $httpCode, $response, $error]);
        
        if ($result) {
            error_log("✅ SMS logged successfully - ID: " . ($booking_id ?? 'NULL'));
        } else {
            error_log("❌ SMS log insert failed");
        }
    } catch (Exception $e) {
        error_log("❌ SMS log database error: " . $e->getMessage());
    }
}

// -----------------------
// Phone Number Formatter
// -----------------------
function formatPhoneNumber($phone) {
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    
    if (empty($phone)) return false;
    if (strlen($phone) > 15) return false;
    
    // +976XXXXXXXX (11 chars)
    if (preg_match('/^\+976\d{8}$/', $phone)) {
        return $phone;
    }
    
    // 976XXXXXXXX (11 chars without +)
    if (preg_match('/^976\d{8}$/', $phone)) {
        return '+' . $phone;
    }
    
    // 0XXXXXXXX (9 chars starting with 0)
    if (preg_match('/^0(\d{8})$/', $phone, $matches)) {
        return '+976' . $matches[1];
    }
    
    // XXXXXXXX (8 chars)
    if (preg_match('/^\d{8}$/', $phone)) {
        return '+976' . $phone;
    }
    
    error_log("❌ Invalid phone format: " . $phone);
    return false;
}

// -----------------------
// Debug Helper
// -----------------------
function debugTwilioConfig() {
    $validation = validateTwilioCredentials();
    
    echo "<div style='background:#f5f5f5;padding:20px;border-radius:8px;font-family:monospace;'>";
    echo "<h3>🔍 Twilio Configuration Debug</h3>";
    
    $sid = get_env('TWILIO_SID');
    $token = get_env('TWILIO_TOKEN');
    $from = get_env('TWILIO_FROM');
    
    echo "<table style='width:100%;border-collapse:collapse;'>";
    echo "<tr><td><strong>TWILIO_SID:</strong></td><td>" . ($sid ? substr($sid, 0, 8) . '...' : '❌ Missing') . " (" . strlen($sid) . " chars)</td></tr>";
    echo "<tr><td><strong>TWILIO_TOKEN:</strong></td><td>" . ($token ? str_repeat('*', strlen($token)) : '❌ Missing') . " (" . strlen($token) . " chars)</td></tr>";
    echo "<tr><td><strong>TWILIO_FROM:</strong></td><td>" . ($from ?: '❌ Missing') . "</td></tr>";
    echo "<tr><td><strong>Validation:</strong></td><td style='color:" . ($validation['valid'] ? 'green' : 'red') . "'>" . ($validation['valid'] ? '✅ Valid' : '❌ Invalid') . "</td></tr>";
    echo "</table>";
    
    if (!$validation['valid']) {
        echo "<div style='color:red;margin-top:15px;'><strong>Errors:</strong><ul>";
        foreach ($validation['errors'] as $error) {
            echo "<li>{$error}</li>";
        }
        echo "</ul></div>";
    }
    
    echo "</div>";
}
?>