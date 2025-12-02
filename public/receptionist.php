<?php
require_once __DIR__ . '/../config.php';

// Profile API handlers - accessible to all logged-in users
require_login();

// Тасгийн нэрийг Монгол руу хөрвүүлэх
function getDepartmentName($dept) {
    $names = [
        'general_surgery' => 'Мэс / Ерөнхий',
        'face_surgery' => 'Мэс / Нүүр',
        'nose_surgery' => 'Мэс / Хамар',
        'oral_surgery' => 'Мэс / Амны',
        'hair_clinic' => 'Үс',
        'non_surgical' => 'Мэсийн бус',
        'nonsurgical' => 'Мэсийн бус'
    ];
    return $names[$dept] ?? $dept;
}

// Handle avatar GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_avatar') {
    $u = current_user();
    $avatar_path = __DIR__ . '/../uploads/avatars/' . $u['id'] . '.jpg';
    if (file_exists($avatar_path)) {
        echo json_encode(['ok' => true, 'avatar' => '/booking/uploads/avatars/' . $u['id'] . '.jpg?t=' . filemtime($avatar_path)]);
    } else {
        echo json_encode(['ok' => false, 'avatar' => null]);
    }
    exit;
}

// Handle get profile request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_profile') {
    $u = current_user();
    echo json_encode(['ok' => true, 'name' => $u['name'] ?? '', 'phone' => $u['phone'] ?? '']);
    exit;
}

