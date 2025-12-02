<?php
require_once __DIR__ . '/../config.php';
require_login();

// Determine current user and their clinic/role.  Doctors should only see their own clinic.
$user      = current_user();
$clinic_id = $user['clinic_id'] ?? 'venera';
$role      = $user['role'] ?? '';

// Load active clinics from the database for the clinic selector.  If the query fails
// (e.g. missing table) fall back to the predefined list.
$clinicOpts = [];
try {
    $st = db()->prepare("SELECT code, name FROM clinics WHERE active = 1 ORDER BY COALESCE(sort_order,0), id");
    $st->execute();
    $clinicOpts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    // fallback to default clinics when DB is unavailable
    $clinicOpts = [
        ['code' => 'venera', 'name' => 'Венера'],
        ['code' => 'luxor',  'name' => 'Голден Луксор'],
        ['code' => 'khatan', 'name' => 'Гоо Хатан'],
    ];
}

// Doctors are locked to their clinic – hide the clinic selector and disable changes.
$isRestricted = ($role === 'doctor' || $role === 'reception');

// Count total active doctors
$doctorCount = db()->query("SELECT COUNT(id) FROM doctors WHERE active = 1")->fetchColumn();

// Get all doctors for this clinic (for export list)
$doctorsForClinic = [];
$inactiveDoctors = [];
try {
    $st = db()->prepare("SELECT id, name, show_in_calendar FROM doctors WHERE clinic = ? AND active = 1 ORDER BY name");
    $st->execute([$clinic_id]);
    $doctorsForClinic = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Get working hours for each doctor
    foreach ($doctorsForClinic as &$doc) {
        $wh = db()->prepare("SELECT day_of_week, start_time, end_time, is_available FROM working_hours WHERE doctor_id = ? ORDER BY day_of_week");
        $wh->execute([$doc['id']]);
        $doc['working_hours'] = $wh->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $st2 = db()->prepare("SELECT id, name FROM doctors WHERE clinic = ? AND active = 0 ORDER BY name");
    $st2->execute([$clinic_id]);
    $inactiveDoctors = $st2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $doctorsForClinic = [];
    $inactiveDoctors = [];
}

// Load application settings to apply theme colours and default view.  These
// values come from `db/settings.json` which can be edited via the Smart
// Settings page.  When the file is missing or malformed, sensible
// defaults are used.
$settingsPath = __DIR__ . '/../db/settings.json';
$settingsDefault = [
    'theme_color'    => '#3b82f6',
    'secondary_color'=> '#8b5cf6',
    'default_view'   => 'week'
];
$settings = $settingsDefault;
if (file_exists($settingsPath)) {
    $saved = json_decode(@file_get_contents($settingsPath), true);
    if (is_array($saved)) {
        $settings = array_merge($settingsDefault, $saved);
    }
}
$primaryColor   = $settings['theme_color']    ?? $settingsDefault['theme_color'];
$secondaryColor = $settings['secondary_color']?? $settingsDefault['secondary_color'];
$defaultView    = $settings['default_view']   ?? $settingsDefault['default_view'];
?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>🗓 Захиалгын календарь — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --hour-h: 80px;
      --primary: <?= htmlspecialchars($primaryColor) ?>;
      --secondary: <?= htmlspecialchars($secondaryColor) ?>;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1e293b;
      --light: #f8fafc;
      --border: #e2e8f0;
      --radius: 16px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
      background-attachment: fixed;
      font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      color: #1e293b;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    main {
      margin-left: 250px;
      padding: 2rem 2.5rem;
      flex: 1;
      margin-bottom: 60px;
    }

    /* Toolbar */
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: center;
      margin-bottom: 1.5rem;
      background: white;
      padding: 1.25rem 1.5rem;
      border-radius: var(--radius);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid var(--border);
    }

    .toolbar .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 220px;
    }

    .toolbar .user-avatar {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 700;
      font-size: 18px;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    }

    .toolbar .user-details {
      display: flex;
      flex-direction: column;
    }

    .toolbar .user-details .welcome {
      font-size: 0.85rem;
      color: #64748b;
    }

    .toolbar .user-details .name {
      font-weight: 700;
      color: #1e293b;
    }

    .toolbar h4 {
      margin: 0;
      font-weight: 700;
      font-size: 1.1rem;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .toolbar h4 strong {
      color: var(--primary);
    }

    /* Buttons */
    .btn {
      border-radius: 10px;
      padding: 0.6rem 1.2rem;
      font-weight: 600;
      font-size: 0.9rem;
      border: none;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .btn-outline-secondary {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    .btn-outline-secondary:hover {
      background: #e2e8f0;
      color: #1e293b;
    }

    .btn-outline-primary {
      background: #ede9fe;
      color: var(--primary);
      border: 1px solid #c4b5fd;
    }

    .btn-outline-primary:hover {
      background: var(--primary);
      color: white;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    }

    .btn-primary:hover {
      box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
      transform: translateY(-2px);
    }

    .btn-group {
      display: flex;
      gap: 0;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
    }

    .btn-group .btn {
      background: transparent;
      color: #64748b;
      border: none;
      border-radius: 8px;
      padding: 0.5rem 1rem;
    }

    .btn-group .btn:hover,
    .btn-group .btn.active {
      background: white;
      color: var(--primary);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .form-select-sm {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font-weight: 500;
      background: white;
      color: #1e293b;
      padding: 0.6rem 1rem;
    }

    .form-select-sm:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    /* Calendar Container */
    .calendar-wrap {
      display: flex;
      background: white;
      border-radius: var(--radius);
      overflow: hidden;
      min-height: 75vh;
      box-shadow: 0 4px 25px rgba(0, 0, 0, 0.06);
      border: 1px solid var(--border);
    }

    #timeCol {
      width: 70px;
      background: #f8fafc;
      border-right: 1px solid #e2e8f0;
      overflow-y: auto;
      text-align: right;
      padding-right: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      color: #64748b;
    }

    #timeCol div {
      height: var(--hour-h);
      border-bottom: 1px solid #f1f5f9;
      padding-top: 4px;
    }

    #calendarRow {
      flex: 1;
      display: flex;
      overflow-x: auto;
      overflow-y: hidden;
    }

    .calendar-col {
      border-right: 1px solid #e2e8f0;
      background: #fafafa;
      display: flex;
      flex-direction: column;
      min-width: 180px;
      flex: 1;
      position: relative;
    }

    .calendar-col:last-child {
      border-right: none;
    }

    .calendar-col .head {
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      padding: 1rem;
      font-weight: 700;
      border-bottom: 2px solid #e2e8f0;
      font-size: 0.95rem;
      position: sticky;
      top: 0;
      z-index: 5;
      text-align: center;
      color: #1e293b;
    }

    .calendar-col .head i {
      color: var(--primary);
      margin-right: 0.5rem;
    }

    .calendar-col .head .work-hours {
      display: inline-block;
      font-size: 0.75rem;
      padding: 0.25rem 0.6rem;
      border-radius: 50px;
      background: #dcfce7;
      color: #16a34a;
      font-weight: 600;
      margin-top: 0.25rem;
      border: 1px solid #86efac;
    }

    .calendar-hours {
      position: relative;
      overflow-y: auto;
      flex: 1;
    }

    .calendar-grid {
      position: absolute;
      inset: 0;
      background: transparent;
      pointer-events: none;
    }

    /* Events */
    .event {
      position: absolute;
      left: 6px;
      right: 6px;
      border-left: 4px solid;
      padding: 0.5rem 0.6rem;
      font-size: 0.8rem;
      border-radius: 8px;
      cursor: pointer;
      overflow: hidden;
      transition: all 0.2s ease;
      z-index: 2;
      display: flex;
      flex-direction: column;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .event:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    }

    .event strong {
      display: block;
      font-weight: 700;
      margin-bottom: 0.2rem;
      font-size: 0.85rem;
    }

    .event small {
      line-height: 1.3;
      opacity: 0.9;
    }

    .event.online {
      border-left-color: #3b82f6;
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      color: #1e40af;
    }

    .event.arrived {
      border-left-color: #f59e0b;
      background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
      color: #92400e;
    }

    .event.paid {
      border-left-color: #10b981;
      background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
      color: #065f46;
    }

    .event.pending {
      border-left-color: #8b5cf6;
      background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
      color: #5b21b6;
    }

    .event.cancelled {
      border-left-color: #ef4444;
      background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
      color: #991b1b;
    }

    /* Status Legend */
    .status-legend {
      display: flex;
      justify-content: flex-start;
      align-items: center;
      gap: 1.5rem;
      margin-top: 1.25rem;
      padding: 1rem 1.5rem;
      background: white;
      border: 1px solid var(--border);
      border-radius: 12px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
      flex-wrap: wrap;
    }

    .status-badge {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
      font-weight: 600;
      color: #475569;
    }

    .status-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      display: inline-block;
      box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
    }

    /* Modal Styling */
    .modal-content {
      border: none;
      border-radius: 20px;
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      background: white;
      color: #1e293b;
    }

    .modal-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      border: none;
      padding: 1.5rem 2rem;
    }

    .modal-title {
      font-weight: 700;
      font-size: 1.15rem;
    }

    .btn-close {
      filter: brightness(0) invert(1);
    }

    .modal-body {
      padding: 2rem;
    }

    .form-label {
      font-weight: 600;
      color: #374151;
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
    }

    .form-control,
    .form-select {
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      background: #f9fafb;
      color: #1f2937;
      transition: all 0.2s ease;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
      background: white;
    }

    .form-control::placeholder {
      color: #9ca3af;
    }

    .modal-footer {
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      padding: 1.25rem 2rem;
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
      color: white;
      box-shadow: 0 4px 15px rgba(239, 68, 68, 0.25);
    }

    .btn-danger:hover {
      box-shadow: 0 8px 25px rgba(239, 68, 68, 0.35);
      transform: translateY(-2px);
    }

    .btn-secondary {
      background: white;
      border: 2px solid #e5e7eb;
      color: #4b5563;
    }

    .btn-secondary:hover {
      background: #f3f4f6;
      border-color: #d1d5db;
    }

    /* Month View */
    #calendarRow.month-view {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      grid-auto-rows: minmax(150px, 1fr);
      gap: 8px;
      padding: 12px;
      width: 100%;
      height: auto;
      overflow-y: auto;
      background: #f8fafc;
    }

    .month-cell {
      background: white;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      padding: 12px;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      transition: all 0.2s ease;
      position: relative;
    }

    .month-cell:hover {
      transform: scale(1.02);
      border-color: var(--primary);
      box-shadow: 0 4px 15px rgba(99, 102, 241, 0.15);
    }

    .month-cell strong {
      font-weight: 700;
      font-size: 0.95rem;
      color: #1e293b;
      margin-bottom: 8px;
    }

    .month-cell small {
      color: #64748b;
      font-size: 0.8rem;
      line-height: 1.3;
    }

    .month-event {
      margin-top: 6px;
      background: #f1f5f9;
      border-left: 3px solid var(--primary);
      border-radius: 6px;
      padding: 4px 8px;
      font-size: 0.75rem;
      color: #475569;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      transition: all 0.2s ease;
    }

    .month-event:hover {
      background: #e2e8f0;
      transform: translateX(2px);
    }

    /* Animations */
    @keyframes phonePulse {
      0% {
        box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.45);
        border-color: var(--primary);
      }
      70% {
        box-shadow: 0 0 0 8px rgba(99, 102, 241, 0);
        border-color: var(--secondary);
      }
      100% {
        box-shadow: 0 0 0 0 rgba(99, 102, 241, 0);
        border-color: var(--primary);
      }
    }

    .phone-reminder {
      animation: phonePulse 1.4s ease-in-out 2;
    }

    .input-error {
      border-color: #f97316 !important;
      box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.15) !important;
    }

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

    .toolbar, .calendar-wrap, .status-legend {
      animation: fadeInUp 0.5s ease forwards;
    }

    /* Responsive */
    @media (max-width: 992px) {
      main {
        margin-left: 0;
        padding: 1.5rem;
      }
    }

  </style>
