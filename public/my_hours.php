<?php
require_once __DIR__ . '/../config.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'doctor') {
  header('Location: index.php');
  exit;
}

$doctor_id = $u['id'];
$name      = $u['name'];

$db = db();
$saved = false;
$error = '';

// 🕒 Хуваарь хадгалах
//
// Энэ формоос орж ирсэн өдрийн цагийн мэдээллийг
// calendar.js-тай нийцтэй байхаар working_hours хүснэгтэд хадгална.
//
// working_hours хүснэгт:
//   doctor_id (FK), day_of_week (0=Ням, 1=Дав, …, 6=Бямба),
//   start_time, end_time, is_available (1=ажиллана, 0=ажиллахгүй)
//
// Нийт 7 өдөр бүхий бүртгэлээ нэг бүрчлэн хадгалах; ажиллахгүй
// өдөр бүрийн is_available=0 гэж тэмдэглэж өгөх нь calendar.js дээр
// бүрэн өдөр off–хэсэгт байгаа болохыг илэрхийлэх тул заавал хадгална.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $db->beginTransaction();

    // working_hours хүснэгтээс хуучин хуваарийг устгана
    $stDel = $db->prepare("DELETE FROM working_hours WHERE doctor_id = ?");
    $stDel->execute([$doctor_id]);
    // doctor_hours хүснэгтээс хуучин хуваарийг устгана (буцаараар нийцтэй байх)
    // Хэрэв энэ хүснэгт байхгүй бол алдаа гаргахгүй
    try {
      $stDelDoc = $db->prepare("DELETE FROM doctor_hours WHERE doctor_id = ?");
      $stDelDoc->execute([$doctor_id]);
    } catch (Exception $e) {
      // ignore if table doesn't exist
    }

    // Шинэ хуваарь хадгалах бэлтгэл
    $stIns = $db->prepare("INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?,?,?,?,?)");
    // doctor_hours руу хадгалах бэлтгэл (хуучин бүтэц)
    // weekday талбар нь 1–7, ажиллах өдөрт л оруулна
    try {
      $stInsDoc = $db->prepare("INSERT INTO doctor_hours (doctor_id, weekday, time_start, time_end) VALUES (?,?,?,?)");
    } catch (Exception $e) {
      $stInsDoc = null;
    }

    // 1=Даваа … 7=Ням; calendar.js day_of_week 0=Ням, 1=Даваа … 6=Бямба
    for ($d = 1; $d <= 7; $d++) {
      // Идэвхтэй эсэх (checkbox)
      $active  = isset($_POST["active_$d"]);
      // Үндсэн эхлэх/дуусах цаг
      $start   = $_POST["start_$d"] ?? '';
      $end     = $_POST["end_$d"]   ?? '';

      // is_available: ажиллах эсэх
      $avail   = $active ? 1 : 0;

      // day_of_week DB-д хадгалах утга (0–6). 7 буюу Ням бол 0 болгоно
      $dow     = ($d == 7) ? 0 : $d;

      // Цагийн утгууд хоосон байвал default 09:00–18:00
      if (!$start || !$end) {
        $start = '09:00';
        $end   = '18:00';
      }

      // Бүх өдөрт бүртгэл оруулна – working_hours хүснэгтэд ажиллахгүй өдөр ч is_available=0 гэж хадгална
      $stIns->execute([$doctor_id, $dow, $start, $end, $avail]);

      // Хуучин doctor_hours хүснэгт рүү зөвхөн ажиллах өдөр хадгална
      if ($avail == 1 && $stInsDoc) {
        // doctor_hours хүснэгтэд Sunday нь 7 гэж хадгалагддаг
        $weekday = $d;
        try {
          $stInsDoc->execute([$doctor_id, $weekday, $start, $end]);
        } catch (Exception $e) {
          // ignore insert errors for compatibility
        }
      }
    }

    $db->commit();
    $saved = true;
  } catch (Exception $e) {
    $db->rollBack();
    $error = $e->getMessage();
  }
}