// Handle avatar POST upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    $u = current_user();
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Зургийг сонгоно уу']);
        exit;
    }
    
    $upload_dir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_info = getimagesize($_FILES['avatar']['tmp_name']);
    if ($file_info === false) {
        echo json_encode(['ok' => false, 'msg' => 'Зургийн формат буруу']);
        exit;
    }
    
    $avatar_path = $upload_dir . $u['id'] . '.jpg';
    $image = imagecreatefromstring(file_get_contents($_FILES['avatar']['tmp_name']));
    if ($image === false) {
        echo json_encode(['ok' => false, 'msg' => 'Зургийг боловсруулахад алдаа']);
        exit;
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    $new_size = 300;
    $new_image = imagecreatetruecolor($new_size, $new_size);
    $size = min($width, $height);
    $x = intval(($width - $size) / 2);
    $y = intval(($height - $size) / 2);
    
    imagecopyresampled($new_image, $image, 0, 0, $x, $y, $new_size, $new_size, $size, $size);
    imagejpeg($new_image, $avatar_path, 85);
    imagedestroy($image);
    imagedestroy($new_image);
    
    echo json_encode(['ok' => true, 'msg' => 'Зураг хадгалагдлаа']);
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $u = current_user();
    try {
        if (isset($_POST['name']) && $_POST['name']) {
            $name = trim($_POST['name']);
            if (!$name) {
                echo json_encode(['ok' => false, 'msg' => 'Нэр оруулна уу']);
                exit;
            }
            $st = db()->prepare("UPDATE users SET name = ? WHERE id = ?");
            $st->execute([$name, $u['id']]);
            // Sync doctors table name for doctor users so calendar shows updated name
            if (($u['role'] ?? '') === 'doctor') {
              try {
                $st2 = db()->prepare("UPDATE doctors SET name = ? WHERE id = ?");
                $st2->execute([$name, $u['id']]);
              } catch (Exception $e) {
                // ignore
              }
            }
            echo json_encode(['ok' => true, 'msg' => 'Нэр шинэчлэгдлээ']);
            exit;
        }
        
        if (isset($_POST['phone']) && $_POST['phone']) {
            $phone = trim($_POST['phone']);
            if (!$phone) {
                echo json_encode(['ok' => false, 'msg' => 'Утасны дугаар оруулна уу']);
                exit;
            }
            $st = db()->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $st->execute([$phone, $u['id']]);
            if ($st->rowCount() > 0) {
                echo json_encode(['ok' => false, 'msg' => 'Энэ утасны дугаар аль хэдийн ашигласан']);
                exit;
            }
            $st = db()->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $st->execute([$phone, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'Утасны дугаар шинэчлэгдлээ']);
            exit;
        }
        
        if (isset($_POST['password']) && $_POST['password']) {
            $password = $_POST['password'];
            if (strlen($password) < 4) {
                echo json_encode(['ok' => false, 'msg' => 'Нууц үг 4 тэмдэгттэй байх ёстой']);
                exit;
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $st = db()->prepare("UPDATE users SET pin_hash = ? WHERE id = ?");
            $st->execute([$hashed, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'Нууц үг шинэчлэгдлээ']);
            exit;
        }
        
        echo json_encode(['ok' => false, 'msg' => 'Хоосон хүсэлт']);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Алдаа: ' . $e->getMessage()]);
        exit;
    }
}

// Now require reception/admin role for receptionist functions
require_role(['reception','admin']);
$u = current_user();
$clinic_id = $u['clinic_id'] ?? 'venera';

// Fetch all doctors for this clinic
$allDoctors = [];
try {
    $st = db()->prepare("SELECT id, name, active, specialty, department FROM doctors WHERE clinic = ? ORDER BY name");
    $st->execute([$clinic_id]);
    $allDoctors = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $allDoctors = [];
}



// Handle JSON POST requests for add_doctor, toggle_doctor, edit_doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    if ($action === 'toggle_doctor') {
        $doctor_id = (int)($data['doctor_id'] ?? 0);
        $active = (int)($data['active'] ?? 1);
        
        if ($doctor_id > 0) {
            try {
                $st = db()->prepare("UPDATE doctors SET active = ? WHERE id = ?");
                $st->execute([$active, $doctor_id]);
                echo json_encode(['ok' => true, 'msg' => 'Эмчийн статус өөрчлөгдлөө']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'Алдаа: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Эмч олдсонгүй']);
        }
        exit;
    }
    
    if ($action === 'edit_doctor') {
        $doctor_id = (int)($data['doctor_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $specialty = trim($data['specialty'] ?? '');
        $department = trim($data['department'] ?? 'nonsurgical');
        
        if ($doctor_id > 0 && $name) {
            try {
                $st = db()->prepare("UPDATE doctors SET name = ?, specialty = ?, department = ? WHERE id = ? AND clinic = ?");
                $st->execute([$name, $specialty, $department, $doctor_id, $clinic_id]);
                echo json_encode(['ok' => true, 'msg' => 'Эмчийн мэдээлэл шинэчлэгдлөө']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'Алдаа: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Эмчийн ID болон нэр шаардлагатай']);
        }
        exit;
    }
    
    if ($action === 'add_doctor') {
        $name = trim($data['name'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $pin = trim($data['pin'] ?? '');
        $specialty = trim($data['specialty'] ?? '');
        $department = trim($data['department'] ?? 'nonsurgical');
        $clinic = trim($data['clinic'] ?? $clinic_id);
        $color = $data['color'] ?? '#3b82f6';
        
        if ($name && $phone && $pin) {
            try {
                // Insert new doctor
                $st = db()->prepare("
                    INSERT INTO doctors (name, clinic, specialty, department, color, active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $st->execute([$name, $clinic, $specialty, $department, $color]);
                $doctor_id = db()->lastInsertId();
                
                // Create default working hours (09:00-18:00, available) for all days
                for ($i = 0; $i < 7; $i++) {
                    $stWh = db()->prepare("INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, 1)");
                    $stWh->execute([$doctor_id, $i, '09:00', '18:00']);
                }
                
                // Create user record for this doctor with provided phone and PIN
                $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
                try {
                    $stUser = db()->prepare("INSERT INTO users (id, name, phone, pin_hash, role, clinic_id) VALUES (?, ?, ?, ?, 'doctor', ?)");
                    $stUser->execute([$doctor_id, $name, $phone, $pin_hash, $clinic]);
                    error_log("✅ User created: $doctor_id, $name, $phone");
                } catch (Exception $e) {
                    error_log("❌ User creation failed: " . $e->getMessage());
                    // If duplicate phone error
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'phone') !== false) {
                        echo json_encode(['ok' => false, 'msg' => 'Утасны дугаар давхцаж байна. Өөр дугаар оруулна уу.']);
                        exit;
                    } else {
                        echo json_encode(['ok' => false, 'msg' => 'User бүртгэхэд алдаа: ' . $e->getMessage()]);
                        exit;
                    }
                }
                
                echo json_encode(['ok' => true, 'msg' => "Эмч '{$name}' нэмэгдлээ", 'id' => $doctor_id]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => 'Алдаа: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Эмчийн нэр, утас, PIN оруулна уу']);
        }
        exit;
    }
}
?><!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>👨‍⚕️ Эмч нарын удирдлага — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #6366f1;
      --primary-light: #818cf8;
      --secondary: #8b5cf6;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1e293b;
      --light: #f8fafc;
      --border: #e2e8f0;
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
    }

    body {
      background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
      background-attachment: fixed;
      font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: #1e293b;
      overflow-x: hidden;
    }

    main {
      margin-left: 250px;
      padding: 2.5rem 3rem;
      min-height: 100vh;
    }

    /* Page Header */
    .page-header {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      border-radius: 20px;
      padding: 2rem 2.5rem;
      margin-bottom: 2rem;
      color: white;
      box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
    }

    .page-header h1 {
      font-size: 2rem;
      font-weight: 800;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .page-header p {
      opacity: 0.9;
      font-size: 1rem;
      margin: 0;
    }

    /* Stats Row */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: white;
      border-radius: 16px;
      padding: 1.5rem;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid #e2e8f0;
      transition: all 0.3s ease;
    }

    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    .stat-card .icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1rem;
      font-size: 1.5rem;
    }

    .stat-card .number {
      font-size: 2.25rem;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 0.25rem;
    }

    .stat-card .label {
      font-size: 0.9rem;
      color: #64748b;
      font-weight: 500;
    }

    /* Toolbar */
    .toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .toolbar-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .toolbar h2 {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .toolbar h2 i {
      color: var(--primary);
    }

    .doctors-count {
      background: #ede9fe;
      border: 1px solid #c4b5fd;
      padding: 0.5rem 1rem;
      border-radius: 50px;
      font-size: 0.85rem;
      color: #7c3aed;
      font-weight: 600;
    }

    .btn-add {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      border: none;
      padding: 0.875rem 1.75rem;
      border-radius: 12px;
      font-weight: 600;
      font-size: 0.95rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    }

    .btn-add:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
      color: white;
    }

    /* Doctor Table */
    .doctors-table-container {
      background: white;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid #e2e8f0;
    }

    .doctors-table {
      width: 100%;
      border-collapse: collapse;
    }

    .doctors-table thead th {
      background: #f8fafc;
      color: #475569;
      font-weight: 600;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 1rem 1.5rem;
      text-align: left;
      border-bottom: 2px solid #e2e8f0;
    }

    .doctors-table tbody tr {
      transition: all 0.2s ease;
      border-bottom: 1px solid #f1f5f9;
    }

    .doctors-table tbody tr:last-child {
      border-bottom: none;
    }

    .doctors-table tbody tr:hover {
      background: #f8fafc;
    }

    .doctors-table tbody td {
      padding: 1.25rem 1.5rem;
      vertical-align: middle;
    }

    .doctor-info {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .doctor-avatar {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 700;
      font-size: 1.1rem;
      flex-shrink: 0;
    }

    .doctor-details h4 {
      font-size: 1rem;
      font-weight: 600;
      color: #1e293b;
      margin: 0 0 0.25rem 0;
    }

    .doctor-details p {
      font-size: 0.85rem;
      color: #64748b;
      margin: 0;
    }

    .specialty-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.4rem 0.9rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      background: #ecfeff;
      color: #0891b2;
      border: 1px solid #a5f3fc;
    }

    .department-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.4rem 0.9rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      background: #f3e8ff;
      color: #9333ea;
      border: 1px solid #d8b4fe;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 1rem;
      border-radius: 50px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .status-active {
      background: #dcfce7;
      color: #16a34a;
      border: 1px solid #86efac;
    }

    .status-inactive {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fca5a5;
    }

    .action-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .btn-action {
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .btn-edit {
      background: #ede9fe;
      color: #7c3aed;
      border: 1px solid #c4b5fd;
    }

    .btn-edit:hover {
      background: #ddd6fe;
      transform: translateY(-1px);
    }

    .btn-toggle-active {
      background: #dcfce7;
      color: #16a34a;
      border: 1px solid #86efac;
    }

    .btn-toggle-inactive {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fca5a5;
    }

    .btn-toggle-active:hover,
    .btn-toggle-inactive:hover {
      transform: translateY(-1px);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      background: white;
      border: 2px dashed #e2e8f0;
      border-radius: 16px;
      color: #64748b;
    }

    .empty-state i {
      font-size: 4rem;
      margin-bottom: 1.5rem;
      color: #c4b5fd;
    }

    .empty-state h3 {
      font-size: 1.25rem;
      font-weight: 600;
      color: #475569;
      margin-bottom: 0.5rem;
    }

    /* Modal Styling */
    .modal-content {
      background: white;
      border: none;
      border-radius: 20px;
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.2);
    }

    .modal-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      border: none;
      padding: 1.5rem 2rem;
      border-radius: 20px 20px 0 0;
    }

    .modal-title {
      font-weight: 700;
      font-size: 1.25rem;
    }

    .btn-close {
      filter: brightness(0) invert(1);
    }

    .modal-body {
      padding: 2rem;
    }

    .form-label {
      color: #374151;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .form-control,
    .form-select {
      background: #f9fafb;
      border: 2px solid #e5e7eb;
      color: #1f2937;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      transition: all 0.2s ease;
    }

    .form-control:focus,
    .form-select:focus {
      background: white;
      border-color: var(--primary);
      color: #1f2937;
      box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    .form-control::placeholder {
      color: #9ca3af;
    }

    .modal-footer {
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      padding: 1.25rem 2rem;
      border-radius: 0 0 20px 20px;
    }

    .btn-secondary {
      background: white;
      border: 2px solid #e5e7eb;
      color: #4b5563;
      border-radius: 10px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
    }

    .btn-secondary:hover {
      background: #f3f4f6;
      border-color: #d1d5db;
      color: #374151;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      border: none;
      color: white;
      border-radius: 10px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    }

    .color-option {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-top: 0.5rem;
    }

    .color-option label {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 10px;
      background: #f9fafb;
      border: 2px solid transparent;
      transition: all 0.2s ease;
    }

    .color-option label:hover {
      border-color: #e5e7eb;
      background: white;
    }

    .color-option input[type="radio"] {
      display: none;
    }

    .color-option input[type="radio"]:checked + .color-swatch {
      transform: scale(1.15);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
    }

    .color-option label:has(input[type="radio"]:checked) {
      border-color: var(--primary);
      background: #ede9fe;
    }

    .color-swatch {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 3px solid white;
      box-shadow: 0 2px 6px rgba(0,0,0,0.15);
      transition: transform 0.2s ease;
    }

    /* Responsive */
    @media (max-width: 1200px) {
      .stats-row {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 992px) {
      main {
        margin-left: 0;
        padding: 1.5rem;
      }
    }

    @media (max-width: 768px) {
      .stats-row {
        grid-template-columns: 1fr;
      }
      
      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }
    }

    /* Animations */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .page-header, .stat-card, .doctors-table-container {
      animation: fadeInUp 0.5s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stat-card:nth-child(2) { animation-delay: 0.2s; }
    .stat-card:nth-child(3) { animation-delay: 0.3s; }
    .stat-card:nth-child(4) { animation-delay: 0.4s; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<?php
// Calculate stats
$activeCount = 0;
$inactiveCount = 0;
foreach ($allDoctors as $doc) {
  if ($doc['active']) $activeCount++;
  else $inactiveCount++;
}
?>

<main>
  <!-- Page Header -->
  <div class="page-header">
    <h1>
      <i class="fas fa-user-md"></i>
      Эмч нарын жагсаалт
    </h1>
    <p>Reception — Эмч нарын бүртгэл ба удирдлага</p>
  </div>

  <!-- Stats Row -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="icon" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.15));">
        <i class="fas fa-users text-primary"></i>
      </div>
      <div class="number" style="color: #a5b4fc;"><?= count($allDoctors) ?></div>
      <div class="label">Нийт эмч</div>
    </div>
    <div class="stat-card">
      <div class="icon" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.15));">
        <i class="fas fa-check-circle text-success"></i>
      </div>
      <div class="number" style="color: #6ee7b7;"><?= $activeCount ?></div>
      <div class="label">Идэвхтэй</div>
    </div>
    <div class="stat-card">
      <div class="icon" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.15));">
        <i class="fas fa-pause-circle text-danger"></i>
      </div>
      <div class="number" style="color: #fca5a5;"><?= $inactiveCount ?></div>
      <div class="label">Идэвхгүй</div>
    </div>
    <div class="stat-card">
      <div class="icon" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.15));">
        <i class="fas fa-calendar-check text-warning"></i>
      </div>
      <div class="number" style="color: #fcd34d;">—</div>
      <div class="label">Өнөөдөр ажиллах</div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <div class="toolbar-left">
      <h2><i class="fas fa-stethoscope"></i> Эмч нарын жагсаалт</h2>
      <span class="doctors-count">
        <i class="fas fa-user-md me-1"></i><?= count($allDoctors) ?> эмч
      </span>
    </div>
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addDoctorModal">
      <i class="fas fa-plus-circle"></i>
      Шинэ эмч нэмэх
    </button>
  </div>

  <?php if (!empty($allDoctors)): ?>
    <div class="doctors-table-container">
      <table class="doctors-table">
        <thead>
          <tr>
            <th>Эмч</th>
            <th>Мэргэжил</th>
            <th>Тасаг</th>
            <th>Статус</th>
            <th style="text-align: right;">Үйлдэл</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allDoctors as $doc): ?>
            <tr>
              <td>
                <div class="doctor-info">
                  <div class="doctor-avatar">
                    <?= mb_strtoupper(mb_substr($doc['name'], 0, 1)) ?>
                  </div>
                  <div class="doctor-details">
                    <h4><?= htmlspecialchars($doc['name']) ?></h4>
                    <p>ID: <?= $doc['id'] ?></p>
                  </div>
                </div>
              </td>
              <td>
                <span class="specialty-badge">
                  <i class="fas fa-graduation-cap"></i>
                  <?= htmlspecialchars($doc['specialty'] ?? 'Ерөнхий эмч') ?>
                </span>
              </td>
              <td>
                <span class="department-badge">
                  <i class="fas fa-building"></i>
                  <?= htmlspecialchars(getDepartmentName($doc['department'] ?? 'non_surgical')) ?>
                </span>
              </td>
              <td>
                <span class="status-badge <?= $doc['active'] ? 'status-active' : 'status-inactive' ?>">
                  <i class="fas <?= $doc['active'] ? 'fa-check-circle' : 'fa-pause-circle' ?>"></i>
                  <?= $doc['active'] ? 'Идэвхтэй' : 'Идэвхгүй' ?>
                </span>
              </td>
              <td>
                <div class="action-buttons" style="justify-content: flex-end;">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($doc)) ?>)">
                    <i class="fas fa-pen"></i>
                    Засах
                  </button>
                  <button class="btn-action <?= $doc['active'] ? 'btn-toggle-inactive' : 'btn-toggle-active' ?>" onclick="toggleDoctorStatus(<?= $doc['id'] ?>, <?= $doc['active'] ? 0 : 1 ?>)">
                    <i class="fas <?= $doc['active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                    <?= $doc['active'] ? 'Түр зогсоох' : 'Идэвхжүүлэх' ?>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-user-md"></i>
      <h3>Одоогоор эмч бүртгэгдээгүй байна</h3>
      <p style="color: #64748b;">Шинэ эмч нэмж эхлэхийн тулд дээрх товчийг дарна уу</p>
    </div>
  <?php endif; ?>