</head>
<body>

  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <div class="toolbar">
      <div class="user-info">
        <div class="user-avatar">
          <i class="fas fa-tooth"></i>
        </div>
        <div class="user-details">
          <span class="welcome">Тавтай морилно уу</span>
          <span class="name"><?= htmlspecialchars($user['name'] ?? '') ?></span>
        </div>
      </div>
      
      <h4 id="dateLabel">
        <span style="color: #64748b;">Өдөр:</span> <strong>2025-12-02</strong>
      </h4>
      
      <button id="prev" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-chevron-left me-1"></i>Өмнөх
      </button>
      <button id="today" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-calendar-day me-1"></i>Өнөөдөр
      </button>
      <button id="next" class="btn btn-outline-secondary btn-sm">
        Дараах<i class="fas fa-chevron-right ms-1"></i>
      </button>
      
      <div class="ms-auto btn-group">
        <button id="viewDay" class="btn btn-sm">
          <i class="fas fa-calendar-day me-1"></i> Өдөр
        </button>
        <button id="viewWeek" class="btn btn-sm">
          <i class="fas fa-calendar-week me-1"></i> 7 хоног
        </button>
        <button id="viewMonth" class="btn btn-sm">
          <i class="fas fa-calendar-alt me-1"></i> Сар
        </button>
      </div>
      
      <select id="clinic" class="form-select form-select-sm ms-2 <?= $isRestricted ? 'd-none' : '' ?>" style="width:auto;" <?= $isRestricted ? 'disabled' : '' ?>>
        <?php foreach ($clinicOpts as $opt): ?>
          <option value="<?= htmlspecialchars($opt['code']) ?>"
            <?= $clinic_id === $opt['code'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($opt['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      
      <button class="btn btn-primary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalAdd">
        <i class="fas fa-plus me-2"></i>Шинэ захиалга
      </button>
    </div>

    <div class="calendar-wrap">
      <div id="timeCol"></div>
      <div id="calendarRow"></div>
    </div>

    <div class="status-legend">
      <div class="status-badge">
        <div class="status-dot" style="background: #3b82f6;"></div>
        <span>Онлайн</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #f59e0b;"></div>
        <span>Ирсэн</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #10b981;"></div>
        <span>Төлсөн</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #8b5cf6;"></div>
        <span>Хүлээгдэж буй</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #ef4444;"></div>
        <span>Цуцлагдсан</span>
      </div>
    </div>
  </main>

  <!-- ➕ ADD MODAL -->
  <div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <form id="addForm" class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">
            <i class="fas fa-calendar-plus me-2"></i>Шинэ захиалга нэмэх
          </h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="clinic" id="clinic_in">

          <div class="col-md-4">
            <label class="form-label">Эмч</label>
            <select name="doctor_id" id="doctor_id" class="form-select" required></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Огноо</label>
            <input type="date" name="date" id="date" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Эхлэх</label>
            <input type="time" name="start_time" id="start_time" class="form-control" value="09:00" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Дуусах</label>
            <input type="time" name="end_time" id="end_time" class="form-control" value="09:30" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Өвчтөний нэр</label>
            <input type="text" name="patient_name" id="patient_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Үйлчилгээ <span class="text-warning fw-semibold">*</span></label>
            <input type="text" name="service_name" id="service_name" class="form-control" placeholder="Жишээ: Botox, Filler гэх мэт" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Хүйс</label>
            <select name="gender" id="gender" class="form-select">
              <option value="">Сонгох...</option>
              <option value="female">Эмэгтэй</option>
              <option value="male">Эрэгтэй</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Давтамж</label>
            <select name="visit_count" id="visit_count" class="form-select">
              <option value="1">1. Анх удаа</option>
              <option value="2">2. Давтан</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Утас <span class="text-warning fw-semibold">*</span></label>
            <input type="tel" name="phone" id="phone" class="form-control" placeholder="Утасны дугаараа оруулна уу" required>
          </div>

          <div class="col-12">
            <label class="form-label">Тэмдэглэл</label>
            <textarea name="note" id="note" class="form-control" rows="2"></textarea>
          </div>

          <div class="col-md-6">
            <label class="form-label">Статус</label>
            <select name="status" id="status" class="form-select">
              <option value="online">Онлайн</option>
              <option value="arrived">Ирсэн</option>
              <option value="paid">Төлбөр хийгдсэн</option>
              <option value="pending">Хүлээгдэж буй</option>
              <option value="cancelled">Цуцлагдсан</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-primary" type="submit">
            <i class="fas fa-save me-2"></i>Хадгалах
          </button>
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Болих
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ✏️ EDIT MODAL -->
  <div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <form id="editForm" class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">
            <i class="fas fa-edit me-2"></i>Захиалга засах
          </h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="id">
          <input type="hidden" name="clinic">

          <div class="col-md-4">
            <label class="form-label">Эмч</label>
            <select name="doctor_id" class="form-select" required></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Огноо</label>
            <input type="date" name="date" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Эхлэх</label>
            <input type="time" name="start_time" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Дуусах</label>
            <input type="time" name="end_time" class="form-control" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Өвчтөний нэр</label>
            <input type="text" name="patient_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Үйлчилгээ <span class="text-warning fw-semibold">*</span></label>
            <input type="text" name="service_name" class="form-control" placeholder="Жишээ: Botox, Filler гэх мэт" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Хүйс</label>
            <select name="gender" class="form-select">
              <option value="">Сонгох...</option>
              <option value="female">Эмэгтэй</option>
              <option value="male">Эрэгтэй</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Давтамж</label>
            <select name="visit_count" class="form-select">
              <option value="1">1. Анх удаа</option>
              <option value="2">2. Давтан</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Утас <span class="text-warning fw-semibold">*</span></label>
            <input type="tel" name="phone" class="form-control" placeholder="Утасны дугаараа оруулна уу" required>
          </div>

          <div class="col-12">
            <label class="form-label">Тэмдэглэл</label>
            <textarea name="note" class="form-control" rows="2"></textarea>
          </div>

          <div class="col-md-6">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
              <option value="online">Онлайн</option>
              <option value="arrived">Ирсэн</option>
              <option value="paid">Төлбөр хийгдсэн</option>
              <option value="pending">Хүлээгдэж буй</option>
              <option value="cancelled">Цуцлагдсан</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-primary" type="submit">
            <i class="fas fa-save me-2"></i>Хадгалах
          </button>
          <button class="btn btn-danger" type="button" id="btnDelete">
            <i class="fas fa-trash me-2"></i>Устгах
          </button>
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Болих
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Working Hours Modal -->
  <div class="modal fade" id="workingHoursModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">📅 Ажиллах цагийг засах - <span id="whDoctorName"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="workingHoursContainer"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Болих</button>
          <button type="button" class="btn btn-primary" onclick="saveWorkingHours()">Хадгалах</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Inject clinic_id and default view mode into the global namespace for calendar.js -->
  <script>
    // CURRENT_CLINIC is read by calendar.js to determine which clinic's doctors to load
    window.CURRENT_CLINIC = <?= json_encode($clinic_id) ?>;
    // DEFAULT_VIEW_MODE is read by calendar.js to determine the initial
    // calendar view (day, week or month).  The value comes from
    // settings.json via index.php on the server side.
    window.DEFAULT_VIEW_MODE = <?= json_encode($defaultView) ?>;
    
    let currentDoctorId = null;
    const dayNames = ['Даваа', 'Мягмар', 'Лхагва', 'Пүрэв', 'Баасан', 'Бямба', 'Ням'];
    
    function openWorkingHoursModal(doctorId, doctorName) {
        currentDoctorId = doctorId;
        document.getElementById('whDoctorName').textContent = doctorName;
        
        // Fetch working hours
        fetch('api.php?action=doctor_working_hours&doctor_id=' + doctorId)
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('workingHoursContainer');
                container.innerHTML = '';
                
                if (!data.working_hours) data.working_hours = [];
                
                for (let day = 0; day < 7; day++) {
                    let wh = data.working_hours.find(w => w.day_of_week == day);
                    if (!wh) {
                        wh = { day_of_week: day, start_time: '09:00', end_time: '18:00', is_available: 1 };
                    }
                    
                    const row = document.createElement('div');
                    row.className = 'row align-items-end mb-3 p-3 bg-light rounded';
                    row.style.borderLeft = wh.is_available ? '4px solid #10b981' : '4px solid #ef4444';
                    
                    row.innerHTML = `
                        <div class="col-md-3">
                            <label class="form-label fw-bold">${dayNames[day]}</label>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <input type="checkbox" class="form-check-input" ${wh.is_available ? 'checked' : ''} 
                                       onchange="toggleDayStatus(this)" style="width: 20px; height: 20px; cursor: pointer;">
                                <span class="badge ${wh.is_available ? 'bg-success' : 'bg-danger'}">${wh.is_available ? 'Идэвхтэй' : 'Идэвхгүй'}</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Эхлэх цаг</label>
                            <input type="time" class="form-control wh-start" value="${wh.start_time}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дуусах цаг</label>
                            <input type="time" class="form-control wh-end" value="${wh.end_time}">
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted">${wh.start_time} - ${wh.end_time}</span>
                        </div>
                    `;
                    
                    container.appendChild(row);
                }
                
                const modal = new bootstrap.Modal(document.getElementById('workingHoursModal'));
                modal.show();
            })
            .catch(e => alert('Алдаа: ' + e.message));
    }
    
    function toggleDayStatus(checkbox) {
        const row = checkbox.closest('.row');
        const badge = row.querySelector('.badge');
        const isActive = checkbox.checked;
        
        row.style.borderLeftColor = isActive ? '#10b981' : '#ef4444';
        badge.className = 'badge ' + (isActive ? 'bg-success' : 'bg-danger');
        badge.textContent = isActive ? 'Идэвхтэй' : 'Идэвхгүй';
    }
    
    function saveWorkingHours() {
        const rows = document.querySelectorAll('#workingHoursContainer .row');
        const workingHours = [];
        
        rows.forEach((row, idx) => {
            const isAvailable = row.querySelector('input[type="checkbox"]').checked ? 1 : 0;
            const startTime = row.querySelector('.wh-start').value;
            const endTime = row.querySelector('.wh-end').value;
            
            workingHours.push({
                day_of_week: idx,
                start_time: startTime,
                end_time: endTime,
                is_available: isAvailable
            });
        });
        
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_working_hours',
                doctor_id: currentDoctorId,
                working_hours: workingHours
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                alert('✅ Ажиллах цаг хадгалагдлаа');
                bootstrap.Modal.getInstance(document.getElementById('workingHoursModal')).hide();
                location.reload();
            } else {
                alert('❌ Алдаа: ' + res.msg);
            }
        })
        .catch(e => alert('❌ Хүсэлт алдаа: ' + e.message));
    }
  </script>
  <script src="js/calendar.js?v=<?= time() ?>"></script>
</body>
</html>