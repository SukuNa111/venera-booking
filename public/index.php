<?php
require_once __DIR__ . '/../config.php';
require_login();

// Some deployments load index.php without api.php helpers; provide a local column_exists shim.
if (!function_exists('column_exists')) {
  function column_exists($table, $column) {
    try {
      $st = db()->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Exception $e) {
      return false;
    }
  }
}

// Determine current user and their clinic/role.  Doctors should only see their own clinic.
$user      = current_user();
$role      = $user['role'] ?? '';
$isSuper   = ($role === 'super_admin');
$clinic_id = $isSuper ? 'all' : ($user['clinic_id'] ?? 'venera');
$userDept  = $user['department'] ?? '';

// If doctor, block access to calendar
if ($role === 'doctor') {
  header('Location: my_schedule.php');
  exit;
}

// Load active clinics from the database for the clinic selector.  If the query fails
// (e.g. missing table) fall back to the predefined list.
$clinicOpts = [];
try {
    $st = db()->prepare("SELECT code, name FROM clinics WHERE active = 1 ORDER BY COALESCE(sort_order,0), id");
    $st->execute();
    $clinicOpts = $st->fetchAll(PDO::FETCH_ASSOC);
  if ($isSuper && !array_filter($clinicOpts, fn($c) => ($c['code'] ?? '') === 'all')) {
    array_unshift($clinicOpts, ['code' => 'all', 'name' => 'Бүх эмнэлэг']);
  }
} catch (Exception $ex) {
    // fallback to default clinics when DB is unavailable
    $clinicOpts = [
        ['code' => 'venera', 'name' => 'Венера'],
        ['code' => 'luxor',  'name' => 'Голден Луксор'],
        ['code' => 'khatan', 'name' => 'Гоо Хатан'],
    ];
  if ($isSuper) {
    array_unshift($clinicOpts, ['code' => 'all', 'name' => 'Бүх эмнэлэг']);
  }
}

// Doctors are locked to their clinic – hide the clinic selector and disable changes.
$isRestricted = (!$isSuper && in_array($role, ['doctor', 'reception', 'admin']));

// Column existence flags for backward compatibility (older schemas may not have active/show_in_calendar)
$hasUserActive = column_exists('users', 'active');
$hasUserShow = column_exists('users', 'show_in_calendar');

// Default working hours (09:00-18:00, Mon-Fri available)
$defaultWorkingHours = [];
for ($i=0; $i<7; $i++) {
  $defaultWorkingHours[] = [
    'day_of_week' => $i,
    'start_time' => '09:00',
    'end_time' => '18:00',
    'is_available' => ($i >= 1 && $i <= 5) ? 1 : 0
  ];
}

// Count total active doctors (fallback when active column missing)
$doctorCountSql = $hasUserActive
  ? "SELECT COUNT(id) FROM users WHERE role='doctor' AND active = 1"
  : "SELECT COUNT(id) FROM users WHERE role='doctor'";
$doctorCount = db()->query($doctorCountSql)->fetchColumn();

