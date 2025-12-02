<?php
require_once __DIR__ . '/../config.php';
require_role(['admin']);

// --- Эмнэлэг сонгох ---
$clinic = $_GET['clinic'] ?? ($_SESSION['clinic_id'] ?? 'all');

// --- Эмнэлэг жагсаалт (баазаас уншина) ---
$clinics = [];
try {
    $st = db()->prepare("SELECT code, name FROM clinics WHERE active=1 ORDER BY COALESCE(sort_order,0), id");
    $st->execute();
    // fetchAll with FETCH_KEY_PAIR returns code => name pairs
    $clinics = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (empty($clinics)) {
        // fallback to predefined clinics when DB returns no active rows
        $clinics = [
            'venera' => 'Венера',
            'luxor'  => 'Голден Луксор',
            'khatan' => 'Гоо Хатан'
        ];
    }
} catch (Exception $ex) {
    // fallback if table missing or query fails
    $clinics = [
        'venera' => 'Венера',
        'luxor'  => 'Голден Луксор',
        'khatan' => 'Гоо Хатан'
    ];
}

// --- Мэргэжлийн жагсаалт (баазнаас авах)
$specialties = [];
try {
    $st_sp = db()->query("SELECT DISTINCT COALESCE(NULLIF(specialty,''),'Ерөнхий эмч') as specialty FROM doctors ORDER BY specialty");
    $specialties = $st_sp->fetchAll(PDO::FETCH_COLUMN);
    if (empty($specialties)) $specialties = ['Ерөнхий эмч'];
} catch (Exception $e) {
    $specialties = ['Ерөнхий эмч'];
}

// --- Бүх идэвхтэй эмчдийн тоо ---
$totalDoctorsCount = db()->query("SELECT COUNT(id) FROM doctors WHERE active = 1")->fetchColumn();

// --- Хугацааны шүүлтүүр ---
$period = $_GET['period'] ?? 'month';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

switch ($period) {
    case 'today':
        $start_date = $end_date = date('Y-m-d');
        $period_label = 'Өнөөдөр';
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        $period_label = 'Энэ долоо хоног';
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_label = 'Энэ сар';
        break;
    case 'custom':
        $period_label = "{$start_date} - {$end_date}";
        break;
    default:
        $period_label = 'Энэ сар';
}

// --- POST үйлдэл ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $clinic = $_POST['clinic'] ?? 'venera';
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#3b82f6');
        $active = isset($_POST['active']) ? 1 : 0;
        $specialty = trim($_POST['specialty'] ?? 'Ерөнхий эмч');
        $department = trim($_POST['department'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pin = trim($_POST['pin'] ?? '');

        // 🔴 DEBUG: LOG incoming data
        error_log("🔵 ADD DOCTOR: clinic=$clinic, name=$name, phone=$phone, pin=" . ($pin ? '***' : 'empty'));

        if ($name !== '') {
            try {
                $st = db()->prepare("INSERT INTO doctors (clinic, name, color, active, specialty, department, show_in_calendar, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
                $result = $st->execute([$clinic, $name, $color, $active, $specialty, $department, $active]);
                error_log("🔵 Doctor INSERT result: " . ($result ? 'SUCCESS' : 'FAILED'));
                $doctor_id = db()->lastInsertId();
                error_log("🔵 New doctor_id: $doctor_id");
                
                // 📌 Automatically create a corresponding user record for the new doctor.
                try {
                    if ($phone && $pin) {
                        $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
                        $usr = db()->prepare("INSERT INTO users (id, name, phone, pin_hash, role, clinic_id, created_at) VALUES (?, ?, ?, ?, 'doctor', ?, NOW())");
                        $usr->execute([$doctor_id, $name, $phone, $pin_hash, $clinic]);
                        error_log("🔵 User created with phone: $phone");
                    } else {
                        // Fallback: use default PIN if not provided
                        $defaultPinHash = '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2';
                        $usr = db()->prepare("INSERT INTO users (id, name, phone, pin_hash, role, clinic_id, created_at) VALUES (?, ?, '', ?, 'doctor', ?, NOW())");
                        $usr->execute([$doctor_id, $name, $defaultPinHash, $clinic]);
                        error_log("🔵 User created with default PIN");
                    }
                } catch (Exception $ex) {
                    error_log("❌ User creation failed: " . $ex->getMessage());
                }
                
                // Save working hours
                for ($i=0; $i<7; $i++) {
                    $start = $_POST['wh_start_' . $i] ?? '09:00';
                    $end = $_POST['wh_end_' . $i] ?? '18:00';
                    $avail = isset($_POST['wh_available_' . $i]) && $_POST['wh_available_' . $i] == '1' ? 1 : 0;
                    $stwh = db()->prepare("INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?,?,?,?,?)");
                    $stwh->execute([$doctor_id, $i, $start, $end, $avail]);
                }
                error_log("🔵 Working hours saved");
                echo json_encode(['ok'=>true, 'id'=>$doctor_id]);
            } catch (Exception $ex) {
                error_log("❌ Database error: " . $ex->getMessage());
                echo json_encode(['ok'=>false, 'msg'=>'Database error: ' . $ex->getMessage()]);
            }
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'Нэр хоосон байна.']);
        }
        exit;
    }

    if ($action === 'update') {
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#3b82f6');
        $active = isset($_POST['active']) ? 1 : 0;
        $specialty = trim($_POST['specialty'] ?? 'Ерөнхий эмч');
        $department = trim($_POST['department'] ?? '');
        $st = db()->prepare("UPDATE doctors SET name=?, color=?, active=?, specialty=?, department=?, show_in_calendar=? WHERE id=?");
        $st->execute([$name, $color, $active, $specialty, $department, $active, $id]);
        // 🛠 Keep user record in sync with doctor record.  Update name and clinic.
        try {
            $usrUpd = db()->prepare("UPDATE users SET name=?, clinic_id=? WHERE id=?");
            $usrUpd->execute([$name, $clinic, $id]);
        } catch (Exception $ex) {
            // ignore user update failure
        }
        // Update working hours
        $delwh = db()->prepare("DELETE FROM working_hours WHERE doctor_id=?");
        $delwh->execute([$id]);
        for ($i=0; $i<7; $i++) {
            $start = $_POST['wh_start_' . $i] ?? '09:00';
            $end = $_POST['wh_end_' . $i] ?? '18:00';
            $avail = isset($_POST['wh_available_' . $i]) && $_POST['wh_available_' . $i] == '1' ? 1 : 0;
            $stwh = db()->prepare("INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?,?,?,?,?)");
            $stwh->execute([$id, $i, $start, $end, $avail]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }
    
    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        if ($id) {
            $st = db()->prepare("DELETE FROM doctors WHERE id=?");
            $st->execute([$id]);
            // ❌ Also remove associated user account when doctor is deleted
            try {
                $uDel = db()->prepare("DELETE FROM users WHERE id=?");
                $uDel->execute([$id]);
            } catch (Exception $ex) {
                // ignore
            }
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'ID алга.']);
        }
        exit;
    }
}

