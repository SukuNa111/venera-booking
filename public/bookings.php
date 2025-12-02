<?php
require_once __DIR__ . '/../config.php';
require_role(['admin', 'reception']);

// Get current user and clinic
$currentUser = current_user();
$clinic = $currentUser['clinic_id'] ?? $_SESSION['clinic_id'] ?? 'venera';

// If clinic is 'all', default to first available clinic
if ($clinic === 'all') {
    $firstClinic = db()->query("SELECT code FROM clinics WHERE active=1 ORDER BY name LIMIT 1")->fetchColumn();
    $clinic = $firstClinic ?: 'venera';
}

// sendSMS is provided by config.php (uses Skytel if configured, otherwise logs to sms_log)

// Read settings (lookup scope, send_reminders, clinic name etc.)
$settingsPath = __DIR__ . '/../db/settings.json';
$settings = [];
$patientLookupScope = 'clinic_fallback'; // default
$sendReminders = false;
$clinicName = 'Клиник';
if (file_exists($settingsPath)) {
  $savedSettings = json_decode(file_get_contents($settingsPath), true);
  if (is_array($savedSettings)) {
    $settings = $savedSettings;
    if (isset($savedSettings['patient_lookup_scope'])) {
      $patientLookupScope = $savedSettings['patient_lookup_scope'];
    }
    $sendReminders = !empty($savedSettings['send_reminders']);
    $clinicName = $savedSettings['clinic_name'] ?? $clinicName;
  }
}