// 🗂 Одоогийн хадгалсан хуваарь унших
// working_hours хүснэгтээс авч, UI-гийн index (1–7) руу хөрвүүлнэ.
// Уншихад эхлээд working_hours хүснэгтээс уншина. Хэрэв хоосон байвал doctor_hours-оос уншиж, is_available=1 гэж бүртгэнэ.
try {
  $st = $db->prepare("SELECT day_of_week, start_time, end_time, is_available FROM working_hours WHERE doctor_id = ?");
  $st->execute([$doctor_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $rows = [];
}

$hours = [];
if (!$rows) {
  // Fallback: doctor_hours хүснэгтээс унших (хуучин бүтэц)
  try {
    $st2 = $db->prepare("SELECT weekday, time_start, time_end FROM doctor_hours WHERE doctor_id = ?");
    $st2->execute([$doctor_id]);
    $rowsOld = $st2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsOld as $r) {
      $weekday = (int)$r['weekday'];
      // doctor_hours: 1=Дав … 7=Ням; UI: 1=Дав … 7=Ням
      $displayDay = $weekday;
      $hours[$displayDay] = [
        'day_of_week' => ($weekday == 7 ? 0 : $weekday),
        'start_time'  => $r['time_start'],
        'end_time'    => $r['time_end'],
        'is_available' => 1
      ];
    }
  } catch (Exception $e) {
    // no fallback
  }
} else {
  foreach ($rows as $r) {
    $dow = (int)$r['day_of_week'];
    // DB: 0=Ням, 1=Дав … 6=Бям; UI: 1=Дав … 7=Ням
    $displayDay = ($dow === 0) ? 7 : $dow;
    $hours[$displayDay] = $r;
    $hours[$displayDay]['is_available'] = (int)$r['is_available'];
  }
}

// 🗓 Өдрийн нэрүүд
$days = [
  1 => 'Даваа',
  2 => 'Мягмар',
  3 => 'Лхагва',
  4 => 'Пүрэв',
  5 => 'Баасан',
  6 => 'Бямба',
  7 => 'Ням',
];
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ажлын цаг - <?= htmlspecialchars($name) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
      min-height: 100vh;
    }
    
    main {
      margin-left: 250px;
      padding: 32px;
      min-height: 100vh;
    }
    
    /* Page Header */
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 32px;
      flex-wrap: wrap;
      gap: 16px;
    }
    
    .page-title {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    
    .page-title .icon {
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
      box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
    }
    
    .page-title h1 {
      font-size: 28px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 4px;
    }
    
    .page-title p {
      color: #64748b;
      font-size: 14px;
    }
    
    .header-actions {
      display: flex;
      gap: 12px;
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      color: white;
      box-shadow: 0 4px 16px rgba(99, 102, 241, 0.3);
    }
    
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    }
    
    .btn-secondary {
      background: white;
      color: #475569;
      border: 1px solid #e2e8f0;
    }
    
    .btn-secondary:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
    }
    
    /* Alert Messages */
    .alert {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 24px;
      font-size: 14px;
      font-weight: 500;
    }
    
    .alert-success {
      background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    
    .alert-success i {
      color: #10b981;
      font-size: 20px;
    }
    
    .alert-danger {
      background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    
    .alert-danger i {
      color: #ef4444;
      font-size: 20px;
    }
    
    /* Info Card */
    .info-card {
      background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
      border: 1px solid #c7d2fe;
      border-radius: 16px;
      padding: 20px 24px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 16px;
    }
    
    .info-card .info-icon {
      width: 48px;
      height: 48px;
      background: white;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #6366f1;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
    }
    
    .info-card .info-text h3 {
      font-size: 15px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 4px;
    }
    
    .info-card .info-text p {
      font-size: 13px;
      color: #64748b;
    }
    
    /* Schedule Card */
    .schedule-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.04);
      overflow: hidden;
    }
    
    .schedule-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .schedule-header h2 {
      font-size: 18px;
      font-weight: 700;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .schedule-header h2 i {
      color: #6366f1;
    }
    
    /* Schedule Table */
    .schedule-table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .schedule-table th {
      padding: 16px 24px;
      text-align: left;
      font-size: 12px;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .schedule-table td {
      padding: 20px 24px;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
      font-size: 14px;
    }
    
    .schedule-table tbody tr {
      transition: all 0.2s;
    }
    
    .schedule-table tbody tr:hover {
      background: linear-gradient(135deg, #f8faff 0%, #faf8ff 100%);
    }
    
    .schedule-table tbody tr:last-child td {
      border-bottom: none;
    }
    
    /* Day Cell */
    .day-cell {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .day-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }
    
    .day-icon.weekday {
      background: linear-gradient(135deg, #e0e7ff 0%, #f0e6ff 100%);
      color: #6366f1;
    }
    
    .day-icon.weekend {
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      color: #d97706;
    }
    
    .day-name {
      font-weight: 600;
      color: #1e293b;
    }
    
    /* Toggle Switch */
    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 52px;
      height: 28px;
    }
    
    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }
    
    .toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: #e2e8f0;
      border-radius: 28px;
      transition: all 0.3s ease;
    }
    
    .toggle-slider:before {
      position: absolute;
      content: "";
      height: 22px;
      width: 22px;
      left: 3px;
      bottom: 3px;
      background: white;
      border-radius: 50%;
      transition: all 0.3s ease;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .toggle-switch input:checked + .toggle-slider {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }
    
    .toggle-switch input:checked + .toggle-slider:before {
      transform: translateX(24px);
    }
    
    /* Time Input */
    .time-input-wrapper {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .time-input {
      padding: 10px 14px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 500;
      color: #1e293b;
      background: #f8fafc;
      transition: all 0.2s;
      width: 120px;
    }
    
    .time-input:focus {
      outline: none;
      border-color: #6366f1;
      background: white;
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    
    .time-label {
      font-size: 12px;
      color: #94a3b8;
      font-weight: 500;
    }
    
    /* Submit Section */
    .submit-section {
      padding: 24px;
      background: #f8fafc;
      border-top: 1px solid #f1f5f9;
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
      main { margin-left: 0; padding: 20px; }
    }
    
    @media (max-width: 768px) {
      .page-header { flex-direction: column; align-items: flex-start; }
      .schedule-table th, .schedule-table td { padding: 12px 16px; }
      .time-input { width: 100px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main>
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-title">
      <div class="icon">
        <i class="fas fa-clock"></i>
      </div>
      <div>
        <h1>Ажлын цаг</h1>
        <p><?= htmlspecialchars($name) ?> - Долоо хоногийн хуваарь</p>
      </div>
    </div>
    <div class="header-actions">
      <a href="my_schedule.php" class="btn btn-secondary">
        <i class="fas fa-calendar-check"></i>
        Миний хуваарь
      </a>
    </div>
  </div>
  
  <?php if ($saved): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <span>Хуваарь амжилттай хадгалагдлаа!</span>
    </div>
    <script>
      if (window.parent) {
        window.parent.postMessage({ reloadDoctors: true }, "*");
      }
    </script>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-danger">
      <i class="fas fa-exclamation-circle"></i>
      <span>Алдаа: <?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>
  
  <!-- Info Card -->
  <div class="info-card">
    <div class="info-icon">
      <i class="fas fa-info"></i>
    </div>
    <div class="info-text">
      <h3>Ажлын цагийн тохиргоо</h3>
      <p>Энэ хуваарийг календар болон захиалгын системд ашиглана. Өдөр бүр дээр toggle-ийг асааж, эхлэх/дуусах цагийг тохируулна уу.</p>
    </div>
  </div>
  
  <!-- Schedule Card -->
  <div class="schedule-card">
    <div class="schedule-header">
      <h2>
        <i class="fas fa-calendar-week"></i>
        Долоо хоногийн хуваарь
      </h2>
    </div>
    
    <form method="post">
      <table class="schedule-table">
        <thead>
          <tr>
            <th>Гараг</th>
            <th style="text-align: center;">Ажиллана</th>
            <th>Эхлэх цаг</th>
            <th>Дуусах цаг</th>
          </tr>
        </thead>
        <tbody>
          <?php for ($d = 1; $d <= 7; $d++):
            $row   = $hours[$d] ?? null;
            $on    = ($row && isset($row['is_available']) && (int)$row['is_available'] === 1);
            $start = $row['start_time'] ?? '09:00';
            $end   = $row['end_time'] ?? '18:00';
            $isWeekend = ($d >= 6);
            $dayAbbr = ['', 'Да', 'Мя', 'Лх', 'Пү', 'Ба', 'Бя', 'Ня'][$d];
          ?>
          <tr>
            <td>
              <div class="day-cell">
                <div class="day-icon <?= $isWeekend ? 'weekend' : 'weekday' ?>">
                  <?= $dayAbbr ?>
                </div>
                <span class="day-name"><?= $days[$d] ?></span>
              </div>
            </td>
            <td style="text-align: center;">
              <label class="toggle-switch">
                <input type="checkbox" name="active_<?= $d ?>" <?= $on ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="time-input-wrapper">
                <input type="time" class="time-input" name="start_<?= $d ?>" value="<?= htmlspecialchars(substr($start, 0, 5)) ?>">
              </div>
            </td>
            <td>
              <div class="time-input-wrapper">
                <input type="time" class="time-input" name="end_<?= $d ?>" value="<?= htmlspecialchars(substr($end, 0, 5)) ?>">
              </div>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      
      <div class="submit-section">
        <button type="button" class="btn btn-secondary" onclick="location.reload()">
          <i class="fas fa-undo"></i>
          Буцаах
        </button>
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i>
          Хуваарь хадгалах
        </button>
      </div>
    </form>
  </div>
</main>
</body>
</html>