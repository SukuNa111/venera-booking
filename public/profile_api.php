<?php
/**
 * profile_api.php - Universal Profile API
 * Accessible by all logged-in users (no role restriction)
 */

require_once __DIR__ . '/../config.php';

// Check if user is logged in
if (empty($_SESSION['uid'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Not logged in'], JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();

// GET: Fetch avatar
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_avatar') {
    $avatar_path = __DIR__ . '/../uploads/avatars/' . $u['id'] . '.jpg';
    header('Content-Type: application/json; charset=utf-8');
    if (file_exists($avatar_path)) {
        echo json_encode(['ok' => true, 'avatar' => '/booking/uploads/avatars/' . $u['id'] . '.jpg?t=' . filemtime($avatar_path)], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'avatar' => null], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// GET: Fetch profile info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_profile') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'name' => $u['name'] ?? '', 'phone' => $u['phone'] ?? ''], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST: Upload avatar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Зургийг сонгоно уу'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $upload_dir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_info = getimagesize($_FILES['avatar']['tmp_name']);
    if ($file_info === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Зургийн формат буруу'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $avatar_path = $upload_dir . $u['id'] . '.jpg';
    
    // Resize and compress image
    $image = imagecreatefromstring(file_get_contents($_FILES['avatar']['tmp_name']));
    if ($image === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Зургийг боловсруулахад алдаа'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    $new_size = 300;
    $new_image = imagecreatetruecolor($new_size, $new_size);
    
    // Calculate crop dimensions
    $size = min($width, $height);
    $x = intval(($width - $size) / 2);
    $y = intval(($height - $size) / 2);
    
    imagecopyresampled($new_image, $image, 0, 0, $x, $y, $new_size, $new_size, $size, $size);
    imagejpeg($new_image, $avatar_path, 85);
    imagedestroy($image);
    imagedestroy($new_image);
    
    echo json_encode(['ok' => true, 'msg' => 'Зураг хадгалагдлаа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST: Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        if (isset($_POST['name'])) {
            $name = trim($_POST['name']);
            if (!$name) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Нэр оруулна уу'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $st = db()->prepare("UPDATE users SET name = ? WHERE id = ?");
            $st->execute([$name, $u['id']]);
            // If this user is a doctor, also sync the doctors table name by id
            if (($u['role'] ?? '') === 'doctor') {
                try {
                    $st2 = db()->prepare("UPDATE doctors SET name = ? WHERE id = ?");
                    $st2->execute([$name, $u['id']]);
                } catch (Exception $e) {
                    // ignore doctor table update errors
                }
            }
            echo json_encode(['ok' => true, 'msg' => 'Нэр шинэчлэгдлээ'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (isset($_POST['phone'])) {
            $phone = trim($_POST['phone']);
            if (!$phone) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Утасны дугаар оруулна уу'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Check if phone is unique (excluding current user)
            $st = db()->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $st->execute([$phone, $u['id']]);
            if ($st->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Энэ утасны дугаар уже ашигласан'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $st = db()->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $st->execute([$phone, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'Утасны дугаар шинэчлэгдлээ'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (isset($_POST['password']) && $_POST['password']) {
            $password = $_POST['password'];
            if (strlen($password) < 4) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'msg' => 'Нууц үг 4 тэмдэгттэй байх ёстой'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $st = db()->prepare("UPDATE users SET pin_hash = ? WHERE id = ?");
            $st->execute([$hashed, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'Нууц үг шинэчлэгдлээ'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Хоосон хүсэлт'], JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Алдаа: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Invalid request
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'msg' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
exit;