</main>

<!-- Edit Doctor Modal -->
<div class="modal fade" id="editDoctorModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Эмчийн мэдээлэл засах</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="editDoctorForm">
          <input type="hidden" name="doctor_id" id="editDoctorId">
          <div class="mb-3">
            <label class="form-label">Эмчийн нэр <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" id="editDoctorName" placeholder="Бүтэн нэр" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Мэргэжил</label>
            <input type="text" name="specialty" class="form-control" id="editDoctorSpecialty" placeholder="Жишээ: Шүдний эмч">
          </div>
          <div class="mb-3">
            <label class="form-label">Тасаг</label>
            <select name="department" class="form-select" id="editDoctorDept">
              <option value="general_surgery">Мэс / ерөнхий</option>
              <option value="face_surgery">Мэс / нүүр</option>
              <option value="nose_surgery">Мэс / хамар</option>
              <option value="oral_surgery">Мэс / амны</option>
              <option value="hair_clinic">Үс</option>
              <option value="non_surgical">Мэсийн бус</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>Цуцлах
        </button>
        <button type="button" class="btn btn-primary" onclick="saveEditedDoctor()">
          <i class="fas fa-save me-1"></i>Хадгалах
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Add Doctor Modal -->
<div class="modal fade" id="addDoctorModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Шинэ эмч нэмэх</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="addDoctorForm">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Эмчийн нэр <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" placeholder="Бүтэн нэр" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Мэргэжил</label>
              <input type="text" name="specialty" class="form-control" placeholder="Жишээ: Шүдний эмч">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Утас <span class="text-danger">*</span></label>
              <input type="tel" name="phone" class="form-control" placeholder="8XXXXXXXX" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">PIN код <span class="text-danger">*</span></label>
              <input type="password" name="pin" class="form-control" placeholder="••••" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Тасаг</label>
            <select name="department" class="form-select">
              <option value="general_surgery">Мэс / ерөнхий</option>
              <option value="face_surgery">Мэс / нүүр</option>
              <option value="nose_surgery">Мэс / хамар</option>
              <option value="oral_surgery">Мэс / амны</option>
              <option value="hair_clinic">Үс</option>
              <option value="non_surgical" selected>Мэсийн бус</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Өнгө сонгох</label>
            <div class="color-option">
              <label><input type="radio" name="color" value="#6366f1" checked><span class="color-swatch" style="background: #6366f1;"></span></label>
              <label><input type="radio" name="color" value="#f59e0b"><span class="color-swatch" style="background: #f59e0b;"></span></label>
              <label><input type="radio" name="color" value="#10b981"><span class="color-swatch" style="background: #10b981;"></span></label>
              <label><input type="radio" name="color" value="#8b5cf6"><span class="color-swatch" style="background: #8b5cf6;"></span></label>
              <label><input type="radio" name="color" value="#ec4899"><span class="color-swatch" style="background: #ec4899;"></span></label>
              <label><input type="radio" name="color" value="#ef4444"><span class="color-swatch" style="background: #ef4444;"></span></label>
              <label><input type="radio" name="color" value="#06b6d4"><span class="color-swatch" style="background: #06b6d4;"></span></label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>Цуцлах
        </button>
        <button type="button" class="btn btn-primary" onclick="addNewDoctor()">
          <i class="fas fa-plus me-1"></i>Нэмэх
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let editModal;

