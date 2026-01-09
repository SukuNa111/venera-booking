<?php
// ============================================
// CONFIG.PHP — Venera-Dent Booking System
// Skytel WEB2SMS Integration + PostgreSQL
// ============================================

date_default_timezone_set('Asia/Ulaanbaatar');

// -----------------------
// Load Environment Variables
// -----------------------
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

// -----------------------
// Security Settings
// -----------------------
$isProduction = getenv('APP_ENV') === 'production';
$isDebug = getenv('APP_DEBUG') === 'true';

// Session Security
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $isProduction ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// -----------------------
// Database Configuration
// -----------------------
// PostgreSQL connection - Supports both Docker and Local
define('DB_TYPE', 'pgsql');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
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
        // Only set HTTP response code if we're in a web context
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
        }
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
    $id   = $_SESSION['uid'] ?? 0;
    $name = $_SESSION['name'] ?? '';
    $role = $_SESSION['role'] ?? '';
    $clinic = $_SESSION['clinic_id'] ?? '';
    $dept = $_SESSION['department'] ?? '';
    if ($id && $dept === '') {
        try {
            $st = db()->prepare('SELECT department FROM users WHERE id = ?');
            $st->execute([$id]);
            $d = (string)$st->fetchColumn();
            if ($d !== '') {
                $_SESSION['department'] = $d;
                $dept = $d;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    return [
        'id'         => $id,
        'name'       => $name,
        'role'       => $role,
        'clinic_id'  => $clinic,
        'department' => $dept,
        'is_super_admin' => $role === 'super_admin'
    ];
}

function require_role($roles) {
    $r = $_SESSION['role'] ?? '';
    $allowed = (array)$roles;
    // super_admin автоматаар admin эрхтэй адилхан
    if ($r === 'super_admin' && in_array('admin', $allowed)) {
        return;
    }
    if (!in_array($r, $allowed)) {
        http_response_code(403);
        echo "<h3 style='color:red;text-align:center;margin-top:2rem'>🚫 Хандалт хориглосон</h3>";
        exit;
    }
}

function is_super_admin() {
    return ($_SESSION['role'] ?? '') === 'super_admin';
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
// MBString Shims (for systems without the extension)
// -----------------------
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($text, $encoding = 'UTF-8') {
        static $map = [
            'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
            'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','Ө'=>'ө','П'=>'п',
            'Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ү'=>'ү','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч',
            'Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
        ];
        return strtr($text, $map);
    }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos($haystack, $needle, $offset = 0, $encoding = 'UTF-8') {
        $h = mb_strtolower($haystack, $encoding);
        $n = mb_strtolower($needle, $encoding);
        return stripos($h, $n, $offset);
    }
}

// -----------------------
// Smart Phone Routing (Department-based)
// -----------------------
function getPhoneForDepartment($booking_id, $clinic = 'venera', $default_phone = '70115090') {
    try {
        // Get booking with treatment info
        $st = db()->prepare("
            SELECT b.service_name, b.department AS booking_department, t.department, t.id as treatment_id 
            FROM bookings b 
            LEFT JOIN treatments t ON t.name = b.service_name AND (t.clinic = b.clinic OR t.clinic IS NULL)
            WHERE b.id = ? 
            LIMIT 1
        ");
        $st->execute([$booking_id]);
        $booking = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            return $default_phone;
        }
        
        // Helper function to extract phone(s) from value (string or array/json)
        $extractPhone = function($value) use ($default_phone) {
            $data = is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0) ? json_decode($value, true) : $value;
            if (is_array($data)) {
                $filtered = array_filter($data, fn($v) => !empty(trim($v)));
                return !empty($filtered) ? implode(', ', $filtered) : $default_phone;
            }
            return !empty(trim($data)) ? $data : $default_phone;
        };

        // For Khatan clinic: use simple clinic phone
        if ($clinic === 'khatan') {
            $st = db()->prepare("SELECT value FROM app_settings WHERE clinic = ? AND key = ? LIMIT 1");
            $st->execute(['khatan', 'clinic_phone']);
            $result = $st->fetch(PDO::FETCH_ASSOC);
            return $result ? $extractPhone($result['value']) : $default_phone;
        }
        
        // For Venera/Golden Luxor: use department phones
        $st = db()->prepare("SELECT value FROM app_settings WHERE clinic = ? AND key = ? LIMIT 1");
        $st->execute([$clinic, 'department_phones']);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return $default_phone;
        }
        
        $dept_phones = json_decode($result['value'], true) ?: [];
        
        // Prefer explicit booking.department if present
        $deptSource = '';
        if (!empty($booking['booking_department'])) {
            $deptSource = $booking['booking_department'];
        } elseif (!empty($booking['department'])) {
            // Then treatment.department
            $deptSource = $booking['department'];
        }

        if ($deptSource !== '') {
            $deptLower = mb_strtolower(trim($deptSource), 'UTF-8');
            
            // Map treatment department to phone key
            $dept_key_map = [
                'dental' => 'dental',
                'шүд' => 'dental',
                'шүдний тасаг' => 'dental',
                'traditional' => 'traditional',
                'уламжлалт' => 'traditional',
                'уламжлалт анагаа' => 'traditional',
                'drip' => 'drip',
                'дусал' => 'drip',
                'дусал / сувилахуй' => 'drip',
                'nonsurgical' => 'nonsurgical',
                'мэсийн бус' => 'nonsurgical',
                'мэсийн бус гоо сайхан' => 'nonsurgical',
                'surgical' => 'surgical',
                'мэс засал' => 'surgical',
                'үзлэг' => 'examination',
                'массаж' => 'massage',
                'massage' => 'massage'
            ];
            
            if (isset($dept_key_map[$deptLower]) && isset($dept_phones[$dept_key_map[$deptLower]])) {
                return $extractPhone($dept_phones[$dept_key_map[$deptLower]]);
            }
        }
        
        // Fallback: keyword matching in service name
        $service = mb_strtolower($booking['service_name'] ?? '', 'UTF-8');
        $dept_map = [
            'dental' => ['шүд', 'tooth', 'dent'],
            'traditional' => ['уламжлалт', 'traditional', 'хөнгө', 'массаж'],
            'drip' => ['дусал', 'сувилахуй', 'drip', 'iv'],
            'nonsurgical' => ['мэсийн бус', 'гоо сайхан', 'nonsurgical', 'botox', 'filler', 'тарилга'],
            'surgical' => ['мэс', 'засал', 'хирург', 'surgical'],
            'examination' => ['үзлэг', 'examination', 'лаборатори'],
            'massage' => ['массаж', 'massage']
        ];
        
        foreach ($dept_map as $dept => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($service, $keyword, 0, 'UTF-8') !== false) {
                    if (isset($dept_phones[$dept])) {
                        return $extractPhone($dept_phones[$dept]);
                    }
                }
            }
        }
        
        return $default_phone;
    } catch (Exception $e) {
        return $default_phone;
    }
}