// Get all doctors for this clinic (for export list)
$doctorsForClinic = [];
$inactiveDoctors = [];
try {
  $showExpr = $hasUserShow ? 'COALESCE(show_in_calendar,1)' : '1';
  $activeClause = $hasUserActive ? 'AND active = 1' : '';
  if ($clinic_id === 'all') {
    $st = db()->prepare("SELECT id, name, {$showExpr} AS show_in_calendar FROM users WHERE role='doctor' {$activeClause} ORDER BY name");
    $st->execute();
  } else {
    $st = db()->prepare("SELECT id, name, {$showExpr} AS show_in_calendar FROM users WHERE role='doctor' {$activeClause} AND clinic_id = ? ORDER BY name");
    $st->execute([$clinic_id]);
  }
  $doctorsForClinic = $st->fetchAll(PDO::FETCH_ASSOC);
    
  foreach ($doctorsForClinic as &$doc) {
    $doc['working_hours'] = $defaultWorkingHours;
  }
    
  if ($hasUserActive) {
    if ($clinic_id === 'all') {
      $st2 = db()->prepare("SELECT id, name FROM users WHERE role='doctor' AND active = 0 ORDER BY name");
      $st2->execute();
    } else {
      $st2 = db()->prepare("SELECT id, name FROM users WHERE role='doctor' AND active = 0 AND clinic_id = ? ORDER BY name");
      $st2->execute([$clinic_id]);
    }
  } else {
    $st2 = db()->prepare("SELECT id, name FROM users WHERE 1=0");
    $st2->execute();
  }
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
  <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
  <link href="css/style.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <style>
    /* Dynamic theme colors from settings */
    :root {
      --primary: <?= htmlspecialchars($primaryColor) ?>;
      --primary-soft: <?= htmlspecialchars($primaryColor) ?>33; /* 20% opacity hex */
      --secondary: <?= htmlspecialchars($secondaryColor) ?>;
    }
    /* Flatpickr Premium Styling */
    .flatpickr-calendar {
      box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
      border: none !important;
      border-radius: 16px !important;
      padding: 8px !important;
      z-index: 10000 !important; /* Above Bootstrap modals */
    }
    .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay {
      background: var(--primary) !important;
      border-color: var(--primary) !important;
      border-radius: 50% !important;
    }
    .flatpickr-day.today {
      border-color: var(--primary) !important;
      border-radius: 50% !important;
    }
    .flatpickr-months .flatpickr-month {
      height: 40px !important;
    }
    .flatpickr-current-month {
      font-size: 1.1rem !important;
      font-weight: 600 !important;
    }
    .flatpickr-monthDropdown-months {
        font-weight: 600 !important;
    }
    /* Hide native time icons for chrome/safari/edge */
    input::-webkit-calendar-picker-indicator {
      display: none !important;
      -webkit-appearance: none;
    }
    /* Disable blue outline on focus for timepickers */
    .timepicker:focus, .date-picker:focus {
      box-shadow: 0 0 0 2px var(--primary-soft) !important;
      border-color: var(--primary) !important;
    }

    /* Toolbar Responsiveness */
    .toolbar {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap; /* Key for mobile */
      background: white;
      padding: 1rem 1.5rem;
      border-radius: 12px;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
      margin-bottom: 1.5rem;
    }
    
    .toolbar .btn-group {
      background: #f1f5f9;
      padding: 4px;
      border-radius: 12px;
    }

    .toolbar .btn {
      border: none;
      border-radius: 8px !important;
      padding: 0.5rem 1rem;
      font-weight: 500;
      transition: all 0.2s;
    }
    
    .toolbar .btn-outline-primary, .toolbar .btn-outline-secondary {
      background: white;
      border: 1px solid #e2e8f0 !important;
    }

    /* Mobile adjustments */
    @media (max-width: 991.98px) {
      .toolbar { gap: 0.5rem; padding: 0.75rem; }
      .toolbar .user-info { display: none; }
      #dateLabel { font-size: 1.1rem; margin-bottom: 0; flex-grow: 1; }
      .now-clock { display: none; }
      .toolbar .btn-group { order: 10; width: 100%; display: flex; }
      .toolbar .btn-group .btn { flex: 1; justify-content: center; }
      .toolbar #prev, .toolbar #next { padding: 0.5rem 0.75rem; }
      .toolbar #today { flex-grow: 1; text-align: center; }
      .toolbar .btn span { display: none; } /* Hide text on some buttons if needed */
    }

    /* Responsive Grid Styles */
    .calendar-wrap {
      display: flex;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      position: relative;
    }
    
    #timeCol {
      position: sticky;
      left: 0;
      z-index: 20;
      box-shadow: 4px 0 8px rgba(0,0,0,0.05);
      flex-shrink: 0;
    }
    
    #calendarRow {
      display: flex;
      flex-grow: 1;
      min-width: max-content;
    }
    
    .calendar-col {
      min-width: 280px; /* Wider for better readability on mobile */
      flex-shrink: 0;
    }
    
    @media (min-width: 992px) {
      .calendar-col {
        min-width: 200px;
        flex: 1;
      }
    }
    
    /* Month View Responsiveness */
    #calendarRow.month-view {
      display: grid;
      grid-template-columns: repeat(7, minmax(120px, 1fr)); /* Minimum width for cells */
      grid-auto-rows: minmax(120px, 1fr);
      gap: 4px;
      padding: 8px;
      width: 100%;
      height: auto;
      overflow-x: auto;
    }
    
    @media (max-width: 768px) {
      #calendarRow.month-view {
        grid-template-columns: repeat(7, minmax(100px, 1fr));
        gap: 2px;
        padding: 4px;
      }
      .month-cell {
        padding: 6px !important;
      }
      .month-cell strong {
        font-size: 0.8rem !important;
      }
    }