// --- POST үйлдэл (JSON) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    // JSON input
    $inputData = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $inputData['action'] ?? '';

    if ($action === 'add') {
  $patient_name = trim($inputData['patient_name'] ?? '');
  $phone = trim($inputData['phone'] ?? '');
  $date = trim($inputData['date'] ?? '');
  $start_time = trim($inputData['start_time'] ?? '');
  $end_time = trim($inputData['end_time'] ?? '');
  $doctor_id = (int)($inputData['doctor_id'] ?? 0);
  $status = $inputData['status'] ?? 'pending';
  $gender = trim($inputData['gender'] ?? '');
  $service_name = trim($inputData['service_name'] ?? '');
  $visit_count = (int)($inputData['visit_count'] ?? 1);
  $note = trim($inputData['note'] ?? '');

        // doctor_id сонголтоор - 0 байж болно
        if ($patient_name && $phone && $date && $start_time && $end_time && $service_name) {
            try {
        $visit_count = max(1, $visit_count);
        $normalizedPhone = preg_replace('/\D+/', '', $phone);
        if ($normalizedPhone !== '') {
          $vs = db()->prepare("SELECT COUNT(*) FROM bookings WHERE clinic=? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','')=?");
          $vs->execute([$clinic, $normalizedPhone]);
          if ((int)$vs->fetchColumn() >= 1) {
            $visit_count = 2;
          }
        }
        $st = db()->prepare("INSERT INTO bookings (patient_name, phone, date, start_time, end_time, doctor_id, clinic, status, gender, service_name, visit_count, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([
          $patient_name,
          $phone,
          $date,
          $start_time,
          $end_time,
          $doctor_id > 0 ? $doctor_id : null,
          $clinic,
          $status,
          $gender,
          $service_name,
          $visit_count,
          $note
        ]);
        
        // Get the last inserted ID for SMS logging
        $booking_id = (int)db()->lastInsertId();
        
        // Send SMS confirmation - ALWAYS send, regardless of sendReminders setting
        $smsMessage = "Та {$patient_name}-ийн \"{$service_name}\" үйлчилгээ {$date} {$start_time}-д захиалсан. Баталгаажлаа. {$clinicName}";
        $smsResult = sendSMS($phone, $smsMessage, $booking_id);
        
        echo json_encode(['ok' => true, 'msg' => 'Захиалга амжилттай нэмэгдлээ', 'sms' => $smsResult]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Нэр, утас, огноо, цаг оруулна уу']);
        }
        exit;
    }

    if ($action === 'update') {
  $id = (int)($inputData['id'] ?? 0);
  $patient_name = trim($inputData['patient_name'] ?? '');
  $phone = trim($inputData['phone'] ?? '');
  $date = trim($inputData['date'] ?? '');
  $start_time = trim($inputData['start_time'] ?? '');
  $end_time = trim($inputData['end_time'] ?? '');
  $doctor_id = (int)($inputData['doctor_id'] ?? 0);
  $status = $inputData['status'] ?? 'pending';
  $gender = trim($inputData['gender'] ?? '');
  $service_name = trim($inputData['service_name'] ?? '');
  $visit_count = (int)($inputData['visit_count'] ?? 1);
  $note = trim($inputData['note'] ?? '');

        // doctor_id сонголтоор - 0 байж болно
        if ($id && $patient_name && $phone && $date && $start_time && $end_time && $service_name) {
            try {
        $visit_count = max(1, $visit_count);
        $normalizedPhone = preg_replace('/\D+/', '', $phone);
        if ($normalizedPhone !== '') {
          $vs = db()->prepare("SELECT COUNT(*) FROM bookings WHERE clinic=? AND id<>? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','')=?");
          $vs->execute([$clinic, $id, $normalizedPhone]);
          if ((int)$vs->fetchColumn() >= 1) {
            $visit_count = 2;
          } else {
            $visit_count = 1;
          }
        }
                $st = db()->prepare("UPDATE bookings SET patient_name=?, phone=?, date=?, start_time=?, end_time=?, doctor_id=?, status=?, gender=?, service_name=?, visit_count=?, note=? WHERE id=?");
                $st->execute([$patient_name, $phone, $date, $start_time, $end_time, $doctor_id > 0 ? $doctor_id : null, $status, $gender, $service_name, $visit_count, $note, $id]);
                
                // Send update notification SMS
                $smsMessage = "Та {$patient_name}-ийн \"{$service_name}\" үйлчилгээний захиалга {$date} {$start_time}-д шинэчлэгдлээ. {$clinicName}";
                $smsResult = sendSMS($phone, $smsMessage, $id);
                
                echo json_encode(['ok' => true, 'msg' => 'Захиалга амжилттай засагдлаа', 'sms' => $smsResult]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Аль нэг талбар алга']);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($inputData['id'] ?? 0);

        if ($id) {
            try {
                $st = db()->prepare("DELETE FROM bookings WHERE id=?");
                $st->execute([$id]);
                echo json_encode(['ok' => true, 'msg' => 'Захиалга амжилттай устгагдлаа']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'ID алга']);
        }
        exit;
    }
}

// --- GET захиалгууд ---
// If admin user has 'all' clinics, show all bookings
$userClinicRaw = $currentUser['clinic_id'] ?? 'venera';
if ($userClinicRaw === 'all') {
    $st = db()->query("
      SELECT 
        b.*, 
        d.name AS doctor_name, 
        d.color AS doctor_color 
      FROM bookings b 
      LEFT JOIN doctors d ON d.id = b.doctor_id 
      ORDER BY b.date DESC, b.start_time DESC
    ");
    $rows = $st->fetchAll();
} else {
    $st = db()->prepare("
      SELECT 
        b.*, 
        d.name AS doctor_name, 
        d.color AS doctor_color 
      FROM bookings b 
      LEFT JOIN doctors d ON d.id = b.doctor_id 
      WHERE b.clinic = ? 
      ORDER BY b.date DESC, b.start_time DESC
    ");
    $st->execute([$clinic]);
    $rows = $st->fetchAll();
}

// --- Эмчид жагсаалт ---
$st = db()->prepare("SELECT * FROM doctors WHERE clinic = ? AND active = 1 ORDER BY name");
$st->execute([$clinic]);
$doctors = $st->fetchAll();

// If no doctors found for this clinic, get all active doctors
if (empty($doctors)) {
    $st = db()->query("SELECT * FROM doctors WHERE active = 1 ORDER BY name");
    $doctors = $st->fetchAll();
}

// --- Статусын тооцоо ---
if ($userClinicRaw === 'all') {
    $statusCounts = db()->query("
      SELECT 
        SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) AS online_count,
        SUM(CASE WHEN status='arrived' THEN 1 ELSE 0 END) AS arrived_count,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count
      FROM bookings
    ");
} else {
    $statusCounts = db()->prepare("
      SELECT 
        SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) AS online_count,
        SUM(CASE WHEN status='arrived' THEN 1 ELSE 0 END) AS arrived_count,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count
      FROM bookings
      WHERE clinic = ?
    ");
    $statusCounts->execute([$clinic]);
}
$statuses = $statusCounts->fetch(PDO::FETCH_ASSOC);
$onlineCount = $statuses['online_count'] ?? 0;
$arrivedCount = $statuses['arrived_count'] ?? 0;
$pendingCount = $statuses['pending_count'] ?? 0;
$cancelledCount = $statuses['cancelled_count'] ?? 0;
?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>📋 Захиалгууд — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
  :root {
    --primary: #6366f1;
    --primary-light: #818cf8;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #06b6d4;
    --dark: #0f172a;
    --glass-bg: rgba(255, 255, 255, 0.85);
    --glass-border: rgba(255, 255, 255, 0.3);
  }
  
  * { box-sizing: border-box; }
  
  body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    background-attachment: fixed;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #1e293b;
    min-height: 100vh;
  }
  
  main {
    margin-left: 250px;
    padding: 2rem;
    min-height: 100vh;
  }
  
  /* Glassmorphism Cards */
  .glass-card {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 24px;
    box-shadow: 
      0 8px 32px rgba(0, 0, 0, 0.1),
      0 2px 8px rgba(0, 0, 0, 0.05),
      inset 0 1px 0 rgba(255, 255, 255, 0.6);
  }
  
  /* Header Section */
  .page-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.95) 0%, rgba(139, 92, 246, 0.95) 100%);
    backdrop-filter: blur(10px);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    color: white;
    box-shadow: 0 20px 40px rgba(99, 102, 241, 0.3);
  }
  
  .page-header h2 {
    font-weight: 800;
    font-size: 2rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }
  
  .page-header .subtitle {
    opacity: 0.9;
    margin-top: 0.5rem;
    font-size: 0.95rem;
  }
  
  /* Stats Cards */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
  }
  
  .stat-card {
    background: var(--glass-bg);
    backdrop-filter: blur(15px);
    border-radius: 16px;
    padding: 1.25rem;
    text-align: center;
    border: 1px solid var(--glass-border);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  }
  
  .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
  }
  
  .stat-card .icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.75rem;
    font-size: 1.25rem;
  }
  
  .stat-card .number {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
  }
  
  .stat-card .label {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 0.25rem;
    font-weight: 500;
  }
  
  /* Filter Section */
  .filter-section {
    background: var(--glass-bg);
    backdrop-filter: blur(15px);
    border-radius: 20px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--glass-border);
  }
  
  .filter-section .form-control,
  .filter-section .form-select {
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    transition: all 0.2s ease;
  }
  
  .filter-section .form-control:focus,
  .filter-section .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
    background: #fff;
  }
  
  .filter-section .form-control::placeholder {
    color: #94a3b8;
  }
  
  /* Table Styling */
  .table-container {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    padding: 1.5rem;
    border: 1px solid var(--glass-border);
    overflow: hidden;
  }
  
  .table {
    margin-bottom: 0;
  }
  
  .table thead th {
    background: linear-gradient(135deg, var(--dark) 0%, #1e293b 100%);
    color: white;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 1rem 1.25rem;
    border: none;
    white-space: nowrap;
  }
  
  .table thead th:first-child { border-radius: 16px 0 0 0; }
  .table thead th:last-child { border-radius: 0 16px 0 0; }
  
  .table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid rgba(226, 232, 240, 0.6);
  }
  
  .table tbody tr:last-child { border-bottom: none; }
  
  .table tbody tr:hover {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);
    transform: scale(1.005);
  }
  
  .table tbody td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    font-size: 0.9rem;
  }
  
  /* Status Badges - Modern Pills */
  .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.9rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  
  .status-online {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
  }
  
  .status-arrived {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
  }
  
  .status-pending {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
  }
  
  .status-paid {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
  }
  
  .status-cancelled {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
  }
  
  /* Service Badge */
  .service-badge {
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4338ca;
    padding: 0.35rem 0.75rem;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
  }
  
  /* Date Badge */
  .date-badge {
    background: rgba(15, 23, 42, 0.08);
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
  }
  
  /* Doctor Chip */
  .doctor-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
  }
  
  .doctor-chip .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  
  /* Action Buttons */
  .btn-action {
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
  }
  
  .btn-edit {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: #78350f;
  }
  
  .btn-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(251, 191, 36, 0.4);
    color: #78350f;
  }
  
  .btn-delete {
    background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
    color: #991b1b;
  }
  
  .btn-delete:hover {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
  }
  
  /* Add Button */
  .btn-add {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.95rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
  }
  
  .btn-add:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(16, 185, 129, 0.4);
    color: white;
  }
  
  /* Clear Filter Button */
  .btn-clear {
    background: rgba(100, 116, 139, 0.1);
    color: #475569;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 1.25rem;
    font-weight: 600;
    transition: all 0.2s ease;
  }
  
  .btn-clear:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
  }
  
  /* Modal Styling */
  .modal-content {
    border: none;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.25);
  }
  
  .modal-header {
    background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
    color: white;
    padding: 1.5rem 2rem;
    border: none;
  }
  
  .modal-header .modal-title {
    font-weight: 700;
    font-size: 1.25rem;
  }
  
  .modal-body {
    padding: 2rem;
    background: #f8fafc;
  }
  
  .modal-body .form-label {
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.5rem;
  }
  
  .modal-body .form-control,
  .modal-body .form-select {
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    transition: all 0.2s ease;
  }
  
  .modal-body .form-control:focus,
  .modal-body .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
  }
  
  .modal-footer {
    background: #fff;
    border-top: 1px solid #e2e8f0;
    padding: 1.25rem 2rem;
  }
  
  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #64748b;
  }
  
  .empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
  }
  
  .empty-state h5 {
    font-weight: 600;
    color: #475569;
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
  
  .glass-card, .stat-card, .filter-section, .table-container {
    animation: fadeInUp 0.5s ease forwards;
  }
  
  /* Responsive */
  @media (max-width: 768px) {
    main {
      margin-left: 0;
      padding: 1rem;
    }
    
    .page-header h2 {
      font-size: 1.5rem;
    }
    
    .stats-row {
      grid-template-columns: repeat(2, 1fr);
    }
  }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <!-- Page Header -->
    <div class="page-header">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
          <h2><i class="fas fa-calendar-check"></i> Захиалгууд</h2>
          <p class="subtitle mb-0">
            <i class="fas fa-chart-line me-1"></i>
            Нийт <strong><?= count($rows) ?></strong> захиалга · <strong><?= count($doctors) ?></strong> эмч
          </p>
        </div>
        <button id="addBtn" class="btn-add">
          <i class="fas fa-plus-circle"></i>
          Шинэ захиалга
        </button>
      </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="icon" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);">
          <i class="fas fa-globe text-success"></i>
        </div>
        <div class="number text-success"><?= $onlineCount ?></div>
        <div class="label">Онлайн</div>
      </div>
      <div class="stat-card">
        <div class="icon" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);">
          <i class="fas fa-user-check text-warning"></i>
        </div>
        <div class="number text-warning"><?= $arrivedCount ?></div>
        <div class="label">Ирсэн</div>
      </div>
      <div class="stat-card">
        <div class="icon" style="background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);">
          <i class="fas fa-clock text-primary"></i>
        </div>
        <div class="number" style="color: var(--primary);"><?= $pendingCount ?></div>
        <div class="label">Хүлээгдэж буй</div>
      </div>
      <div class="stat-card">
        <div class="icon" style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);">
          <i class="fas fa-times-circle text-danger"></i>
        </div>
        <div class="number text-danger"><?= $cancelledCount ?></div>
        <div class="label">Цуцалсан</div>
      </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
      <div class="row g-3 align-items-end">
        <div class="col-lg-3 col-md-6">
          <label class="form-label fw-semibold text-muted small">
            <i class="fas fa-search me-1"></i>Өвчтөн хайх
          </label>
          <input type="text" id="filterPatient" class="form-control" placeholder="Нэр эсвэл үйлчилгээ...">
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label fw-semibold text-muted small">
            <i class="fas fa-calendar me-1"></i>Огноо
          </label>
          <input type="date" id="filterDate" class="form-control">
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label fw-semibold text-muted small">
            <i class="fas fa-tag me-1"></i>Статус
          </label>
          <select id="filterStatus" class="form-select">
            <option value="">Бүгд</option>
            <option value="pending">Хүлээгдэж буй</option>
            <option value="online">Онлайн</option>
            <option value="arrived">Ирсэн</option>
            <option value="paid">Төлөгдсөн</option>
            <option value="cancelled">Цуцалсан</option>
          </select>
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label fw-semibold text-muted small">
            <i class="fas fa-user-md me-1"></i>Эмч
          </label>
          <select id="filterDoctor" class="form-select">
            <option value="">Бүх эмч</option>
            <?php foreach ($doctors as $doc): ?>
              <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-3 col-md-12">
          <button id="clearFilter" class="btn btn-clear w-100">
            <i class="fas fa-redo me-2"></i>Цэвэрлэх
          </button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="table-container">
      <div class="table-responsive">
        <table class="table" id="bookingsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Огноо</th>
              <th>Эмч</th>
              <th>Цаг</th>
              <th>Өвчтөн</th>
              <th>Үйлчилгээ</th>
              <th>Утас</th>
              <th>Статус</th>
              <th class="text-end">Үйлдэл</th>
            </tr>
          </thead>
          <tbody id="bookingsBody">
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h5>Захиалга байхгүй байна</h5>
                    <p>Шинэ захиалга нэмэхийн тулд дээрх товчийг дарна уу</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $i => $r): ?>
                <tr 
                  data-date="<?= htmlspecialchars($r['date']) ?>" 
                  data-status="<?= htmlspecialchars($r['status']) ?>" 
                  data-doctor="<?= $r['doctor_id'] ?>" 
                  data-patient="<?= htmlspecialchars(mb_strtolower($r['patient_name'])) ?>" 
                  data-service="<?= htmlspecialchars(mb_strtolower($r['service_name'] ?? '')) ?>">
                  <td><strong><?= $i+1 ?></strong></td>
                  <td>
                    <span class="date-badge"><?= htmlspecialchars($r['date']) ?></span>
                  </td>
                  <td>
                    <div class="doctor-chip">
                      <span class="dot" style="background:<?= htmlspecialchars($r['doctor_color'] ?? '#6366f1') ?>;"></span>
                      <?= htmlspecialchars($r['doctor_name'] ?? '—') ?>
                    </div>
                  </td>
                  <td>
                    <i class="fas fa-clock text-muted me-1"></i>
                    <?= htmlspecialchars($r['start_time']) ?> - <?= htmlspecialchars($r['end_time']) ?>
                  </td>
                  <td><strong><?= htmlspecialchars($r['patient_name']) ?></strong></td>
                  <td>
                    <?php if (!empty($r['service_name'])): ?>
                      <span class="service-badge"><?= htmlspecialchars($r['service_name']) ?></span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <i class="fas fa-phone text-muted me-1"></i>
                    <?= htmlspecialchars($r['phone']) ?>
                  </td>
                  <td>
                    <?php
                      $statusLabels = [
                        'pending' => ['Хүлээгдэж буй', 'status-pending', 'fa-hourglass-half'],
                        'online' => ['Онлайн', 'status-online', 'fa-globe'],
                        'arrived' => ['Ирсэн', 'status-arrived', 'fa-user-check'],
                        'paid' => ['Төлөгдсөн', 'status-paid', 'fa-check-circle'],
                        'cancelled' => ['Цуцалсан', 'status-cancelled', 'fa-times-circle']
                      ];
                      $st = $statusLabels[$r['status']] ?? ['Тодорхойгүй', 'status-pending', 'fa-question'];
                    ?>
                    <span class="status-badge <?= $st[1] ?>">
                      <i class="fas <?= $st[2] ?>"></i>
                      <?= $st[0] ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex gap-2 justify-content-end">
                      <button class="btn-action btn-edit edit-btn" 
                        data-id="<?= $r['id'] ?>" 
                        data-name="<?= htmlspecialchars($r['patient_name']) ?>" 
                        data-phone="<?= htmlspecialchars($r['phone']) ?>" 
                        data-date="<?= htmlspecialchars($r['date']) ?>"
                        data-start="<?= htmlspecialchars($r['start_time']) ?>"
                        data-end="<?= htmlspecialchars($r['end_time']) ?>"
                        data-doctor="<?= $r['doctor_id'] ?>"
                        data-status="<?= htmlspecialchars($r['status']) ?>"
                        data-gender="<?= htmlspecialchars($r['gender'] ?? '') ?>"
                        data-service="<?= htmlspecialchars($r['service_name'] ?? '') ?>"
                        data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                        data-visit_count="<?= htmlspecialchars($r['visit_count'] ?? 1) ?>">
                        <i class="fas fa-pen"></i>
                        Засах
                      </button>
                      <button class="btn-action btn-delete delete-btn" data-id="<?= $r['id'] ?>" data-name="<?= htmlspecialchars($r['patient_name']) ?>">
                        <i class="fas fa-trash"></i>
                        Устгах
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
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
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Шинэ захиалга</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">Өвчтөний нэр <span class="text-danger">*</span></label>
              <input type="text" name="patient_name" class="form-control" placeholder="Жишээ: Батболд" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Хүйс</label>
              <select name="gender" class="form-select">
                <option value="">—</option>
                <option value="male">Эр</option>
                <option value="female">Эм</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Утас <span class="text-danger">*</span></label>
            <input type="tel" name="phone" class="form-control" placeholder="89370128" required>
          </div>
          <input type="hidden" name="visit_count" value="1">
          <div class="mb-3">
            <label class="form-label">Үйлчилгээ <span class="text-danger">*</span></label>
            <input type="text" name="service_name" class="form-control" placeholder="Жишээ: Шүдний эмчилгээ" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Тэмдэглэл</label>
            <textarea name="note" class="form-control" rows="2" placeholder="Нэмэлт мэдээлэл..."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Огноо <span class="text-danger">*</span></label>
            <input type="date" name="date" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Эхлэх <span class="text-danger">*</span></label>
              <input type="time" name="start_time" class="form-control" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Дуусах <span class="text-danger">*</span></label>
              <input type="time" name="end_time" class="form-control" required>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Эмч</label>
              <select name="doctor_id" class="form-select">
                <option value="0">— Эмч сонгоогүй —</option>
                <?php foreach ($doctors as $doc): ?>
                  <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Статус</label>
              <select name="status" class="form-select">
                <option value="pending">Хүлээгдэж буй</option>
                <option value="online">Онлайн</option>
                <option value="arrived">Ирсэн</option>
                <option value="paid">Төлөгдсөн</option>
                <option value="cancelled">Цуцалсан</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i>Цуцлах
          </button>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-check me-1"></i>Хадгалах
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal — Засах -->
  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="editForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Захиалга засах</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id">
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">Өвчтөний нэр <span class="text-danger">*</span></label>
              <input type="text" name="patient_name" class="form-control" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Хүйс</label>
              <select name="gender" class="form-select">
                <option value="">—</option>
                <option value="male">Эр</option>
                <option value="female">Эм</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Утас <span class="text-danger">*</span></label>
            <input type="tel" name="phone" class="form-control" required>
          </div>
          <input type="hidden" name="visit_count" value="1">
          <div class="mb-3">
            <label class="form-label">Үйлчилгээ <span class="text-danger">*</span></label>
            <input type="text" name="service_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Тэмдэглэл</label>
            <textarea name="note" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Огноо <span class="text-danger">*</span></label>
            <input type="date" name="date" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Эхлэх <span class="text-danger">*</span></label>
              <input type="time" name="start_time" class="form-control" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Дуусах <span class="text-danger">*</span></label>
              <input type="time" name="end_time" class="form-control" required>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Эмч</label>
              <select name="doctor_id" class="form-select">
                <option value="0">— Эмч сонгоогүй —</option>
                <?php foreach ($doctors as $doc): ?>
                  <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Статус</label>
              <select name="status" class="form-select">
                <option value="pending">Хүлээгдэж буй</option>
                <option value="online">Онлайн</option>
                <option value="arrived">Ирсэн</option>
                <option value="paid">Төлөгдсөн</option>
                <option value="cancelled">Цуцалсан</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i>Цуцлах
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Хадгалах
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const addModal = new bootstrap.Modal(document.getElementById('addModal'));
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));

    document.getElementById('addBtn').addEventListener('click', () => {
      document.getElementById('addForm').reset();
      // Set default date to today
      document.querySelector('#addForm input[name="date"]').value = new Date().toISOString().split('T')[0];
      addModal.show();
    });

    document.getElementById('addForm').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target;
      const btn = f.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Хадгалж байна...';
      
      const serviceName = f.querySelector('input[name="service_name"]').value.trim();
      if (!serviceName) {
        showToast('Үйлчилгээний нэрийг оруулна уу', 'warning');
        f.querySelector('input[name="service_name"]').focus();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Хадгалах';
        return;
      }

      const payload = {
        action: 'add',
        patient_name: f.querySelector('input[name="patient_name"]').value,
        phone: f.querySelector('input[name="phone"]').value,
        gender: f.querySelector('select[name="gender"]').value,
        service_name: serviceName,
        note: f.querySelector('textarea[name="note"]').value,
        date: f.querySelector('input[name="date"]').value,
        start_time: f.querySelector('input[name="start_time"]').value,
        end_time: f.querySelector('input[name="end_time"]').value,
        doctor_id: parseInt(f.querySelector('select[name="doctor_id"]').value) || 0,
        status: f.querySelector('select[name="status"]').value,
        visit_count: parseInt(f.querySelector('input[name="visit_count"]') ? f.querySelector('input[name="visit_count"]').value : 1) || 1
      };

      try {
        const r = await fetch('bookings.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const j = await r.json();
        if (j.ok) {
          showToast(j.msg, 'success');
          setTimeout(() => location.reload(), 1000);
        } else {
          showToast(j.msg || 'Алдаа гарлаа', 'danger');
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-check me-1"></i>Хадгалах';
        }
      } catch (err) {
        showToast('Сүлжээний алдаа: ' + err.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Хадгалах';
      }
    });

    document.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelector('#editForm input[name="id"]').value = btn.dataset.id;
        document.querySelector('#editForm input[name="patient_name"]').value = btn.dataset.name;
        document.querySelector('#editForm input[name="phone"]').value = btn.dataset.phone;
        document.querySelector('#editForm input[name="date"]').value = btn.dataset.date;
        document.querySelector('#editForm input[name="start_time"]').value = btn.dataset.start;
        document.querySelector('#editForm input[name="end_time"]').value = btn.dataset.end;
        document.querySelector('#editForm select[name="doctor_id"]').value = btn.dataset.doctor;
        document.querySelector('#editForm select[name="status"]').value = btn.dataset.status;
        document.querySelector('#editForm select[name="gender"]').value = btn.dataset.gender || '';
        document.querySelector('#editForm input[name="service_name"]').value = btn.dataset.service || '';
        document.querySelector('#editForm textarea[name="note"]').value = btn.dataset.note || '';
        if (document.querySelector('#editForm input[name="visit_count"]')) document.querySelector('#editForm input[name="visit_count"]').value = btn.dataset.visit_count || 1;
        editModal.show();
      });
    });

    document.getElementById('editForm').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target;
      const btn = f.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Хадгалж байна...';
      
      const serviceName = f.querySelector('input[name="service_name"]').value.trim();
      if (!serviceName) {
        showToast('Үйлчилгээний нэрийг оруулна уу', 'warning');
        f.querySelector('input[name="service_name"]').focus();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Хадгалах';
        return;
      }

      const payload = {
        action: 'update',
        id: parseInt(f.querySelector('input[name="id"]').value),
        patient_name: f.querySelector('input[name="patient_name"]').value,
        phone: f.querySelector('input[name="phone"]').value,
        gender: f.querySelector('select[name="gender"]').value,
        service_name: serviceName,
        note: f.querySelector('textarea[name="note"]').value,
        date: f.querySelector('input[name="date"]').value,
        start_time: f.querySelector('input[name="start_time"]').value,
        end_time: f.querySelector('input[name="end_time"]').value,
        doctor_id: parseInt(f.querySelector('select[name="doctor_id"]').value) || 0,
        status: f.querySelector('select[name="status"]').value,
        visit_count: parseInt(f.querySelector('input[name="visit_count"]') ? f.querySelector('input[name="visit_count"]').value : 1) || 1
      };

      try {
        const r = await fetch('bookings.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const j = await r.json();
        if (j.ok) {
          showToast(j.msg, 'success');
          setTimeout(() => location.reload(), 1000);
        } else {
          showToast(j.msg || 'Алдаа гарлаа', 'danger');
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-save me-1"></i>Хадгалах';
        }
      } catch (err) {
        showToast('Сүлжээний алдаа: ' + err.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Хадгалах';
      }
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
      
      // Add toast styles
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
          box-shadow: 0 8px 30px rgba(0,0,0,0.2);
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

    // 🔍 Фильтр функц
    function applyFilters() {
      const patientFilter = document.getElementById('filterPatient').value.toLowerCase();
      const dateFilter = document.getElementById('filterDate').value;
      const statusFilter = document.getElementById('filterStatus').value;
      const doctorFilter = document.getElementById('filterDoctor').value;

      const rows = document.querySelectorAll('#bookingsBody tr:not(.empty-row)');
      let visibleCount = 0;

      rows.forEach(row => {
        if (!row.dataset.date) return; // Skip non-data rows
        let show = true;

        if (patientFilter && !row.dataset.patient.includes(patientFilter) && !(row.dataset.service || '').includes(patientFilter)) {
          show = false;
        }
        if (dateFilter && row.dataset.date !== dateFilter) {
          show = false;
        }
        if (statusFilter && row.dataset.status !== statusFilter) {
          show = false;
        }
        if (doctorFilter && row.dataset.doctor !== doctorFilter) {
          show = false;
        }

        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
      });

      // Show/hide empty state
      let emptyRow = document.querySelector('.empty-row');
      if (visibleCount === 0 && rows.length > 0) {
        if (!emptyRow) {
          const tbody = document.getElementById('bookingsBody');
          const tr = document.createElement('tr');
          tr.className = 'empty-row';
          tr.innerHTML = '<td colspan="9"><div class="empty-state"><i class="fas fa-search"></i><h5>Хайлтад тохирох үр дүн олдсонгүй</h5><p>Шүүлтүүрийг өөрчилж дахин хайна уу</p></div></td>';
          tbody.appendChild(tr);
        }
      } else if (emptyRow) {
        emptyRow.remove();
      }
    }

    // Фильтр events
    document.getElementById('filterPatient').addEventListener('keyup', applyFilters);
    document.getElementById('filterDate').addEventListener('change', applyFilters);
    document.getElementById('filterStatus').addEventListener('change', applyFilters);
    document.getElementById('filterDoctor').addEventListener('change', applyFilters);

    document.getElementById('clearFilter').addEventListener('click', () => {
      document.getElementById('filterPatient').value = '';
      document.getElementById('filterDate').value = '';
      document.getElementById('filterStatus').value = '';
      document.getElementById('filterDoctor').value = '';
      applyFilters();
    });

    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm(`"${btn.dataset.name}" захиалгыг устгах уу?`)) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
          const r = await fetch('bookings.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete', id: parseInt(btn.dataset.id) })
          });
          const j = await r.json();
          if (j.ok) {
            showToast(j.msg, 'success');
            btn.closest('tr').style.opacity = '0';
            setTimeout(() => location.reload(), 800);
          } else {
            showToast(j.msg || 'Алдаа гарлаа', 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Устгах';
          }
        } catch (err) {
          showToast('Сүлжээний алдаа', 'danger');
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-trash"></i> Устгах';
        }
      });
    });

    // Patient lookup
    const clinicSlug = <?= json_encode($clinic) ?>;
    const patientLookupScope = <?= json_encode($patientLookupScope) ?>;
    let addPhoneTimer = null;
    let editPhoneTimer = null;

    async function lookupPatient(phone, formSelector = '#addForm') {
      if (!phone) return;
      try {
        const normalized = phone.replace(/\D+/g, '');
        if (normalized.length < 7) return;
        const url = `./api.php?action=patient_info&clinic=${encodeURIComponent(clinicSlug)}&phone=${encodeURIComponent(normalized)}&_=${Date.now()}`;
        const r = await fetch(url);
        const j = await r.json();
        if (!j?.ok || !j?.data) return;
        const data = j.data;
        const f = document.querySelector(formSelector);
        if (!f) return;
        if (data.patient_name) f.querySelector('[name="patient_name"]').value = data.patient_name;
        if (data.service_name) {
          const svc = f.querySelector('[name="service_name"]');
          if (svc) svc.value = data.service_name;
        }
        if (data.gender) {
          const g = (data.gender||'').toString().toLowerCase();
          if (g.match(/эм|female|woman|girl/)) f.querySelector('[name="gender"]').value = 'female';
          else if (g.match(/эр|male|man|boy/)) f.querySelector('[name="gender"]').value = 'male';
        }
        if (data.note) f.querySelector('[name="note"]').value = data.note;
        if (data.visits && f.querySelector('[name="visit_count"]')) {
          f.querySelector('[name="visit_count"]').value = data.visits > 1 ? data.visits : 1;
        }
        
        if (data.is_global_match && data.source_clinic) {
          showToast(`Өгөгдөл ${data.source_clinic} эмнэлгээс олдлоо`, 'info');
        }
      } catch (e) {
        console.error('Patient lookup error', e);
      }
    }

    const addPhone = document.querySelector('#addForm [name="phone"]');
    if (addPhone) {
      addPhone.addEventListener('blur', e => lookupPatient(e.target.value.trim(), '#addForm'));
      addPhone.addEventListener('input', e => {
        const phone = e.target.value.trim();
        if (addPhoneTimer) clearTimeout(addPhoneTimer);
        if (phone.length >= 4) {
          addPhoneTimer = setTimeout(() => lookupPatient(phone, '#addForm'), 500);
        }
      });
    }

    const editPhone = document.querySelector('#editForm [name="phone"]');
    if (editPhone) {
      editPhone.addEventListener('blur', e => lookupPatient(e.target.value.trim(), '#editForm'));
      editPhone.addEventListener('input', e => {
        const phone = e.target.value.trim();
        if (editPhoneTimer) clearTimeout(editPhoneTimer);
        if (phone.length >= 4) {
          editPhoneTimer = setTimeout(() => lookupPatient(phone, '#editForm'), 500);
        }
      });
    }
  </script>
</body>
</html>