// -----------------------
// Skytel WEB2SMS API Sender
// -----------------------
function sendSMS($phone, $message, $booking_id = null) {
    $status = 'logged';
    $httpCode = 0;
    $response = null;
    $errorDetail = '';
    
    // Check if SMS is enabled
    if (getenv('SMS_ENABLED') === 'false') {
        logSMS($booking_id, $phone, $message, 'disabled', 0, null, 'SMS disabled');
        return ['ok' => true, 'status' => 'disabled'];
    }
    
    // Skytel WEB2SMS API token from environment
    $skytelToken = getenv('SMS_TOKEN') ?: '4d5a863d5a97a5f56d01a4e3912caafa356f2311';
    
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

// -----------------------
// Clinic directory (fallback for contact / map data)
// -----------------------
// If DB values are missing, SMS templates and UI pull from here.
// Numbers provided by user; use single number when only one is given.
$clinicDirectory = [
    // Venera – main clinic (Gem Palace, 14F)
    'venera' => [
        'name'   => 'Venera',
        'phone1' => '70115090',   // primary
        'phone2' => '99303071',   // secondary
        'map'    => '',
        'address'=> 'БГД, 4-р хороо, Баруун 4-н зам, МҮЭСТО-ын зүүн талд, Gem Palace төвийн 14 давхарт'
    ],
    // Venera Dent – dentistry (single number)
    'dent' => [
        'name'   => 'Venera Dent',
        'phone1' => '80806780',   // single contact
        'phone2' => '',
        'map'    => '',
        'address'=> 'БГД, 4-р хороо, Баруун 4-н зам, МҮЭСТО-ын зүүн талд, Gem Palace төвийн 14 давхарт'
    ],
    // STELLA төв, 1 давхар (25-р эмийн сангийн буудлын урд) – "Goo Khatan" салбар
    'khatan' => [
        'name'   => 'Goo Khatan',
        'phone1' => '70117150',   // primary
        'phone2' => '99303048',   // secondary
        'map'    => '',
        'address'=> '25-р эмийн сангийн буудлын урд, STELLA төв, 1 давхар'
    ],
    // Мэс энт - Gem Palace, 14 давхар
    'luxor' => [
        'name'   => 'Мэс энт',
        'phone1' => '70115090',   // primary
        'phone2' => '99303071',   // secondary
        'map'    => '',
        'address'=> 'БГД, 4-р хороо, Баруун 4-н зам, МҮЭСТО-ын зүүн талд, Gem Palace төвийн 14 давхарт'
    ]
];

function clinic_directory() {
    global $clinicDirectory;
    return $clinicDirectory;
}

function get_clinic_metadata($code = 'venera') {
    $code = trim((string)$code);
    // Temporarily alias 'luxor' to 'venera' until luxor goes live
    if ($code === 'luxor') {
        $code = 'venera';
    }
    $dir = clinic_directory();
    if (isset($dir[$code])) {
        return $dir[$code];
    }
    return $dir['venera'];
}

// -----------------------
// Template Helper
// -----------------------
if (!function_exists('render_template')) {
    function render_template($tpl, array $vars) {
        return preg_replace_callback('/\{(\w+)\}/', function($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? $vars[$key] : $m[0];
        }, (string)$tpl);
    }
}

// -----------------------
// Latin Converter
// -----------------------
if (!function_exists('to_latin')) {
    function to_latin($text) {
        static $map = [
            'А'=>'A','а'=>'a','Б'=>'B','б'=>'b','В'=>'V','в'=>'v','Г'=>'G','г'=>'g','Д'=>'D','д'=>'d','Е'=>'E','е'=>'e','Ё'=>'Yo','ё'=>'yo',
            'Ж'=>'Zh','ж'=>'zh','З'=>'Z','з'=>'z','И'=>'I','и'=>'i','Й'=>'Y','й'=>'y','К'=>'K','к'=>'k','Л'=>'L','л'=>'l','М'=>'M','м'=>'m',
            'Н'=>'N','н'=>'n','О'=>'O','о'=>'o','Ө'=>'O','ө'=>'o','П'=>'P','п'=>'p','Р'=>'R','р'=>'r','С'=>'S','с'=>'s','Т'=>'T','т'=>'t',
            'У'=>'U','у'=>'u','Ү'=>'U','ү'=>'u','Ф'=>'F','ф'=>'f','Х'=>'Kh','х'=>'kh','Ц'=>'Ts','ц'=>'ts','Ч'=>'Ch','ч'=>'ch','Ш'=>'Sh','ш'=>'sh',
            'Щ'=>'Sh','щ'=>'sh','Ъ'=>'','ъ'=>'','Ы'=>'Y','ы'=>'y','Ь'=>'','ь'=>'','Э'=>'E','э'=>'e','Ю'=>'Yu','ю'=>'yu','Я'=>'Ya','я'=>'ya'
        ];
        $out = strtr((string)$text, $map);
        return preg_replace('/[^A-Za-z0-9@#\/:.,\-\s()?!]/', '', $out);
    }
}

/**
 * Proactively schedules/updates SMS reminders and aftercare messages in the sms_schedule table.
 * This makes them visible in the "Scheduled SMS" tab and ensures timely delivery.
 */
function syncScheduledSMS($booking_id) {
    try {
        $st = db()->prepare("
            SELECT b.*, u.name as doctor_name, c.name as clinic_name 
            FROM bookings b
            LEFT JOIN users u ON u.id = b.doctor_id AND u.role='doctor'
            LEFT JOIN clinics c ON c.code = b.clinic
            WHERE b.id = ?
        ");
        $st->execute([$booking_id]);
        $booking = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return;
        
        $clinic = $booking['clinic'];
        $phone = $booking['phone'];
        $patient = $booking['patient_name'];
        $date = $booking['date'];
        $start_time = $booking['start_time'];
        $status = $booking['status'];
        
        // Remove existing pending reminders/aftercare for this booking to avoid duplicates
        db()->prepare("DELETE FROM sms_schedule WHERE booking_id = ? AND status = 'pending'")->execute([$booking_id]);
        
        // If booking is cancelled, don't schedule anything new
        if (in_array($status, ['cancelled', 'doctor_cancelled'])) {
            return;
        }

        // --- 1. REMINDER SMS (2 hours before or day before) ---
        $reminderTpl = '';
        $remIsLatin = 1;
        $remClinName = '';
        $remClinPhone = '';
        
        $tst = db()->prepare("SELECT message, is_latin, clinic_name, clinic_phone FROM sms_templates WHERE type='reminder' AND (clinic = ? OR clinic = 'global') ORDER BY (clinic = ?) DESC LIMIT 1");
        $tst->execute([$clinic, $clinic]);
        $trow = $tst->fetch(PDO::FETCH_ASSOC);
        if ($trow) {
            $reminderTpl = $trow['message'];
            $remIsLatin = (int)$trow['is_latin'];
            $remClinName = $trow['clinic_name'] ?? '';
            $remClinPhone = $trow['clinic_phone'] ?? '';
        }
        
        if (!$reminderTpl) {
            $reminderTpl = 'Sain baina uu {patient_name}! Tany zahialga {clinic_name}-d {date} {start_time}-d baina. Lawlax utas: {phone}.';
        }
        
        $finalClinName = to_latin($remClinName ?: ($booking['clinic_name'] ?? strtoupper($clinic)));
        $defaultPhone = $remClinPhone ?: '70115090';
        $deptPhone = getPhoneForDepartment($booking_id, $clinic, $defaultPhone);
        
        $vars = [
            'patient_name' => $patient,
            'date' => date('m-d', strtotime($date)),
            'start_time' => substr($start_time, 0, 5),
            'clinic_name' => $finalClinName,
            'phone' => $deptPhone,
            'doctor' => $booking['doctor_name'] ?? '',
            'treatment' => $booking['service_name'] ?? ''
        ];
        
        $remMsg = render_template($reminderTpl, $vars);
        if ($remIsLatin) $remMsg = to_latin($remMsg);
        
        // Calculate Schedule Time: 1 day before at 10:00, or if appt is today, 1.5 hours before
        $bookingTs = strtotime($date . ' ' . $start_time);
        $oneDayBeforeTs = strtotime($date . ' -1 day 10:00:00');
        
        $remScheduledAt = date('Y-m-d H:i:s', $oneDayBeforeTs);
        if ($oneDayBeforeTs < time()) {
            // If already passed, set to 1.5 hours before appointment or NOW if too close
            $remScheduledAt = date('Y-m-d H:i:s', max(time() + 60, $bookingTs - 5400)); 
        }
        
        // Insert reminder if appt is in future
        if ($bookingTs > time()) {
            $insRem = db()->prepare("INSERT INTO sms_schedule (booking_id, phone, message, scheduled_at, type, status) VALUES (?, ?, ?, ?, 'reminder', 'pending')");
            $insRem->execute([$booking_id, $phone, $remMsg, $remScheduledAt]);
        }

        // --- 2. AFTERCARE SMS (If treatment has aftercare days) ---
        $stTreat = db()->prepare("SELECT aftercare_days, aftercare_message FROM treatments WHERE name = ? AND (clinic = ? OR clinic IS NULL) LIMIT 1");
        $stTreat->execute([$booking['service_name'], $clinic]);
        $treat = $stTreat->fetch(PDO::FETCH_ASSOC);
        
        if ($treat && $treat['aftercare_days'] > 0) {
            $afterTpl = '';
            $afterIsLatin = 1;
            
            $atst = db()->prepare("SELECT message, is_latin FROM sms_templates WHERE type='aftercare' AND (clinic = ? OR clinic = 'global') ORDER BY (clinic = ?) DESC LIMIT 1");
            $atst->execute([$clinic, $clinic]);
            $arow = $atst->fetch(PDO::FETCH_ASSOC);
            if ($arow) {
                $afterTpl = $arow['message'];
                $afterIsLatin = (int)$arow['is_latin'];
            }
            
            $afterMsg = $afterTpl ? render_template($afterTpl, $vars) : ($treat['aftercare_message'] ?: '');
            if (empty($afterMsg)) {
                $afterMsg = "Sain baina uu {$patient}! {$finalClinName} emnelegees mendchilj baina. Tany emchilgeenii daraah baydlyyg asuuj baina. Lawlax: {$deptPhone}";
            }
            if ($afterIsLatin) $afterMsg = to_latin($afterMsg);
            
            $afterScheduledAt = date('Y-m-d 10:30:00', strtotime($date . ' +' . $treat['aftercare_days'] . ' days'));
            
            $insAfter = db()->prepare("INSERT INTO sms_schedule (booking_id, phone, message, scheduled_at, type, status) VALUES (?, ?, ?, ?, 'aftercare', 'pending')");
            $insAfter->execute([$booking_id, $phone, $afterMsg, $afterScheduledAt]);
        }
        
    } catch (Exception $e) {
        error_log("syncScheduledSMS error: " . $e->getMessage());
    }
}
