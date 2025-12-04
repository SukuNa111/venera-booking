<?php
// ============================================
// CONFIG.PHP — Venera-Dent Booking System
// Skytel WEB2SMS Integration + PostgreSQL
// ============================================

date_default_timezone_set('Asia/Ulaanbaatar');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -----------------------
// Database Configuration
// -----------------------
// PostgreSQL connection - Supports both Docker and Local
define('DB_TYPE', 'pgsql');
define('DB_HOST', getenv('DB_HOST') ?: '192.168.1.94');
define('DB_PORT', getenv('DB_PORT') ?: 5432);
define('DB_NAME', getenv('DB_NAME') ?: 'hospital_db');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '1234');

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;

    try {
        if (DB_TYPE === 'pgsql') {
            $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';options=\'--client_encoding=UTF8\'';
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        }
        
        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        // Set UTF-8 encoding for PostgreSQL
        if (DB_TYPE === 'pgsql') {
            $pdo->exec("SET NAMES 'UTF8'");
        }
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
    // Docker: DocumentRoot = /var/www/html/public, тиймээс URL нь зүгээр /
    return '/' . ltrim($path, '/');
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
// Skytel WEB2SMS API Sender
// -----------------------
function sendSMS($phone, $message, $booking_id = null) {
    $status = 'logged';
    $httpCode = 0;
    $response = null;
    $errorDetail = '';
    
    // Skytel WEB2SMS API token
    $skytelToken = '4d5a863d5a97a5f56d01a4e3912caafa356f2311';
    
    // Format phone number (8 digit Mongolian number)
    $phoneClean = preg_replace('/\D/', '', $phone);
    if (substr($phoneClean, 0, 3) === '976') {
        $phoneClean = substr($phoneClean, 3);
    }
    
    if (strlen($phoneClean) !== 8) {
        $errorDetail = 'Утасны дугаар буруу (8 оронтой байх ёстой)';
        $status = 'failed';
        logSMS($booking_id, $phone, $message, $status, 0, null, $errorDetail);
        return ['ok' => false, 'error' => $errorDetail];
    }
    
    // Build Skytel API URL
    $url = "http://web2sms.skytel.mn/apiSend?token=" . $skytelToken 
         . "&sendto=" . $phoneClean 
         . "&message=" . rawurlencode($message);
    
    // Send request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Check response
    if ($curlError) {
        $errorDetail = 'cURL Error: ' . $curlError;
        $status = 'failed';
    } elseif ($httpCode === 200) {
        $jsonResponse = @json_decode($response, true);
        
        if (is_array($jsonResponse)) {
            if (isset($jsonResponse['status']) && $jsonResponse['status'] == 1) {
                $status = 'sent';
            } else {
                $status = 'failed';
                $errorDetail = $jsonResponse['message'] ?? 'Skytel алдаа';
            }
        } else {
            // Simple response check
            if ($response === '1' || stripos($response, 'success') !== false) {
                $status = 'sent';
            } else {
                $status = 'failed';
                $errorDetail = "Response: $response";
            }
        }
    } else {
        $errorDetail = "HTTP $httpCode";
        $status = 'failed';
    }
    
    // Log to database
    logSMS($booking_id, $phone, $message, $status, $httpCode, $response, $errorDetail);
    
    return [
        'ok' => $status === 'sent',
        'status' => $status,
        'error' => $errorDetail
    ];
}

// -----------------------
// SMS Logger
// -----------------------
function logSMS($booking_id, $phone, $message, $status, $httpCode, $response, $error) {
    try {
        $stmt = db()->prepare("
            INSERT INTO sms_log (booking_id, phone, message, status, http_code, response, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$booking_id, $phone, $message, $status, $httpCode, $response, $error]);
    } catch (Exception $e) {
        error_log("SMS log error: " . $e->getMessage());
    }
}

// -----------------------
// Phone Number Formatter
// -----------------------
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', trim($phone));
    
    if (empty($phone)) return false;
    
    // Remove 976 prefix if exists
    if (substr($phone, 0, 3) === '976' && strlen($phone) === 11) {
        $phone = substr($phone, 3);
    }
    
    // Must be 8 digits
    if (strlen($phone) === 8) {
        return $phone;
    }
    
    return false;
}
?>
