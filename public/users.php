<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$currentUser = current_user();
$role = $currentUser['role'] ?? '';
$isSuper = is_super_admin();
$isAdmin = $isSuper; // backward compatibility with UI logic
$userClinic = $currentUser['clinic_id'] ?? null;

// --- GET хэрэглэгчид ---
try {
    if ($isAdmin) {
        $st = db()->query("SELECT * FROM users ORDER BY name");
    } else {
        $st = db()->prepare("SELECT * FROM users WHERE clinic_id = ? ORDER BY name");
        $st->execute([$userClinic]);
    }
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
}

// --- Load clinics list ---
$clinics = [];
try {
    $st_cl = db()->query("SELECT code, name FROM clinics WHERE active=1 ORDER BY COALESCE(sort_order,0), id");
    $clinics = $st_cl->fetchAll(PDO::FETCH_ASSOC);
    if (empty($clinics)) {
        $clinics = [
            ['code' => 'venera', 'name' => 'Венера'],
            ['code' => 'luxor', 'name' => 'Голден Луксор'],
            ['code' => 'khatan', 'name' => 'Гоо Хатан']
        ];
    }
    if ($isSuper && !array_filter($clinics, fn($c) => ($c['code'] ?? '') === 'all')) {
        array_unshift($clinics, ['code' => 'all', 'name' => 'Бүх эмнэлэг']);
    }
} catch (Exception $e) {
    $clinics = [
        ['code' => 'venera', 'name' => 'Венера'],
        ['code' => 'luxor', 'name' => 'Голден Луксор'],
        ['code' => 'khatan', 'name' => 'Гоо Хатан']
    ];
    if ($isSuper) {
        array_unshift($clinics, ['code' => 'all', 'name' => 'Бүх эмнэлэг']);
    }
}

// --- Load specialties list ---
$specialties = [];
try {
    $st_sp = db()->query("SELECT DISTINCT COALESCE(NULLIF(specialty,''),'Ерөнхий эмч') as specialty FROM users WHERE role='doctor' ORDER BY specialty");
    $specialties = $st_sp->fetchAll(PDO::FETCH_COLUMN);
    if (empty($specialties)) $specialties = ['Ерөнхий эмч'];
} catch (Exception $e) {
    $specialties = ['Ерөнхий эмч'];
}

// --- POST үйлдэл ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