document.addEventListener('DOMContentLoaded', function() {
    editModal = new bootstrap.Modal(document.getElementById('editDoctorModal'));
});

// Toast notification
function showToast(message, type = 'info') {
  const container = document.getElementById('toastContainer') || createToastContainer();
  const toast = document.createElement('div');
  toast.className = `toast-notification toast-${type}`;
  toast.innerHTML = `
    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
    <span>${message}</span>
  `;
  container.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

function createToastContainer() {
  const container = document.createElement('div');
  container.id = 'toastContainer';
  container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;';
  document.body.appendChild(container);
  
  const style = document.createElement('style');
  style.textContent = `
    .toast-notification {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 20px;
      border-radius: 12px;
      color: white;
      font-weight: 500;
      box-shadow: 0 8px 30px rgba(0,0,0,0.3);
      transform: translateX(120%);
      transition: transform 0.3s ease;
    }
    .toast-notification.show { transform: translateX(0); }
    .toast-success { background: linear-gradient(135deg, #10b981, #059669); }
    .toast-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .toast-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .toast-info { background: linear-gradient(135deg, #6366f1, #4f46e5); }
  `;
  document.head.appendChild(style);
  return container;
}

function openEditModal(doctor) {
    document.getElementById('editDoctorId').value = doctor.id;
    document.getElementById('editDoctorName').value = doctor.name;
    document.getElementById('editDoctorSpecialty').value = doctor.specialty || '';
    document.getElementById('editDoctorDept').value = doctor.department || 'non_surgical';
    
    if (!editModal) {
        editModal = new bootstrap.Modal(document.getElementById('editDoctorModal'));
    }
    editModal.show();
}

function saveEditedDoctor() {
    const form = document.getElementById('editDoctorForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.action = 'edit_doctor';
    
    const btn = form.closest('.modal-content').querySelector('.btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Хадгалж байна...';
    
    fetch('./receptionist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            showToast(res.msg, 'success');
            editModal.hide();
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Алдаа: ' + res.msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-1"></i>Хадгалах';
        }
    })
    .catch(e => {
        showToast('Хүсэлт алдаа: ' + e.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Хадгалах';
    });
}

function toggleDoctorStatus(doctorId, newStatus) {
    fetch('./receptionist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'toggle_doctor',
            doctor_id: doctorId,
            active: newStatus
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            showToast(res.msg, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Алдаа: ' + res.msg, 'danger');
        }
    })
    .catch(e => showToast('Хүсэлт алдаа: ' + e.message, 'danger'));
}

function addNewDoctor() {
    const form = document.getElementById('addDoctorForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.action = 'add_doctor';
    
    const btn = form.closest('.modal-content').querySelector('.btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Нэмж байна...';
    
    fetch('./receptionist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            showToast(res.msg, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Алдаа: ' + res.msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus me-1"></i>Нэмэх';
        }
    })
    .catch(e => {
        showToast('Хүсэлт алдаа: ' + e.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Нэмэх';
    });
}
</script>
</body>
</html>