// --- Эмчдийн KPI статистик (шинэчлэгдсэн) ---
$kpi_query = "
    SELECT 
        d.*,
        COUNT(b.id) as total_bookings,
        SUM(CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        COUNT(DISTINCT b.patient_name) as unique_patients,
        AVG(b.visit_count) as avg_visits
    FROM doctors d
    LEFT JOIN bookings b ON d.id = b.doctor_id 
        AND b.date BETWEEN ? AND ?
    WHERE (? = 'all' OR d.clinic = ?)
    GROUP BY d.id
    ORDER BY total_bookings DESC
";

$st = db()->prepare($kpi_query);
$st->execute([$start_date, $end_date, $clinic, $clinic]);
$doctors_kpi = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Нийт KPI ---
$total_stats = [
    'total_bookings' => array_sum(array_column($doctors_kpi, 'total_bookings')),
    'total_paid' => array_sum(array_column($doctors_kpi, 'paid_count')),
    'total_cancelled' => array_sum(array_column($doctors_kpi, 'cancelled_count')),
    'total_confirmed' => array_sum(array_column($doctors_kpi, 'confirmed_count')),
    'total_online' => array_sum(array_column($doctors_kpi, 'confirmed_count')),
    'total_patients' => array_sum(array_column($doctors_kpi, 'unique_patients'))
];

$total_stats['success_rate'] = $total_stats['total_bookings'] ? 
    round(($total_stats['total_paid'] / $total_stats['total_bookings']) * 100, 1) : 0;

// --- Өдрийн цагийн ачаалал ---
$workload_query = "
    SELECT 
        d.id as doctor_id,
        HOUR(b.start_time) as hour,
        COUNT(b.id) as booking_count
    FROM doctors d
    LEFT JOIN bookings b ON d.id = b.doctor_id 
        AND b.date BETWEEN ? AND ?
        AND b.status != 'cancelled'
    WHERE (? = 'all' OR d.clinic = ?)
    GROUP BY d.id, HOUR(b.start_time)
    ORDER BY d.id, hour
";

$st = db()->prepare($workload_query);
$st->execute([$start_date, $end_date, $clinic, $clinic]);
$workload_data = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Эмч бүрийн ачаалал ---
$doctor_workload = [];
foreach ($workload_data as $row) {
    $doctor_workload[$row['doctor_id']][$row['hour']] = $row['booking_count'];
}

// --- Тухайн эмчийн дэлгэрэнгүй мэдээлэл ---
$selected_doctor_id = $_GET['doctor_id'] ?? null;
$doctor_detail = null;
$doctor_timeline = [];

if ($selected_doctor_id) {
    $st = db()->prepare("SELECT * FROM doctors WHERE id = ?");
    $st->execute([$selected_doctor_id]);
    $doctor_detail = $st->fetch(PDO::FETCH_ASSOC);
    
    // Эмчийн цагийн хуваарь
    $timeline_query = "
        SELECT 
            b.date,
            b.start_time,
            b.end_time,
            b.status,
            b.patient_name,
            b.service_name,
            b.visit_count,
            b.source
        FROM bookings b
        WHERE b.doctor_id = ? 
            AND b.date BETWEEN ? AND ?
        ORDER BY b.date, b.start_time
    ";
    $st = db()->prepare($timeline_query);
    $st->execute([$selected_doctor_id, $start_date, $end_date]);
    $doctor_timeline = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>📊 Эмч KPI Dashboard — Захиалгын систем</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
        
        * { box-sizing: border-box; }
        
        body { 
            background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
            background-attachment: fixed;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            color: #1e293b;
        }
        
        main {
            margin-left: 250px;
            padding: 2rem 2.5rem;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-radius: 20px;
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 0.9rem;
            margin: 0;
        }

        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.primary::before { background: linear-gradient(90deg, #6366f1, #818cf8); }
        .stat-card.success::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .stat-card.warning::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .stat-card.danger::before { background: linear-gradient(90deg, #ef4444, #f87171); }
        .stat-card.info::before { background: linear-gradient(90deg, #06b6d4, #22d3ee); }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-card .icon-box {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-card .icon-box.primary { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #6366f1; }
        .stat-card .icon-box.success { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #10b981; }
        .stat-card .icon-box.warning { background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #f59e0b; }
        .stat-card .icon-box.danger { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #ef4444; }
        .stat-card .icon-box.info { background: linear-gradient(135deg, #ecfeff, #cffafe); color: #06b6d4; }

        .stat-card h6 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 800;
        }

        .stat-card .subtitle {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        /* Glass Card */
        .glass-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            border: 1px solid var(--border);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .period-btn {
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 2px solid var(--border);
            background: white;
            color: #64748b;
            transition: all 0.2s;
            text-decoration: none;
        }

        .period-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #f5f3ff;
        }

        .period-btn.active {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            border-color: transparent;
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* Doctor Avatar */
        .doctor-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Modern Table */
        .modern-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.5rem;
        }

        .modern-table thead th {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1rem;
            border: none;
        }

        .modern-table thead th:first-child { border-radius: 12px 0 0 12px; }
        .modern-table thead th:last-child { border-radius: 0 12px 12px 0; }

        .modern-table tbody tr {
            background: white;
            transition: all 0.3s ease;
        }

        .modern-table tbody tr:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.15);
        }

        .modern-table tbody td {
            padding: 1rem;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .modern-table tbody td:first-child {
            border-left: 1px solid #f1f5f9;
            border-radius: 12px 0 0 12px;
        }

        .modern-table tbody td:last-child {
            border-right: 1px solid #f1f5f9;
            border-radius: 0 12px 12px 0;
        }

        /* Badges */
        .clinic-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .stat-badge.primary { background: #eff6ff; color: #6366f1; }
        .stat-badge.success { background: #ecfdf5; color: #10b981; }
        .stat-badge.warning { background: #fffbeb; color: #f59e0b; }
        .stat-badge.danger { background: #fef2f2; color: #ef4444; }
        .stat-badge.info { background: #ecfeff; color: #06b6d4; }

        /* Progress Cell */
        .progress-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .progress-cell .progress {
            flex: 1;
            height: 8px;
            border-radius: 10px;
            background: #e2e8f0;
        }

        .progress-cell .progress-bar {
            border-radius: 10px;
        }

        .progress-cell .percent {
            font-weight: 700;
            font-size: 0.9rem;
            min-width: 45px;
            text-align: right;
        }

        /* Action Buttons */
        .btn-action {
            padding: 0.45rem 0.9rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-action.primary {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
        }

        .btn-action.success {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
        }

        .btn-action.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            color: white;
        }

        .btn-action.danger {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: white;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Modern Select */
        .modern-select {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            background: white;
            min-width: 180px;
            transition: all 0.2s;
        }

        .modern-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        /* Add Button */
        .btn-add {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.7rem 1.5rem;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            color: white;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in {
            animation: fadeInUp 0.5s ease-out forwards;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <main>
        <!-- 🎯 Page Header -->
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1><i class="fas fa-user-md"></i> Эмч KPI Dashboard</h1>
                <p>Эмчдийн гүйцэтгэлийн цогц тайлан | Бүх эмч: <strong><?= $totalDoctorsCount ?></strong></p>
            </div>
            <button id="addBtn" class="btn-add">
                <i class="fas fa-plus"></i> Эмч нэмэх
            </button>
        </div>

        <!-- 🔍 Filter Section -->
        <div class="filter-section">
            <div class="d-flex gap-2">
                <a href="?clinic=<?= $clinic ?>&period=today" class="period-btn <?= $period=='today'?'active':'' ?>">
                    <i class="fas fa-sun me-1"></i>Өнөөдөр
                </a>
                <a href="?clinic=<?= $clinic ?>&period=week" class="period-btn <?= $period=='week'?'active':'' ?>">
                    <i class="fas fa-calendar-week me-1"></i>7 хоног
                </a>
                <a href="?clinic=<?= $clinic ?>&period=month" class="period-btn <?= $period=='month'?'active':'' ?>">
                    <i class="fas fa-calendar-alt me-1"></i>Сар
                </a>
            </div>

            <form method="get" class="mb-0 ms-auto d-flex gap-2 align-items-center">
                <select name="clinic" class="modern-select" onchange="this.form.submit()">
                    <option value="all" <?= $clinic=='all'?'selected':'' ?>>🏥 БҮХ ЭМНЭЛГҮҮД</option>
                    <?php foreach($clinics as $id=>$label): ?>
                        <option value="<?= $id ?>" <?= $clinic==$id?'selected':'' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="period" value="<?= $period ?>">
            </form>

            <a href="#" onclick="window.print()" class="btn-action success">
                <i class="fas fa-file-export"></i>Гаргах
            </a>
        </div>

        <!-- 📊 Stats Row -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6 animate-fade-in" style="animation-delay: 0.1s">
                <div class="stat-card primary">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6>Нийт Захиалга</h6>
                            <div class="value text-primary"><?= number_format($total_stats['total_bookings']) ?></div>
                            <div class="subtitle"><?= $period_label ?></div>
                        </div>
                        <div class="icon-box primary">
                            <i class="fas fa-calendar-check fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 animate-fade-in" style="animation-delay: 0.2s">
                <div class="stat-card success">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6>Төлбөртэй</h6>
                            <div class="value text-success"><?= number_format($total_stats['total_paid']) ?></div>
                            <div class="subtitle">Амжилттай</div>
                        </div>
                        <div class="icon-box success">
                            <i class="fas fa-check-circle fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 animate-fade-in" style="animation-delay: 0.3s">
                <div class="stat-card warning">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6>Цуцалсан</h6>
                            <div class="value text-warning"><?= number_format($total_stats['total_cancelled']) ?></div>
                            <div class="subtitle">Цуцалсан</div>
                        </div>
                        <div class="icon-box warning">
                            <i class="fas fa-times-circle fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 animate-fade-in" style="animation-delay: 0.4s">
                <div class="stat-card info">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6>Өвчтөн</h6>
                            <div class="value" style="color: #06b6d4;"><?= number_format($total_stats['total_patients']) ?></div>
                            <div class="subtitle">Уникал өвчтөн</div>
                        </div>
                        <div class="icon-box info">
                            <i class="fas fa-users fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📈 Charts Section -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="glass-card p-4 h-100">
                    <h5 class="fw-bold mb-3" style="color: #1e293b;">
                        <i class="fas fa-chart-bar me-2" style="color: #6366f1;"></i>Эмчдийн захиалгын харьцуулалт
                    </h5>
                    <div class="chart-container">
                        <canvas id="bookingsChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="glass-card p-4 h-100">
                    <h5 class="fw-bold mb-3" style="color: #1e293b;">
                        <i class="fas fa-chart-pie me-2" style="color: #8b5cf6;"></i>Төлөвийн хуваарилалт
                    </h5>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🏥 Doctors Table -->
        <div class="glass-card p-4 animate-fade-in">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0" style="color: #1e293b;">
                    <i class="fas fa-user-md me-2" style="color: #6366f1;"></i>Эмчдийн Гүйцэтгэлийн Тайлан
                </h5>
                <span style="color: #64748b; font-weight: 600;"><?= count($doctors_kpi) ?> эмч</span>
            </div>
            
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Эмч</th>
                            <th>Эмнэлэг</th>
                            <th>Нийт</th>
                            <th>Төлбөртэй</th>
                            <th>Цуцалсан</th>
                            <th>Баталгаажсан</th>
                            <th>Онлайн</th>
                            <th>Өвчтөн</th>
                            <th>Гүйцэтгэл</th>
                            <th>Үйлдэл</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doctors_kpi as $doctor): 
                            $success_rate = $doctor['total_bookings'] ? 
                                round(($doctor['paid_count'] / $doctor['total_bookings']) * 100, 1) : 0;
                            $workload_data = $doctor_workload[$doctor['id']] ?? [];
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="doctor-avatar" style="background: <?= $doctor['color'] ?>;">
                                            <?= mb_substr($doctor['name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($doctor['name']) ?></div>
                                            <small class="text-muted">ID: <?= $doctor['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="clinic-badge"><?= htmlspecialchars($clinics[$doctor['clinic']] ?? $doctor['clinic']) ?></span>
                                </td>
                                <td>
                                    <span class="stat-badge primary"><?= $doctor['total_bookings'] ?></span>
                                </td>
                                <td>
                                    <span class="stat-badge success"><?= $doctor['paid_count'] ?></span>
                                </td>
                                <td>
                                    <span class="stat-badge warning"><?= $doctor['cancelled_count'] ?></span>
                                </td>
                                <td>
                                    <span class="stat-badge info"><?= $doctor['confirmed_count'] ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= $doctor['unique_patients'] ?></span>
                                    <?php if ($doctor['avg_visits']): ?>
                                        <br><small class="text-muted">Дундаж: <?= round($doctor['avg_visits'], 1) ?> удаа</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="progress-cell">
                                        <div class="progress">
                                            <div class="progress-bar bg-success" style="width:<?= $success_rate ?>%"></div>
                                        </div>
                                        <span class="percent"><?= $success_rate ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="?clinic=<?= $clinic ?>&period=<?= $period ?>&doctor_id=<?= $doctor['id'] ?>" 
                                           class="btn-action primary">
                                            <i class="fas fa-chart-line"></i>Дэлгэрэнгүй
                                        </a>
                                        <button class="btn-action warning edit-btn"
                                                data-id="<?= $doctor['id'] ?>"
                                                data-name="<?= htmlspecialchars($doctor['name']) ?>"
                                                data-color="<?= htmlspecialchars($doctor['color']) ?>"
                                                data-active="<?= $doctor['active'] ?>"
                                                data-specialty="<?= htmlspecialchars($doctor['specialty'] ?? 'Ерөнхий эмч') ?>"
                                                data-department="<?= htmlspecialchars($doctor['department'] ?? '') ?>">
                                            <i class="fas fa-edit"></i>Засах
                                        </button>
                                        <button class="btn-action danger delete-btn"
                                                data-id="<?= $doctor['id'] ?>"
                                                data-name="<?= htmlspecialchars($doctor['name']) ?>">
                                            <i class="fas fa-trash"></i>Хасах
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 👨‍⚕️ Сонгогдсон эмчийн дэлгэрэнгүй -->
        <?php if ($doctor_detail): ?>
        <div id="doctorDetailSection" class="glass-card p-4 mt-4 animate-fade-in" style="border: 3px solid #6366f1; box-shadow: 0 0 30px rgba(99, 102, 241, 0.3);">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0" style="color: #1e293b;">
                    <i class="fas fa-user-md me-2" style="color: #6366f1;"></i>
                    <?= htmlspecialchars($doctor_detail['name']) ?> - Дэлгэрэнгүй тайлан
                    <span class="ms-2" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem;">📍 Энд байна</span>
                </h5>
                <a href="doctors.php?clinic=<?= $clinic ?>&period=<?= $period ?>" class="btn-action danger">
                    <i class="fas fa-times"></i>Хаах
                </a>
            </div>

            <div class="row g-4">
                <!-- Эмчийн үндсэн мэдээлэл -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="doctor-avatar mx-auto mb-3" style="background: <?= $doctor_detail['color'] ?>; width: 80px; height: 80px;">
                                <?= mb_substr($doctor_detail['name'], 0, 1) ?>
                            </div>
                            <h5 class="fw-bold"><?= htmlspecialchars($doctor_detail['name']) ?></h5>
                            <div class="d-flex justify-content-center gap-2">
                                <span class="badge bg-primary"><?= htmlspecialchars($clinics[$doctor_detail['clinic']] ?? $doctor_detail['clinic']) ?></span>
                                <span class="badge <?= $doctor_detail['active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $doctor_detail['active'] ? 'Идэвхтэй' : 'Идэвхгүй' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Өдрийн цагийн ачаалал -->
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent">
                            <h6 class="fw-bold mb-0"><i class="fas fa-clock me-2"></i>Өдрийн цагийн ачаалал</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="workloadChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Сүүлийн захиалгууд -->
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent">
                            <h6 class="fw-bold mb-0"><i class="fas fa-list me-2"></i>Сүүлийн захиалгууд</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($doctor_timeline): ?>
                                <div class="timeline">
                                    <?php foreach (array_slice($doctor_timeline, 0, 10) as $booking): ?>
                                        <div class="timeline-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?= htmlspecialchars($booking['patient_name']) ?></strong>
                                                    <small class="text-muted ms-2"><?= $booking['date'] ?> <?= $booking['start_time'] ?></small>
                                                    <?php if ($booking['service_name']): ?>
                                                        <br><span class="service-badge"><?= htmlspecialchars($booking['service_name']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($booking['visit_count'] > 1): ?>
                                                        <span class="badge bg-info ms-1"><?= $booking['visit_count'] ?> удаа</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-<?= $booking['status'] == 'paid' ? 'success' : ($booking['status'] == 'cancelled' ? 'warning' : 'primary') ?>">
                                                        <?= $booking['status'] ?>
                                                    </span>
                                                    <br>
                                                    <small class="text-muted"><?= $booking['source'] ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center">Захиалга байхгүй</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- 🆕 Modal — Шинэ эмч нэмэх -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form class="modal-content glass-card" id="addForm" style="border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary), #60a5fa); color: white; border: none;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Шинэ эмч нэмэх</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Эмнэлэг</label>
                            <select name="clinic" class="form-select" style="border-radius: 8px; border: 2px solid #e5e7eb;" required>
                                <?php foreach($clinics as $id=>$label): ?>
                                    <option value="<?= $id ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Юуны эмч</label>
                            <!-- Allow manual entry of specialty with a datalist for existing options -->
                            <input type="text" name="specialty" class="form-control" style="border-radius: 8px; border: 2px solid #e5e7eb;" list="specialtyListAdd" placeholder="Мэргэжил" required>
                            <datalist id="specialtyListAdd">
                                <?php foreach($specialties as $sp): ?>
                                    <option value="<?= htmlspecialchars($sp) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Тасаг</label>
                            <select name="department" class="form-select" style="border-radius: 8px; border: 2px solid #e5e7eb;">
                                <option value="">-- Сонгох --</option>
                                <option value="general_surgery">Мэс / ерөнхий</option>
                                <option value="nose_surgery">Мэс / хамар</option>
                                <option value="oral_surgery">Мэс / амны</option>
                                <option value="hair_clinic">Үс</option>
                                <option value="non_surgical">Мэсийн бус</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Эмчийн нэр</label>
                            <input type="text" name="name" class="form-control" style="border-radius: 8px; border: 2px solid #e5e7eb; padding: 10px;" placeholder="Эмчийн бүтэн нэр" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Утасны дугаар</label>
                            <input type="tel" name="phone" class="form-control" style="border-radius: 8px; border: 2px solid #e5e7eb;" placeholder="8XXXXXXXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">PIN код</label>
                            <input type="password" name="pin" class="form-control" style="border-radius: 8px; border: 2px solid #e5e7eb;" placeholder="PIN оруулна уу">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Өнгө</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" name="color" class="form-control form-control-color" value="#3b82f6" style="width: 60px; height: 44px; border-radius: 8px; border: 2px solid #e5e7eb; cursor: pointer;">
                                <small class="text-muted">Эмчийн дүрс</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="active" id="addActive" checked style="width: 20px; height: 20px; cursor: pointer;">
                                <label class="form-check-label fw-bold" for="addActive" style="cursor: pointer;">Идэвхтэй эмч</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    
                    <div>
                        <label class="form-label fw-bold mb-3"><i class="fas fa-calendar-alt me-2"></i>Ажиллах цагийн хуваарь</label>
                        <div style="overflow-x: auto;">
                            <table class="table table-sm align-middle" style="border-collapse: separate; border-spacing: 0 8px;">
                                <thead>
                                    <tr style="background: linear-gradient(135deg, var(--primary), #60a5fa); color: white;">
                                        <th style="border-radius: 8px 0 0 8px; padding: 10px;">Өдөр</th>
                                        <th style="padding: 10px;">Эхлэх цаг</th>
                                        <th style="padding: 10px;">Дуусах цаг</th>
                                        <th style="border-radius: 0 8px 8px 0; padding: 10px; text-align: center;">Ажиллах</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $days = ['Ням','Даваа','Мягмар','Лхагва','Пүрэв','Баасан','Бямба'];
                                    for($i=0;$i<7;$i++): ?>
                                    <tr style="background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;">
                                        <td class="align-middle fw-bold text-center" style="border-radius: 8px 0 0 8px; padding: 12px; width: 80px;"><?= $days[$i] ?></td>
                                        <td style="padding: 12px;"><input type="time" name="wh_start_<?= $i ?>" class="form-control form-control-sm text-center" value="09:00" style="border-radius: 6px; border: 1px solid #d1d5db;"></td>
                                        <td style="padding: 12px;"><input type="time" name="wh_end_<?= $i ?>" class="form-control form-control-sm text-center" value="18:00" style="border-radius: 6px; border: 1px solid #d1d5db;"></td>
                                        <td class="text-center align-middle" style="border-radius: 0 8px 8px 0; padding: 12px;">
                                            <input type="hidden" name="wh_available_<?= $i ?>" value="0">
                                            <input type="checkbox" name="wh_available_<?= $i ?>" value="1" checked style="width: 20px; height: 20px; cursor: pointer;">
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e5e7eb; background: #f8fafc; border-radius: 0 0 16px 16px;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Цуцлах</button>
                    <button type="submit" class="btn btn-success" style="background: linear-gradient(135deg, #10b981, #34d399); border: none;"><i class="fas fa-save me-2"></i>Хадгалах</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 🪄 Modal — Эмч засах -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" id="editForm" style="border: none; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);">
                <div class="modal-header" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none; padding: 1.5rem 2rem;">
                    <h5 class="modal-title text-white fw-bold" style="font-size: 1.25rem;">
                        <i class="fas fa-user-edit me-2"></i>Эмчийн мэдээлэл засах
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding: 2rem; background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);">
                    <input type="hidden" name="id" id="doctorId">
                    
                    <!-- Эмчийн нэр -->
                    <div class="mb-4">
                        <label class="form-label fw-bold" style="color: #374151; font-size: 0.9rem;">
                            <i class="fas fa-user me-2" style="color: #6366f1;"></i>Эмчийн нэр
                        </label>
                        <input type="text" name="name" id="doctorName" class="form-control" required
                               style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 0.75rem 1rem; font-size: 1rem; transition: all 0.2s;"
                               onfocus="this.style.borderColor='#6366f1'; this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.1)'"
                               onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'">
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <!-- Өнгө -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold" style="color: #374151; font-size: 0.9rem;">
                                <i class="fas fa-palette me-2" style="color: #8b5cf6;"></i>Өнгө
                            </label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" name="color" id="doctorColor" value="#6366f1"
                                       style="width: 60px; height: 50px; border: 2px solid #e5e7eb; border-radius: 12px; cursor: pointer; padding: 4px;">
                                <span style="color: #64748b; font-size: 0.85rem;">Эмчийн дүрс</span>
                            </div>
                        </div>
                        
                        <!-- Идэвхтэй -->
                        <div class="col-md-8">
                            <label class="form-label fw-bold" style="color: #374151; font-size: 0.9rem;">
                                <i class="fas fa-toggle-on me-2" style="color: #10b981;"></i>Төлөв
                            </label>
                            <div class="d-flex align-items-center gap-3 p-3" style="background: #f0fdf4; border-radius: 12px; border: 2px solid #d1fae5;">
                                <input class="form-check-input" type="checkbox" name="active" id="doctorActive"
                                       style="width: 24px; height: 24px; cursor: pointer; border-radius: 6px;">
                                <label class="form-check-label fw-bold" for="doctorActive" style="cursor: pointer; color: #166534;">
                                    Идэвхтэй эмч
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <!-- Юуны эмч -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="color: #374151; font-size: 0.9rem;">
                                <i class="fas fa-stethoscope me-2" style="color: #06b6d4;"></i>Юуны эмч
                            </label>
                            <input type="text" name="specialty" id="doctorSpecialty" class="form-control" list="specialtyListEdit" required
                                   style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 0.75rem 1rem;"
                                   onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e5e7eb'">
                            <datalist id="specialtyListEdit">
                                <?php foreach($specialties as $sp): ?>
                                    <option value="<?= htmlspecialchars($sp) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        
                        <!-- Тасаг -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="color: #374151; font-size: 0.9rem;">
                                <i class="fas fa-building me-2" style="color: #f59e0b;"></i>Тасаг
                            </label>
                            <select name="department" id="doctorDepartment" class="form-select"
                                    style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 0.75rem 1rem;"
                                    onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="">-- Сонгох --</option>
                                <option value="general_surgery">Мэс / ерөнхий</option>
                                <option value="nose_surgery">Мэс / хамар</option>
                                <option value="oral_surgery">Мэс / амны</option>
                                <option value="hair_clinic">Үс</option>
                                <option value="non_surgical">Мэсийн бус</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Ажиллах цагийн хуваарь -->
                    <div class="mb-2">
                        <label class="form-label fw-bold mb-3" style="color: #374151; font-size: 0.9rem;">
                            <i class="fas fa-clock me-2" style="color: #ef4444;"></i>Ажиллах цагийн хуваарь
                        </label>
                        <div style="background: white; border-radius: 16px; border: 2px solid #e5e7eb; overflow: hidden;">
                            <table class="table table-sm align-middle mb-0" id="whTableEdit">
                                <thead>
                                    <tr style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);">
                                        <th style="color: white; font-weight: 600; padding: 1rem; font-size: 0.8rem; text-transform: uppercase;">Өдөр</th>
                                        <th style="color: white; font-weight: 600; padding: 1rem; font-size: 0.8rem; text-transform: uppercase;">Эхлэх</th>
                                        <th style="color: white; font-weight: 600; padding: 1rem; font-size: 0.8rem; text-transform: uppercase;">Дуусах</th>
                                        <th style="color: white; font-weight: 600; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; text-align: center;">Ажиллах</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $days = ['Ням','Даваа','Мягмар','Лхагва','Пүрэв','Баасан','Бямба'];
                                    for($i=0;$i<7;$i++): 
                                        $bgColor = $i % 2 == 0 ? '#f8fafc' : '#ffffff';
                                    ?>
                                    <tr style="background: <?= $bgColor ?>;">
                                        <td class="align-middle fw-bold" style="padding: 0.75rem 1rem; color: #374151;"><?= $days[$i] ?></td>
                                        <td style="padding: 0.5rem;">
                                            <input type="time" name="wh_start_<?= $i ?>" id="whEditStart<?= $i ?>" 
                                                   class="form-control form-control-sm text-center" value="09:00"
                                                   style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 0.5rem;">
                                        </td>
                                        <td style="padding: 0.5rem;">
                                            <input type="time" name="wh_end_<?= $i ?>" id="whEditEnd<?= $i ?>" 
                                                   class="form-control form-control-sm text-center" value="18:00"
                                                   style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 0.5rem;">
                                        </td>
                                        <td class="text-center align-middle" style="padding: 0.5rem;">
                                            <input type="hidden" name="wh_available_<?= $i ?>" value="0">
                                            <input type="checkbox" name="wh_available_<?= $i ?>" id="whEditAvail<?= $i ?>" value="1" checked
                                                   style="width: 22px; height: 22px; cursor: pointer; border-radius: 6px; accent-color: #6366f1;">
                                        </td>
                                    </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border: none; padding: 1.25rem 2rem; background: #f8fafc;">
                    <button type="button" class="btn px-4 py-2" data-bs-dismiss="modal"
                            style="background: #e5e7eb; color: #374151; border: none; border-radius: 12px; font-weight: 600;">
                        <i class="fas fa-times me-2"></i>Цуцлах
                    </button>
                    <button type="submit" class="btn px-4 py-2"
                            style="background: linear-gradient(135deg, #10b981 0%, #34d399 100%); color: white; border: none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
                        <i class="fas fa-save me-2"></i>Хадгалах
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    
    // === Chart.js Configuration ===
    const bookingsCtx = document.getElementById('bookingsChart').getContext('2d');
    const bookingsChart = new Chart(bookingsCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($doctors_kpi, 'name')) ?>,
            datasets: [{
                label: 'Нийт захиалга',
                data: <?= json_encode(array_column($doctors_kpi, 'total_bookings')) ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                borderRadius: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }
    });

    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Төлбөртэй', 'Цуцалсан', 'Баталгаажсан'],
            datasets: [{
                data: [
                    <?= $total_stats['total_paid'] ?>,
                    <?= $total_stats['total_cancelled'] ?>,
                    <?= $total_stats['total_confirmed'] ?>
                ],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(59, 130, 246, 0.8)'
                ],
                borderWidth: 3,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    <?php if ($selected_doctor_id && isset($doctor_workload[$selected_doctor_id])): ?>
    const workloadCtx = document.getElementById('workloadChart').getContext('2d');
    const workloadData = <?= json_encode($doctor_workload[$selected_doctor_id]) ?>;
    const hours = Array.from({length: 24}, (_, i) => i);
    const workloadChart = new Chart(workloadCtx, {
        type: 'bar',
        data: {
            labels: hours.map(h => h + ':00'),
            datasets: [{
                label: 'Захиалгын тоо',
                data: hours.map(h => workloadData[h] || 0),
                backgroundColor: 'rgba(59, 130, 246, 0.6)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Захиалгын тоо'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Цаг'
                    }
                }
            }
        }
    });
    <?php endif; ?>

    // === Modal Management ===
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    const addModal = new bootstrap.Modal(document.getElementById('addModal'));

    // ✏️ Засах товч
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            console.log('🔧 Edit button clicked for doctor:', btn.dataset.id);
            
            const doctorId = btn.dataset.id;
            const doctorName = btn.dataset.name;
            const doctorColor = btn.dataset.color;
            const doctorActive = btn.dataset.active;
            const specialty = btn.dataset.specialty || 'Ерөнхий эмч';
            const department = btn.dataset.department || '';
            
            // Set basic info
            document.getElementById('doctorId').value = doctorId;
            document.getElementById('doctorName').value = doctorName;
            document.getElementById('doctorColor').value = doctorColor;
            document.getElementById('doctorActive').checked = (doctorActive === '1');
            
            // Set specialty (input with datalist)
            const sel = document.getElementById('doctorSpecialty');
            if (sel) {
                sel.value = specialty;
            }
            
            // Set department
            const deptEl = document.getElementById('doctorDepartment');
            if (deptEl) {
                deptEl.value = department;
            }
            
            console.log('📋 Fetching working hours...');
            
            // Load working hours from API
            try {
                const response = await fetch('api.php?action=doctor_working_hours&id=' + doctorId);
                const data = await response.json();
                
                console.log('✅ API Response:', data);
                
                if (data.ok && Array.isArray(data.hours) && data.hours.length > 0) {
                    console.log(`✅ Found ${data.hours.length} working hour records`);
                    
                    for (let i = 0; i < 7; i++) {
                        const wh = data.hours.find(h => parseInt(h.day_of_week) === i);
                        const startEl = document.getElementById('whEditStart' + i);
                        const endEl = document.getElementById('whEditEnd' + i);
                        const checkEl = document.getElementById('whEditAvail' + i);
                        
                        if (wh) {
                            const startTime = wh.start_time.substring(0, 5);
                            const endTime = wh.end_time.substring(0, 5);
                            const isAvailable = (wh.is_available == 1 || wh.is_available == '1');
                            
                            if (startEl) startEl.value = startTime;
                            if (endEl) endEl.value = endTime;
                            if (checkEl) checkEl.checked = isAvailable;
                            
                            console.log(`  Day ${i}: ${startTime}-${endTime}, available=${isAvailable}`);
                        } else {
                            // No data for this day, set defaults
                            if (startEl) startEl.value = '09:00';
                            if (endEl) endEl.value = '18:00';
                            if (checkEl) checkEl.checked = true;
                            console.log(`  Day ${i}: using defaults (no data in DB)`);
                        }
                    }
                } else {
                    console.warn('⚠️ No working hours found or empty response');
                    // Set all to defaults
                    for (let i = 0; i < 7; i++) {
                        const startEl = document.getElementById('whEditStart' + i);
                        const endEl = document.getElementById('whEditEnd' + i);
                        const checkEl = document.getElementById('whEditAvail' + i);
                        if (startEl) startEl.value = '09:00';
                        if (endEl) endEl.value = '18:00';
                        if (checkEl) checkEl.checked = true;
                    }
                }
            } catch (err) {
                console.error('❌ Error fetching working hours:', err);
                // Set defaults on error
                for (let i = 0; i < 7; i++) {
                    const startEl = document.getElementById('whEditStart' + i);
                    const endEl = document.getElementById('whEditEnd' + i);
                    const checkEl = document.getElementById('whEditAvail' + i);
                    if (startEl) startEl.value = '09:00';
                    if (endEl) endEl.value = '18:00';
                    if (checkEl) checkEl.checked = true;
                }
            }
            
            // Show modal
            editModal.show();
        });
    });

    // �️ Хасах товч
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const doctorId = btn.dataset.id;
            const doctorName = btn.dataset.name;
            
            if (confirm(`⚠️ "${doctorName}" эмчийг үнэхээр хасах уу? Энэ үйлдлийг буцаах боломжгүй.`)) {
                try {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', doctorId);
                    
                    const r = await fetch('doctors.php', { method: 'POST', body: fd });
                    const j = await r.json();
                    
                    if (j.ok) {
                        alert('✅ Эмч амжилттай устгагдлаа.');
                        location.reload();
                    } else {
                        alert('⚠️ Устгахад алдаа гарлаа: ' + (j.msg || 'Үл мэдэгдэх алдаа'));
                    }
                } catch (e) {
                    alert('❌ Сүлжээний алдаа: ' + e.message);
                }
            }
        });
    });

    // 💾 Засвар хадгалах
    document.getElementById('editForm').addEventListener('submit', async e => {
        e.preventDefault();
        console.log('📝 Saving doctor form...');
        const fd = new FormData(e.target);
        fd.append('action','update');
        
        // Log form data for debugging - only working hours fields
        console.log('📋 Form data being sent:');
        let whDataCount = 0;
        for (let [key, value] of fd.entries()) {
            if (key.startsWith('wh_') || key === 'id' || key === 'name') {
                console.log(`  ${key}: ${value}`);
                if (key.startsWith('wh_')) whDataCount++;
            }
        }
        console.log(`✅ Total working hour fields: ${whDataCount}`);
        
        try {
            const r = await fetch('doctors.php', {method:'POST', body:fd});
            const responseText = await r.text();
            console.log('📥 Response text:', responseText);
            
            const j = JSON.parse(responseText);
            console.log('✅ Parsed response:', j);
            
            if(j.ok) {
                alert('✅ Амжилттай хадгалагдлаа');
                location.reload();
            } else {
                alert('⚠️ Алдаа: ' + (j.msg || 'Үл мэдэгдэх алдаа'));
            }
        } catch (err) {
            console.error('❌ Error:', err);
            alert('❌ Хадгалахад алдаа: ' + err.message);
        }
    });

    // ➕ Шинэ нэмэх
    document.getElementById('addBtn').addEventListener('click',()=>addModal.show());
    document.getElementById('addForm').addEventListener('submit', async e=>{
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action','add');
        const r = await fetch('doctors.php',{method:'POST',body:fd});
        const j = await r.json();
        if(j.ok){ addModal.hide(); location.reload(); }
        else alert(j.msg || '⚠️ Хадгалах үед алдаа гарлаа.');
    });

    // Add smooth animations and auto-scroll to detail section
    document.addEventListener('DOMContentLoaded', function() {
        const elements = document.querySelectorAll('.animate-fade-in');
        elements.forEach((el, index) => {
            el.style.animationDelay = `${index * 0.1}s`;
        });

        // Auto-scroll to doctor detail section if exists
        const detailSection = document.getElementById('doctorDetailSection');
        if (detailSection) {
            setTimeout(() => {
                detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
    });
    </script>

    <!-- Add Inter Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
</body>
</html