if ($action === 'add') {
    $name      = trim($_POST['name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role      = trim($_POST['role'] ?? 'reception');
    $pin       = trim($_POST['pin'] ?? '');
    $clinic_id = trim($_POST['clinic_id'] ?? 'venera');
    $department = trim($_POST['department'] ?? '');
    $specialty = trim($_POST['specialty'] ?? 'Ерөнхий эмч');
    $color     = trim($_POST['color'] ?? '#3b82f6');

    // Reception users cannot add users via this page
    if (!$isAdmin) {
        echo json_encode(['ok' => false, 'msg' => 'Зөвхөн админ хэрэглэгч нэмэх боломжтой']);
        exit;
    }

    if ($role === 'super_admin' && !$isSuper) {
        echo json_encode(['ok' => false, 'msg' => 'Супер админ эрхийг зөвхөн супер админ үүсгэнэ'] );
        exit;
    }

    if ($role === 'doctor' && $clinic_id === 'all' && !$isSuper) {
        echo json_encode(['ok' => false, 'msg' => 'Эмчид "Бүх эмнэлэг" сонгох боломжгүй']);
        exit;
    }

    if ($name && $phone && $pin) {
        try {
            $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
            
            if ($role === 'doctor') {
                $st_usr = db()->prepare("INSERT INTO users (name, phone, pin_hash, role, clinic_id, department, specialty, color, show_in_calendar, active, created_at) VALUES (?, ?, ?, 'doctor', ?, ?, ?, ?, 1, 1, NOW())");
                $st_usr->execute([$name, $phone, $pin_hash, $clinic_id, $department, $specialty, $color]);
                $doctor_id = db()->lastInsertId();
                echo json_encode(['ok' => true, 'msg' => 'Эмч амжилттай нэмэгдлээ', 'id' => $doctor_id]);
            } else {
                $st = db()->prepare("INSERT INTO users (name, phone, pin_hash, role, clinic_id, department, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $st->execute([$name, $phone, $pin_hash, $role, $clinic_id, $department]);
                echo json_encode(['ok' => true, 'msg' => 'Хэрэглэгч амжилттай нэмэгдлээ']);
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Бүх талбарыг оруулна уу']);
    }
    exit;
}

if ($action === 'update') {
    $id        = $_POST['id'] ?? 0;
    $name      = trim($_POST['name'] ?? '');
    $role      = trim($_POST['role'] ?? 'reception');
    $phone     = trim($_POST['phone'] ?? '');
    $pin       = trim($_POST['pin'] ?? '');
    $clinic_id = trim($_POST['clinic_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $specialty = trim($_POST['specialty'] ?? 'Ерөнхий эмч');
    $color     = trim($_POST['color'] ?? '#3b82f6');

    // Reception cannot edit users
    if (!$isAdmin) {
        echo json_encode(['ok' => false, 'msg' => 'Зөвхөн админ хэрэглэгч засах боломжтой']);
        exit;
    }

    if ($role === 'super_admin' && !$isSuper) {
        echo json_encode(['ok' => false, 'msg' => 'Супер админ эрхийг зөвхөн супер админ олгоно']);
        exit;
    }

    // Check current role
    $stCur = db()->prepare("SELECT role FROM users WHERE id=?");
    $stCur->execute([$id]);
    $currentRole = $stCur->fetchColumn() ?: '';
    
    // Cannot change role from doctor to non-doctor or vice versa
    if ($currentRole !== $role && ($currentRole === 'doctor' || $role === 'doctor')) {
        echo json_encode(['ok' => false, 'msg' => 'Хэрэглэгчийн үүргийг эмч рүү/эсэх өөрчлөх болохгүй']);
        exit;
    }

    if ($role === 'doctor' && $clinic_id === 'all' && !$isSuper) {
        echo json_encode(['ok' => false, 'msg' => 'Эмчид "Бүх эмнэлэг" сонгох боломжгүй']);
        exit;
    }

    if ($id && $name && $phone) {
        try {
            // Build update query
            if ($pin) {
                $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
                $st = db()->prepare("UPDATE users SET name=?, phone=?, pin_hash=?, role=?, clinic_id=?, department=?, specialty=?, color=? WHERE id=?");
                $st->execute([$name, $phone, $pin_hash, $role, $clinic_id, $department, $specialty, $color, $id]);
            } else {
                $st = db()->prepare("UPDATE users SET name=?, phone=?, role=?, clinic_id=?, department=?, specialty=?, color=? WHERE id=?");
                $st->execute([$name, $phone, $role, $clinic_id, $department, $specialty, $color, $id]);
            }
            
            echo json_encode(['ok' => true, 'msg' => 'Хэрэглэгч амжилттай засагдлаа']);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Нэр, утас заавал оруулна уу']);
    }
    exit;
}

if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;

        if ($id) {
            try {
                // Check if doctor has any bookings
                $stCheck = db()->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE doctor_id = ?");
                $stCheck->execute([$id]);
                $bookingCount = $stCheck->fetch()['cnt'] ?? 0;
                
                if ($bookingCount > 0) {
                    echo json_encode(['ok' => false, 'msg' => "Энэ хэрэглэгчтэй холбоотой $bookingCount захиалга байна. Эхлээд захиалгуудыг устгана уу."]);
                    exit;
                }
                
                // Delete user record
                $stDelUser = db()->prepare("DELETE FROM users WHERE id=?");
                $stDelUser->execute([$id]);
                
                echo json_encode(['ok' => true, 'msg' => 'Хэрэглэгч амжилттай устгагдлаа']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'ID алга']);
        }
        exit;
    }
}

// Get clinics
$clinics = [];
try {
    $st = db()->query("SELECT code, name FROM clinics WHERE active=1 ORDER BY name");
    $clinics = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clinics = [];
}

// Fallback clinics if DB is empty
if (empty($clinics)) {
    $clinics = [
        ['code' => 'venera', 'name' => 'Венера'],
        ['code' => 'luxor', 'name' => 'Голден Луксор'],
        ['code' => 'khatan', 'name' => 'Гоо Хатан']
    ];
}

if ($isSuper && !array_filter($clinics, fn($c) => ($c['code'] ?? '') === 'all')) {
    array_unshift($clinics, ['code' => 'all', 'name' => 'Бүх эмнэлэг']);
}

// --- Specialist list for doctors ---
$specialties = [];
try {
    $sp = db()->query("SELECT DISTINCT COALESCE(NULLIF(specialty,''),'Ерөнхий эмч') AS specialty FROM users WHERE role='doctor' ORDER BY specialty");
    $specialties = $sp->fetchAll(PDO::FETCH_COLUMN);
    if (empty($specialties)) $specialties = ['Ерөнхий эмч'];
} catch (Exception $e) {
    $specialties = ['Ерөнхий эмч'];
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>👥 Хэрэглэгчид — Захиалгын систем</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }
        
        main {
            margin-left: 250px;
            padding: 2rem;
            min-height: 100vh;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .page-header p {
            opacity: 0.9;
            margin: 0;
            font-size: 0.95rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .stat-card.purple .stat-icon { background: linear-gradient(135deg, #ede9fe, #f3e8ff); color: #7c3aed; }
        .stat-card.green .stat-icon { background: linear-gradient(135deg, #d1fae5, #ecfdf5); color: #059669; }
        .stat-card.blue .stat-icon { background: linear-gradient(135deg, #dbeafe, #eff6ff); color: #2563eb; }
        .stat-card.orange .stat-icon { background: linear-gradient(135deg, #fed7aa, #fff7ed); color: #ea580c; }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .stat-card .stat-label {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        /* Main Card */
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(16px);
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(226, 232, 240, 0.6);
            overflow: hidden;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            padding: 1.75rem 2rem;
            border-bottom: 3px solid rgba(139, 92, 246, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header-custom h5 {
            font-weight: 700;
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Search Box */
        .search-box {
            position: relative;
            width: 280px;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.625rem 1rem 0.625rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.875rem;
            transition: all 0.2s;
            background: white;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .search-box i {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        
        /* Table */
        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .modern-table thead th {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        
        .modern-table thead th:first-child { border-radius: 0; }
        .modern-table thead th:last-child { border-radius: 0; }
        
        .modern-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .modern-table tbody tr:hover {
            background: linear-gradient(135deg, rgba(250, 245, 255, 0.6), rgba(240, 244, 255, 0.6));
            transform: translateX(4px);
        }
        
        .modern-table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
        }
        
        /* User Avatar */
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.15rem;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .user-avatar.admin { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .user-avatar.reception { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .user-avatar.doctor { background: linear-gradient(135deg, #06b6d4, #22d3ee); }
        
        .user-info .user-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.125rem;
        }
        
        .user-info .user-id {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        /* Role Badges */
        .role-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        
        .role-badge.admin {
            background: linear-gradient(135deg, #ede9fe, #f3e8ff);
            color: #7c3aed;
        }
        
        .role-badge.reception {
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            color: #d97706;
        }
        
        .role-badge.doctor {
            background: linear-gradient(135deg, #cffafe, #ecfeff);
            color: #0891b2;
        }
        
        /* Clinic Badge */
        .clinic-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            background: #f1f5f9;
            color: #475569;
        }
        
        /* Action Buttons */
        .btn-action {
            padding: 0.5rem 0.875rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            color: #b45309;
        }
        
        .btn-edit:hover {
            background: linear-gradient(135deg, #fde68a, #fef3c7);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.25);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #fee2e2, #fef2f2);
            color: #dc2626;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #fecaca, #fee2e2);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        }
        
        /* Add Button */
        .btn-add {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.35);
        }
        
        .btn-add:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 32px rgba(99, 102, 241, 0.45);
        }
        
        /* Modal Styling */
        .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }
        
        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .modal-header-custom .modal-title {
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-label i {
            color: var(--primary);
            font-size: 0.875rem;
        }
        
        .form-control, .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        .modal-footer-custom {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }
        
        .btn-cancel {
            background: #f1f5f9;
            color: #64748b;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-cancel:hover {
            background: #e2e8f0;
            color: #475569;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border: none;
            padding: 0.625rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            main {
                margin-left: 0;
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .search-box {
                width: 100%;
            }
            
            .modern-table {
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <main>
        <!-- Page Header -->
        <div class="page-header">
            <h2><i class="fas fa-users"></i> <?= $isAdmin ? 'Хэрэглэгчид' : 'Эмч нар' ?></h2>
            <p><?= $isAdmin ? 'Системийн хэрэглэгчдийг удирдах' : 'Таны эмнэлгийн эмч нарыг удирдах' ?></p>
        </div>

        <!-- Stats Grid -->
        <?php
        $totalUsers = count($users);
        $adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
        $doctorCount = count(array_filter($users, fn($u) => $u['role'] === 'doctor'));
        $receptionCount = count(array_filter($users, fn($u) => $u['role'] === 'reception'));
        ?>
        <?php if ($isAdmin): ?>
        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-label">Нийт хэрэглэгч</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                <div class="stat-value"><?= $adminCount ?></div>
                <div class="stat-label">Администратор</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-user-md"></i></div>
                <div class="stat-value"><?= $doctorCount ?></div>
                <div class="stat-label">Эмч нар</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-headset"></i></div>
                <div class="stat-value"><?= $receptionCount ?></div>
                <div class="stat-label">Хүлээн авалт</div>
            </div>
        </div>
        <?php else: ?>
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-user-md"></i></div>
                <div class="stat-value"><?= $doctorCount ?></div>
                <div class="stat-label">Эмч нар</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Card -->
        <div class="glass-card">
            <div class="card-header-custom">
                <h5><i class="fas fa-list"></i> <?= $isAdmin ? 'Хэрэглэгчдийн жагсаалт' : 'Эмч нарын жагсаалт' ?></h5>
                <div class="d-flex align-items-center gap-3">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Хайх...">
                    </div>
                    <button id="addBtn" class="btn-add">
                        <i class="fas fa-plus"></i> <?= $isAdmin ? 'Хэрэглэгч нэмэх' : 'Эмч нэмэх' ?>
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Хэрэглэгч</th>
                            <th>Утас</th>
                            <th>Үүрэг</th>
                            <th>Эмнэлэг</th>
                            <th style="text-align: right;">Үйлдэл</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="user-avatar <?= htmlspecialchars($user['role']) ?>"><?= mb_substr($user['name'], 0, 1) ?></div>
                                        <div class="user-info">
                                            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                                            <div class="user-id">ID: <?= $user['id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="color: #475569; font-weight: 500;"><?= htmlspecialchars($user['phone'] ?? '—') ?></span></td>
                                <td>
                                    <span class="role-badge <?= htmlspecialchars($user['role']) ?>">
                                        <?php
                                        $roleNames = ['super_admin' => 'Супер админ', 'admin' => 'Админ', 'doctor' => 'Эмч', 'reception' => 'Хүлээн авалт'];
                                        echo $roleNames[$user['role']] ?? $user['role'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $clin = $user['clinic_id'] ?: ($user['doctor_clinic'] ?? '');
                                    if (!$clin && !empty($user['doctor_clinic'])) {
                                        $clin = $user['doctor_clinic'];
                                    }
                                    $clinicNames = ['venera' => 'Венера', 'luxor' => 'Голден Луксор', 'khatan' => 'Гоо Хатан', 'all' => 'Бүгд'];
                                    ?>
                                    <span class="clinic-badge"><?= $clinicNames[$clin] ?? htmlspecialchars($clin ?: '—') ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <button class="btn-action btn-edit edit-btn" data-id="<?= $user['id'] ?>"
                                            data-name="<?= htmlspecialchars($user['name']) ?>"
                                            data-role="<?= htmlspecialchars($user['role']) ?>"
                                            data-phone="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                            data-clinic="<?= htmlspecialchars($user['clinic_id'] ?: ($user['doctor_clinic'] ?? '')) ?>"
                                            data-specialty="<?= htmlspecialchars($user['doctor_specialty'] ?? '') ?>"
                                            data-department="<?= htmlspecialchars($user['doctor_department'] ?? '') ?>"
                                            data-color="<?= htmlspecialchars($user['doctor_color'] ?? '#3b82f6') ?>">
                                        <i class="fas fa-edit"></i> Засах
                                    </button>
                                    <button class="btn-action btn-delete delete-btn" data-id="<?= $user['id'] ?>" data-name="<?= htmlspecialchars($user['name']) ?>">
                                        <i class="fas fa-trash"></i> Хасах
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p>Хэрэглэгч байхгүй байна</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal — Нэмэх -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="addForm">
                <div class="modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> <?= $isAdmin ? 'Хэрэглэгч нэмэх' : 'Эмч нэмэх' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user"></i> Нэр</label>
                        <input type="text" name="name" class="form-control" placeholder="Дээлэнцэцэг" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-phone"></i> Утас</label>
                        <input type="tel" name="phone" class="form-control" placeholder="89370128888" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-lock"></i> PIN код</label>
                        <input type="password" name="pin" class="form-control" placeholder="••••" required>
                    </div>
                    <div class="row">
                        <?php if ($isAdmin): ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-user-tag"></i> Үүрэг</label>
                            <select name="role" class="form-select" required>
                                <option value="reception">Хүлээн авалт</option>
                                <option value="super_admin">Супер админ</option>
                                <option value="admin">Администратор</option>
                                <option value="doctor">Эмч</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлэг</label>
                            <select name="clinic_id" class="form-select" required>
                                <?php foreach ($clinics as $c): ?>
                                    <option value="<?= htmlspecialchars($c['code'] ?? $c['code']) ?>">
                                        <?= htmlspecialchars($c['name'] ?? $c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <!-- Reception: fixed role as doctor, fixed clinic -->
                        <input type="hidden" name="role" value="doctor">
                        <input type="hidden" name="clinic_id" value="<?= htmlspecialchars($userClinic) ?>">
                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлэг</label>
                            <input type="text" class="form-control" value="<?php
                                $clinicNames = ['venera' => 'Венера', 'luxor' => 'Голден Луксор', 'khatan' => 'Гоо Хатан'];
                                echo htmlspecialchars($clinicNames[$userClinic] ?? $userClinic);
                            ?>" disabled readonly style="background: #f1f5f9; font-weight: 500;">
                            <small class="text-muted">Таны эмнэлэгт эмч нэмэгдэнэ</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3 <?= $isAdmin ? 'doctor-only' : '' ?>" <?= $isAdmin ? 'style="display:none;"' : '' ?>>
                        <label class="form-label"><i class="fas fa-stethoscope"></i> Мэргэжил</label>
                        <input type="text" name="specialty" class="form-control" list="specialtyList" placeholder="Шүдний эмч">
                        <datalist id="specialtyList">
                            <?php foreach ($specialties as $spec): ?>
                                <option value="<?= htmlspecialchars($spec) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3 <?= $isAdmin ? 'doctor-only' : '' ?>" <?= $isAdmin ? 'style="display:none;"' : '' ?>>
                        <label class="form-label"><i class="fas fa-briefcase-medical"></i> Тасаг</label>
                        <select name="department" class="form-select">
                            <option value="">-- Сонгох --</option>
                            <option value="Мэс засал">Мэс засал</option>
                            <option value="Мэсийн бус">Мэсийн бус</option>
                            <option value="Уламжлалт">Уламжлалт</option>
                            <option value="Шүд">Шүд</option>
                            <option value="Дусал">Дусал</option>
                        </select>
                    </div>

                    <div class="mb-3 <?= $isAdmin ? 'doctor-only' : '' ?>" <?= $isAdmin ? 'style="display:none;"' : '' ?>>
                        <label class="form-label"><i class="fas fa-palette"></i> Өнгө</label>
                        <input type="color" name="color" class="form-control form-control-color" value="#3b82f6" style="height: 45px; width: 100%;">
                        <small class="text-muted">Календарь дээр эмчийн өнгө</small>
                    </div>
                </div>
                <div class="modal-footer-custom">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Цуцлах</button>
                    <button type="submit" class="btn-save"><i class="fas fa-check"></i> Хадгалах</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal — Засах -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="editForm">
                <div class="modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> <?= $isAdmin ? 'Хэрэглэгч засах' : 'Эмч засах' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user"></i> Нэр</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-phone"></i> Утас</label>
                        <input type="tel" name="phone" class="form-control" placeholder="77777777" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-lock"></i> PIN код (шинэ)</label>
                        <input type="password" name="pin" class="form-control" placeholder="••••">
                    </div>
                    <div class="row">
                        <?php if ($isAdmin): ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-user-tag"></i> Үүрэг</label>
                            <select name="role" class="form-select" required>
                                <option value="reception">Хүлээн авалт</option>
                                <option value="super_admin">Супер админ</option>
                                <option value="admin">Администратор</option>
                                <option value="doctor">Эмч</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлэг</label>
                            <select name="clinic_id" class="form-select" required>
                                <?php foreach ($clinics as $c): ?>
                                    <option value="<?= htmlspecialchars($c['code'] ?? $c['code']) ?>">
                                        <?= htmlspecialchars($c['name'] ?? $c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <!-- Reception: cannot change role or clinic -->
                        <input type="hidden" name="role" value="doctor">
                        <input type="hidden" name="clinic_id" value="<?= htmlspecialchars($userClinic) ?>">
                        <div class="col-12 mb-3">
                            <label class="form-label"><i class="fas fa-hospital"></i> Эмнэлэг</label>
                            <input type="text" class="form-control" value="<?php
                                $clinicNames = ['venera' => 'Венера', 'luxor' => 'Голден Луксор', 'khatan' => 'Гоо Хатан'];
                                echo htmlspecialchars($clinicNames[$userClinic] ?? $userClinic);
                            ?>" disabled readonly style="background: #f1f5f9; font-weight: 500;">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3 doctor-only" style="display:none;">
                        <label class="form-label"><i class="fas fa-stethoscope"></i> Мэргэжил</label>
                        <input type="text" name="specialty" class="form-control" list="specialtyListEdit">
                        <datalist id="specialtyListEdit">
                            <?php foreach ($specialties as $spec): ?>
                                <option value="<?= htmlspecialchars($spec) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3 doctor-only" style="display:none;">
                        <label class="form-label"><i class="fas fa-briefcase-medical"></i> Тасаг</label>
                        <select name="department" class="form-select">
                            <option value="">-- Сонгох --</option>
                            <option value="Мэс засал">Мэс засал</option>
                            <option value="Мэсийн бус">Мэсийн бус</option>
                            <option value="Уламжлалт">Уламжлалт</option>
                            <option value="Шүд">Шүд</option>
                            <option value="Дусал">Дусал</option>
                        </select>
                    </div>

                    <div class="mb-3 doctor-only" style="display:none;">
                        <label class="form-label"><i class="fas fa-palette"></i> Өнгө</label>
                        <input type="color" name="color" class="form-control form-control-color" style="height: 45px; width: 100%;">
                        <small class="text-muted">Календарь дээр эмчийн өнгө</small>
                    </div>
                </div>
                <div class="modal-footer-custom">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Цуцлах</button>
                    <button type="submit" class="btn-save"><i class="fas fa-check"></i> Хадгалах</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addModal = new bootstrap.Modal(document.getElementById('addModal'));
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#usersTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        document.getElementById('addBtn').addEventListener('click', () => {
            const addForm = document.getElementById('addForm');
            addForm.reset();
            toggleRoleAdd();
            addModal.show();
        });

        document.getElementById('addForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'add');

            try {
                const r = await fetch('users.php', {method: 'POST', body: fd});
                const j = await r.json();
                if (j.ok) {
                    showToast('✅ ' + j.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                }
            } catch (err) {
                showToast('❌ Алдаа: ' + err.message, 'error');
            }
        });

        function toggleRoleAdd() {
            const roleSel = document.querySelector('#addForm select[name="role"]');
            const docFields = document.querySelectorAll('#addForm .doctor-only');
            const clinicSel = document.querySelector('#addForm select[name="clinic_id"]');
            if (!roleSel) return;
            docFields.forEach(field => {
                field.style.display = roleSel.value === 'doctor' ? 'block' : 'none';
            });
            if (roleSel.value === 'doctor' && clinicSel && clinicSel.value === 'all') {
                const firstClinic = Array.from(clinicSel.options).find(o => o.value !== 'all');
                if (firstClinic) clinicSel.value = firstClinic.value;
            }
        }
        
        function toggleRoleEdit() {
            const roleSel = document.querySelector('#editForm select[name="role"]');
            const docFields = document.querySelectorAll('#editForm .doctor-only');
            const clinicSel = document.querySelector('#editForm select[name="clinic_id"]');
            if (!roleSel) return;
            docFields.forEach(field => {
                field.style.display = roleSel.value === 'doctor' ? 'block' : 'none';
            });
            if (roleSel.value === 'doctor' && clinicSel && clinicSel.value === 'all') {
                const firstClinic = Array.from(clinicSel.options).find(o => o.value !== 'all');
                if (firstClinic) clinicSel.value = firstClinic.value;
            }
        }
        
        // Bind role change handlers
        const addRoleSel = document.querySelector('#addForm select[name="role"]');
        if (addRoleSel) addRoleSel.addEventListener('change', toggleRoleAdd);
        toggleRoleAdd();
        const editRoleSel = document.querySelector('#editForm select[name="role"]');
        if (editRoleSel) editRoleSel.addEventListener('change', toggleRoleEdit);
        
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const form = document.getElementById('editForm');
                form.querySelector('[name="id"]').value = btn.dataset.id;
                form.querySelector('[name="name"]').value = btn.dataset.name;
                form.querySelector('[name="phone"]').value = btn.dataset.phone || '';
                form.querySelector('[name="role"]').value = btn.dataset.role;
                const clinicSel = form.querySelector('[name="clinic_id"]');
                if (clinicSel) {
                    const val = btn.dataset.clinic || '';
                    clinicSel.value = val || clinicSel.options[0].value;
                }
                const specInput = form.querySelector('[name="specialty"]');
                if (specInput) specInput.value = btn.dataset.specialty || '';
                const deptInput = form.querySelector('[name="department"]');
                if (deptInput) deptInput.value = btn.dataset.department || '';
                const colorInput = form.querySelector('[name="color"]');
                if (colorInput) colorInput.value = btn.dataset.color || '#3b82f6';
                toggleRoleEdit();
                editModal.show();
            });
        });

        document.getElementById('editForm').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'update');

            try {
                const r = await fetch('users.php', {method: 'POST', body: fd});
                const j = await r.json();
                if (j.ok) {
                    showToast('✅ ' + j.msg, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                }
            } catch (err) {
                showToast('❌ Алдаа: ' + err.message, 'error');
            }
        });

        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (confirm(`⚠️ "${btn.dataset.name}" хэрэглэгчийг үнэхээр хасах уу?`)) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', btn.dataset.id);

                    try {
                        const r = await fetch('users.php', {method: 'POST', body: fd});
                        const j = await r.json();
                        if (j.ok) {
                            showToast('✅ ' + j.msg, 'success');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast('⚠️ ' + (j.msg || 'Алдаа'), 'error');
                        }
                    } catch (err) {
                        showToast('❌ Алдаа: ' + err.message, 'error');
                    }
                }
            });
        });

        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                background: ${type === 'success' ? 'linear-gradient(135deg, #10b981, #34d399)' : type === 'error' ? 'linear-gradient(135deg, #ef4444, #f87171)' : 'linear-gradient(135deg, #6366f1, #8b5cf6)'};
                color: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideIn 0.3s ease;
                font-weight: 500;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Add animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