</style>
</head>
<body>

  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <div class="toolbar">
      <button class="mobile-toggle" id="btnToggleSidebar">
        <i class="fas fa-bars"></i>
      </button>

      <div class="user-info">
        <div class="user-avatar">
          <i class="fas fa-tooth"></i>
        </div>
        <div class="user-details">
          <span class="welcome">Тавтай морилно уу</span>
          <span class="name"><?= htmlspecialchars($user['name'] ?? '') ?></span>
        </div>
      </div>
      
      <h4 id="dateLabel" style="cursor: pointer; transition: all 0.2s;" onmouseover="this.style.opacity='0.7'; this.style.transform='scale(1.02)';" onmouseout="this.style.opacity='1'; this.style.transform='scale(1)';" title="Огноо сонгох">
        <span style="color: #64748b;">Өдөр:</span> <strong>---</strong>
      </h4>
      <input type="text" id="datePickerHidden" style="visibility: hidden; width: 0; height: 0; padding: 0; border: none; position: absolute;">
      
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
      <span id="nowClock" class="now-clock ms-2">--:--:--</span>
      
      <select id="clinic" class="form-select form-select-sm ms-2 <?= $isRestricted ? 'd-none' : '' ?>" style="width:auto;" <?= $isRestricted ? 'disabled' : '' ?>>
        <?php foreach ($clinicOpts as $opt): ?>
          <option value="<?= htmlspecialchars($opt['code']) ?>"
            <?= $clinic_id === $opt['code'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($opt['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($isRestricted): ?>
        <span class="badge bg-white text-primary border ms-2 px-3 py-2" style="font-size: 0.9rem; font-weight: 600; border-radius: 8px;">
          <i class="fas fa-hospital me-1"></i>
          <?php 
            $cName = 'Венера';
            foreach($clinicOpts as $o) if($o['code']===$clinic_id) $cName = $o['name'];
            echo htmlspecialchars($cName);
          ?>
        </span>
      <?php endif; ?>

      <!-- Department selector (for Venera clinic) -->
      <?php $lockDept = ($isRestricted && !empty($userDept)); ?>
      <select id="departmentSelect" class="form-select form-select-sm ms-2" style="width:auto; min-width:160px;" <?= $lockDept ? 'disabled' : '' ?>>
        <?php if (!$lockDept): ?>
          <option value="">Бүх тасаг</option>
        <?php endif; ?>
        <?php
          $deps = ['Мэс засал','Мэсийн бус','Уламжлалт','Шүд','Дусал'];
          foreach ($deps as $dep):
            $sel = ($userDept === $dep) ? 'selected' : '';
            echo '<option value="'.htmlspecialchars($dep).'" '.$sel.'>'.htmlspecialchars($dep).'</option>';
          endforeach;
        ?>
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
        <span>Төлбөр хүлээгдэж буй</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #ef4444;"></div>
        <span>Үйлчлүүлэгч цуцалсан</span>
      </div>
      <div class="status-badge">
        <div class="status-dot" style="background: #06b6d4;"></div>
        <span>Эмч цуцалсан</span>
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
          <!-- Patient Summary Insight (Dynamic) -->
          <div id="patientInsightRowAdd" class="col-12" style="display:none;">
            <div class="alert alert-info border-0 shadow-sm d-flex align-items-center" style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-radius: 12px; padding: 1rem;">
              <div class="me-3 bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; min-width: 48px;">
                <i class="fas fa-history text-success" style="font-size: 1.25rem;"></i>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                   <div id="patientSummaryTextAdd" class="fw-bold text-success" style="font-size: 0.95rem;">Уншиж байна...</div>
                   <div id="patientVisitTagAdd" class="badge bg-success rounded-pill"></div>
                </div>
                <div id="patientRecentHistoryAdd" class="text-muted small mt-1"></div>
              </div>
            </div>
          </div>
          <input type="hidden" name="clinic" id="clinic_in">

          <div class="col-md-4">
            <label class="form-label">Огноо</label>
            <input type="text" name="date" id="date" class="form-control date-picker" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Эхлэх</label>
            <input type="text" name="start_time" id="addStartTime" class="form-control timepicker" value="09:00" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Дуусах <span id="treatmentDurationBadge" class="badge bg-info ms-2" style="display:none;"></span></label>
            <input type="text" name="end_time" id="addEndTime" class="form-control timepicker" value="09:30" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Тасаг <span class="text-danger">*</span></label>
            <select name="department" id="department" class="form-select" required>
              <option value="">-- Сонгох --</option>
              <option value="Мэс засал">Мэс засал</option>
              <option value="Мэсийн бус">Мэсийн бус</option>
              <option value="Уламжлалт">Уламжлалт</option>
              <option value="Шүд">Шүд</option>
              <option value="Дусал">Дусал</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Өвчтөний нэр</label>
            <input type="text" name="patient_name" id="patient_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Эмчилгээний төрөл <span class="text-warning fw-semibold">*</span></label>
            <div class="treatment-select-wrapper">
              <input type="text" id="treatment_search" class="form-control" placeholder="Хайх эсвэл бичих..." autocomplete="off">
              <input type="hidden" name="treatment_id" id="treatment_id">
              <input type="hidden" name="custom_treatment" id="custom_treatment">
              <div id="treatment_dropdown" class="treatment-dropdown"></div>
            </div>
            <small class="text-muted">Сонгох эсвэл шинээр бичнэ үү</small>
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
              <option value="cancelled">Үйлчлүүлэгч цуцалсан</option>
              <option value="doctor_cancelled">Эмч цуцалсан</option>
            </select>
          </div>

          <div class="col-md-6" id="addPriceGroup" style="display:none;">
            <label class="form-label">Үнийн дүн <span class="text-danger">*</span></label>
            <input type="number" name="price" id="addPrice" class="form-control" placeholder="0" min="0" step="0.01">
            <div class="form-text text-muted" style="margin-top:6px;">Төлбөр хийгдсэн төлөвт шилжүүлэхэд үнийн дүн заавал шаардлагатай.</div>
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
          <!-- Patient Summary Insight (Dynamic) -->
          <div id="patientInsightRow" class="col-12" style="display:none;">
            <div class="alert alert-info border-0 shadow-sm d-flex align-items-center" style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-radius: 12px; padding: 1rem;">
              <div class="me-3 bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; min-width: 48px;">
                <i class="fas fa-id-card text-primary" style="font-size: 1.25rem;"></i>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                   <div id="patientSummaryText" class="fw-bold text-primary" style="font-size: 0.95rem;">Уншиж байна...</div>
                   <div id="patientVisitTag" class="badge bg-primary rounded-pill"></div>
                </div>
                <div id="patientRecentHistory" class="text-muted small mt-1"></div>
              </div>
            </div>
          </div>
          <input type="hidden" name="id">
          <input type="hidden" name="clinic">

          <div class="col-md-4">
            <label class="form-label">Огноо</label>
            <input type="text" name="date" class="form-control date-picker" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Эхлэх</label>
            <input type="text" name="start_time" id="editStartTime" class="form-control timepicker" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Дуусах</label>
            <input type="text" name="end_time" id="editEndTime" class="form-control timepicker" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Тасаг <span class="text-danger">*</span></label>
            <select name="department" id="editDepartment" class="form-select" required>
              <option value="">-- Сонгох --</option>
              <option value="Мэс засал">Мэс засал</option>
              <option value="Мэсийн бус">Мэсийн бус</option>
              <option value="Уламжлалт">Уламжлалт</option>
              <option value="Шүд">Шүд</option>
              <option value="Дусал">Дусал</option>
            </select>
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
            <label class="form-label">Өвчтөний нэр</label>
            <input type="text" name="patient_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Үйлчилгээ <span class="text-warning fw-semibold">*</span></label>
            <div class="treatment-select-wrapper">
              <input type="text" id="edit_treatment_search" name="service_name" class="form-control" placeholder="Хайх эсвэл бичих..." autocomplete="off" required>
              <input type="hidden" name="treatment_id" id="edit_treatment_id">
              <input type="hidden" name="custom_treatment" id="edit_custom_treatment">
              <div id="edit_treatment_dropdown" class="treatment-dropdown"></div>
            </div>
            <small class="text-muted">Сонгох эсвэл шинээр бичнэ үү</small>
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
            <select name="status" id="editStatusSelect" class="form-select">
              <option value="online">Онлайн</option>
              <option value="arrived">Ирсэн</option>
              <option value="paid">Төлбөр хийгдсэн</option>
              <option value="pending">Хүлээгдэж буй</option>
              <option value="cancelled">Үйлчлүүлэгч цуцалсан</option>
              <option value="doctor_cancelled">Эмч цуцалсан</option>
            </select>
            <div class="form-text text-muted" style="margin-top:6px;">Төлбөрийн төлөв (`Төлбөр хийгдсэн`) рүү шилжүүлэхийн өмнө эмчийг заавал сонгоно уу.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Эмч</label>
            <select name="doctor_id" class="form-select" id="editDoctorSelect"></select>
            <div class="form-text text-muted" style="margin-top:6px;">Захиалга үүсгэх үед эмч сонгох шаардлагагүй. Төлбөр үргэлжлүүлэхийн тулд эмчээ сонгоно уу.</div>
          </div>

          <div class="col-md-6" id="editPriceGroup" style="display:none;">
            <label class="form-label">Үнийн дүн <span class="text-danger">*</span></label>
            <input type="number" name="price" id="editPrice" class="form-control" placeholder="0" min="0" step="0.01">
            <div class="form-text text-muted" style="margin-top:6px;">Төлбөр хийгдсэн төлөвт шилжүүлэхэд үнийн дүн заавал шаардлагатай.</div>
          </div>

          <!-- Material Usage Section -->
          <div class="col-12 mt-3 px-3 py-3 rounded-4 bg-light border">
            <h6 class="fw-bold mb-3"><i class="fas fa-boxes me-2 text-primary"></i>Ашигласан материал</h6>
            <div class="row g-2 mb-3">
              <div class="col-8">
                <select id="usageMaterialSelect" class="form-select border-0 shadow-sm" style="border-radius: 0.75rem;">
                  <option value="">-- Материал сонгох --</option>
                </select>
              </div>
              <div class="col-3">
                <input type="number" id="usageQty" class="form-control border-0 shadow-sm" placeholder="Тоо" value="1" step="0.01" style="border-radius: 0.75rem;">
              </div>
              <div class="col-1">
                <button type="button" class="btn btn-primary w-100" onclick="recordUsage()" style="border-radius: 0.75rem;"><i class="fas fa-plus"></i></button>
              </div>
            </div>
            <div id="usageList" class="small">
              <div class="text-center text-muted py-2">Ачаалж байна...</div>
            </div>
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
    // User context for locking filters on the client
    window.USER_ROLE = <?= json_encode($role) ?>;
    window.USER_DEPARTMENT = <?= json_encode($userDept) ?>;
    // DEFAULT_VIEW_MODE is read by calendar.js to determine the initial
    // calendar view (day, week or month).  The value comes from
    // settings.json via index.php on the server side.
    window.DEFAULT_VIEW_MODE = <?= json_encode($defaultView) ?>;
    
    let currentDoctorId = null;
    const dayNames = ['Даваа', 'Мягмар', 'Лхагва', 'Пүрэв', 'Баасан', 'Бямба', 'Ням'];

    document.getElementById('modalEdit').addEventListener('hidden.bs.modal', function () {
      if (typeof INVENTORY_LOADED !== 'undefined') INVENTORY_LOADED = false;
      // Also reset usage list
      document.getElementById('usageList').innerHTML = '<div class="text-center text-muted py-2">Ачаалж байна...</div>';
    });
    
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
                            <input type="text" class="form-control wh-start timepicker" value="${wh.start_time}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дуусах цаг</label>
                            <input type="text" class="form-control wh-end timepicker" value="${wh.end_time}">
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

    // Live topbar clock (Asia/Ulaanbaatar)
    (function initNowClock(){
      const el = document.getElementById('nowClock');
      if (!el) return;
      
      function tick() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        el.textContent = `${hours}:${minutes}:${seconds}`;
      }
      
      tick();
      setInterval(tick, 1000);
    })();

    // Эхлэх цаг оруулахад автоматаар 30 минут нэмэх функц
    function addThirtyMinutes(timeString) {
      if (!timeString) return '';
      const [hours, minutes] = timeString.split(':').map(Number);
      const date = new Date();
      date.setHours(hours);
      date.setMinutes(minutes + 30);
      return date.toTimeString().slice(0, 5);
    }

    // Нэмэх модал - эхлэх цаг оруулахад дуусах цагийг автоматаар тооцоох
    const addStartTime = document.getElementById('addStartTime');
    if (addStartTime) {
      addStartTime.addEventListener('change', function() {
        const endTimeInput = document.getElementById('addEndTime');
        if (endTimeInput && this.value) {
          endTimeInput.value = addThirtyMinutes(this.value);
        }
      });
    }

    // Засах модал - эхлэх цаг оруулахад дуусах цагийг автоматаар тооцоох
    const editStartTime = document.getElementById('editStartTime');
    if (editStartTime) {
      editStartTime.addEventListener('change', function() {
        const endTimeInput = document.getElementById('editEndTime');
        if (endTimeInput && this.value) {
          endTimeInput.value = addThirtyMinutes(this.value);
        }
      });
    }

    // Эмч талбарыг статусын утгад үндэслэн харуулах/нуух функц
    function toggleDoctorField(selectElement) {
      const doctorSelectAdd = document.querySelector('#addForm select[name="doctor_id"]');
      const doctorSelectEdit = document.querySelector('#editForm select[name="doctor_id"]');
      
      if (!selectElement) return;
      const status = selectElement.value;
      
      // Нэмэх модал дээр зөвхөн "paid" статус сонгосон үед эмч сонгох бий
      if (doctorSelectAdd && selectElement.id !== 'editStatusSelect') {
        // Нэмэх модалд эмч сонгох байхгүй байх - үүнийг нуусан байна
        // Энд сонгох шаардлагагүй
      }
      
      // Засах модал дээр "paid" статус сонгосон үед л эмч талбар харагдах
      if (doctorSelectEdit && selectElement.id === 'editStatusSelect') {
        const doctorFieldWrapper = doctorSelectEdit.closest('.col-md-4');
        const priceGroup = document.getElementById('editPriceGroup');
        if (status === 'paid') {
          if (doctorFieldWrapper) doctorFieldWrapper.style.display = 'block';
          if (priceGroup) priceGroup.style.display = 'block';
        } else {
          if (doctorFieldWrapper) doctorFieldWrapper.style.display = 'none';
          if (priceGroup) priceGroup.style.display = 'none';
        }
      }

      // Нэмэх модал дээр бас үнийн дүн харуулах
      if (selectElement.id === 'status' && selectElement.closest('#addForm')) {
        const priceGroup = document.getElementById('addPriceGroup');
        if (status === 'paid') {
           if (priceGroup) priceGroup.style.display = 'block';
        } else {
           if (priceGroup) priceGroup.style.display = 'none';
        }
      }
    }

    // Засах модал дээр статус өөрчлөгдөх үед эмч талбарыг харуулах/нуух
    const editStatusSelect = document.getElementById('editStatusSelect');
    if (editStatusSelect) {
      editStatusSelect.addEventListener('change', function() {
        toggleDoctorField(this);
      });
    }

    // Модал нээгдөхөд эмч талбарыг шалгах
    document.getElementById('modalEdit').addEventListener('show.bs.modal', function() {
      setTimeout(() => {
        toggleDoctorField(editStatusSelect);
      }, 50);
    });

    // Нэмэх модалд эмч талбарыг анхдаа нуух
    document.getElementById('modalAdd').addEventListener('show.bs.modal', function() {
      const doctorSelect = document.querySelector('#addForm select[name="doctor_id"]');
      if (doctorSelect) {
        doctorSelect.closest('.col-md-4').style.display = 'none';
      }
      updateDepartmentVisibility();
    });

    // Department visibility logic
    function updateDepartmentVisibility() {
      const clinicCode = document.getElementById('clinic').value;
      const isVenera = (clinicCode === 'venera');
      const isLuxor = (clinicCode === 'luxor');
      
      // Define departments for each clinic
      const clinicDepts = {
        'venera': ['Мэс засал', 'Мэсийн бус', 'Уламжлалт', 'Шүд', 'Дусал'],
        'luxor': ['Үзлэг', 'Массаж', 'Мэсийн бус'],
        'khatan': []
      };
      const depts = clinicDepts[clinicCode] || [];

      // 1. Toolbar department selector - show for both Venera and Luxor
      const toolbarDept = document.getElementById('departmentSelect');
      if (toolbarDept) {
        const group = toolbarDept.closest('.ms-2');
        if (group) {
          group.style.display = (isVenera || isLuxor) ? 'block' : 'none';
        }

        // Store current value
        const currentVal = toolbarDept.value;
        // Rebuild options
        toolbarDept.innerHTML = '<option value="">Бүх тасаг</option>';
        depts.forEach(d => {
          const opt = document.createElement('option');
          opt.value = d;
          opt.textContent = d;
          if (d === currentVal) opt.selected = true;
          toolbarDept.appendChild(opt);
        });
      }

      // 2. Add/Edit modal selections
      const deptSelects = [
        document.getElementById('department'), // Add modal
        document.getElementById('editDepartment') // Edit modal
      ];

      deptSelects.forEach(select => {
        if (!select) return;
        
        // Hide/Show the department group wrapper
        const group = select.closest('.col-md-4');
        if (group) {
          group.style.display = depts.length > 0 ? 'block' : 'none';
          // Make it mandatory if it's shown
          select.required = depts.length > 0;
        }

        // Store current value to restore if possible
        const currentVal = select.value;
        
        // Clear and rebuild options
        select.innerHTML = '<option value="">-- Сонгох --</option>';
        depts.forEach(d => {
          const opt = document.createElement('option');
          opt.value = d;
          opt.textContent = d;
          if (d === currentVal) opt.selected = true;
          select.appendChild(opt);
        });
      });
    }

    // Consolidate all pickers into a global initialization function
    window.initAppPickers = function() {
        console.log("🛠 Initializing App Pickers...");
        initTimePickers();
        initDatePickers();
    };

    // Initialize Flatpickr for all 24h timepickers
    function initTimePickers() {
      document.querySelectorAll('.timepicker').forEach(el => {
        if (!el._flatpickr) {
          flatpickr(el, {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i",
            time_24hr: true,
            minuteIncrement: 5,
            allowInput: true,
            static: true
          });
        } else {
          el._flatpickr.setDate(el.value || '', false);
        }
      });
    }

    // Initialize date pickers
    function initDatePickers() {
      // Inputs in modals
      flatpickr('.date-picker', {
        dateFormat: "Y-m-d",
        allowInput: true,
        monthSelectorType: "dropdown",
        locale: { firstDayOfWeek: 1 },
        // Ensure it appears above everything
        onOpen: function(selectedDates, dateStr, instance) {
            instance.calendarContainer.style.zIndex = "10000";
        }
      });
      
      // Hidden picker for jumping to date via Label
      const hiddenPicker = flatpickr('#datePickerHidden', {
        dateFormat: "Y-m-d",
        allowInput: true,
        locale: { firstDayOfWeek: 1 },
        onChange: function(selectedDates, dateStr) {
          if (dateStr) {
            window.CURRENT_DATE = new Date(dateStr);
            if (typeof window.loadBookings === 'function') window.loadBookings();
          }
        }
      });
      
      const dateLabel = document.getElementById('dateLabel');
      if (dateLabel) {
        // Remove old listener to prevent duplicates
        const newLabel = dateLabel.cloneNode(true);
        dateLabel.parentNode.replaceChild(newLabel, dateLabel);
        newLabel.addEventListener('click', () => {
          hiddenPicker.open();
        });
      }
    }

    // Initial call
    window.initAppPickers();

    // Re-init when modals open to catch dynamic elements if any
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('shown.bs.modal', initTimePickers);
    });

    // Initialize on load
    updateDepartmentVisibility();

    // Clinic selector change
    document.getElementById('clinic').addEventListener('change', function() {
      updateDepartmentVisibility();
      window.initAppPickers();
    });

    // Modal show events
    document.getElementById('modalAdd').addEventListener('shown.bs.modal', function() {
      // Ensure pickers are bound after modal is visible
      window.initAppPickers();
      
      // Set the date field to CURRENT_DATE if it's empty
      const dateIn = document.getElementById('date');
      if (dateIn) {
        const val = dateIn.value;
        if (!val || val === '---') {
            const defaultDate = (typeof window.fmtDate === 'function') 
              ? window.fmtDate(window.CURRENT_DATE || new Date()) 
              : new Date().toISOString().slice(0,10);
            
            dateIn.value = defaultDate;
            if (dateIn._flatpickr) dateIn._flatpickr.setDate(defaultDate, false);
        }
      }
      
      const doctorSelect = document.querySelector('#addForm select[name="doctor_id"]');
      if (doctorSelect) {
        doctorSelect.closest('.col-md-4').style.display = 'none';
      }
      updateDepartmentVisibility();
    });
    
    document.getElementById('modalEdit').addEventListener('shown.bs.modal', function() {
      window.initAppPickers();
      updateDepartmentVisibility();
    });
  </script>
  <script src="js/calendar.js?v=<?= time() ?>"></script>
</body>
</html>