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

// Ирэх өдрүүдийн хуваарь
$st = db()->prepare("
  SELECT id, patient_name, service_name, date, start_time, end_time, status
  FROM bookings
  WHERE doctor_id = ?
    AND date >= CURRENT_DATE
  ORDER BY date, start_time
");
$st->execute([$doctor_id]);
$bookings = $st->fetchAll(PDO::FETCH_ASSOC);

// Статистик
$today = date('Y-m-d');
$todayCount = 0;
$weekCount = 0;
$weekEnd = date('Y-m-d', strtotime('+7 days'));

foreach ($bookings as $b) {
  if ($b['date'] === $today) $todayCount++;
  if ($b['date'] <= $weekEnd) $weekCount++;
}

$statusLabels = [
  'online' => ['label' => 'Онлайн', 'color' => '#3b82f6', 'bg' => '#eff6ff'],
  'arrived' => ['label' => 'Ирсэн', 'color' => '#f59e0b', 'bg' => '#fffbeb'],
  'paid' => ['label' => 'Төлсөн', 'color' => '#10b981', 'bg' => '#ecfdf5'],
  'pending' => ['label' => 'Хүлээгдэж буй', 'color' => '#8b5cf6', 'bg' => '#f5f3ff'],
  'cancelled' => ['label' => 'Цуцлагдсан', 'color' => '#ef4444', 'bg' => '#fef2f2']
];
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Миний хуваарь - <?= htmlspecialchars($name) ?></title>
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
      padding: 12px 20px;
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
    
    /* Stats Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 32px;
    }
    
    .stat-card {
      background: white;
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.04);
      transition: all 0.3s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
    }
    
    .stat-card .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      margin-bottom: 16px;
    }
    
    .stat-card .stat-value {
      font-size: 32px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 4px;
    }
    
    .stat-card .stat-label {
      font-size: 14px;
      color: #64748b;
    }
    
    .stat-card.today .stat-icon { background: #eff6ff; color: #3b82f6; }
    .stat-card.week .stat-icon { background: #f0fdf4; color: #22c55e; }
    .stat-card.total .stat-icon { background: #f5f3ff; color: #8b5cf6; }
    
    /* Table Container */
    .table-container {
      background: white;
      border-radius: 20px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.04);
      overflow: hidden;
    }
    
    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px;
      border-bottom: 1px solid #f1f5f9;
      flex-wrap: wrap;
      gap: 16px;
    }
    
    .table-header h2 {
      font-size: 18px;
      font-weight: 700;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .table-header h2 i {
      color: #6366f1;
    }
    
    .search-box {
      position: relative;
    }
    
    .search-box input {
      padding: 10px 16px 10px 42px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font-size: 14px;
      width: 260px;
      transition: all 0.2s;
    }
    
    .search-box input:focus {
      outline: none;
      border-color: #6366f1;
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    
    .search-box i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
    }
    
    /* Table */
    table {
      width: 100%;
      border-collapse: collapse;
    }
    
    table th {
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
    
    table td {
      padding: 18px 24px;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
      font-size: 14px;
    }
    
    table tbody tr {
      transition: all 0.2s;
      cursor: pointer;
    }
    
    table tbody tr:hover {
      background: linear-gradient(135deg, #f8faff 0%, #faf8ff 100%);
      transform: scale(1.005);
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
    }
    
    table tbody tr:last-child td {
      border-bottom: none;
    }
    
    /* Date column */
    .date-cell {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .date-badge {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      border-radius: 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
    }
    
    .date-badge .day {
      font-size: 18px;
      font-weight: 700;
      line-height: 1;
    }
    
    .date-badge .month {
      font-size: 10px;
      font-weight: 500;
      text-transform: uppercase;
      opacity: 0.9;
    }
    
    .date-info .weekday {
      font-weight: 600;
      color: #1e293b;
    }
    
    .date-info .full-date {
      font-size: 12px;
      color: #64748b;
    }
    
    /* Time */
    .time-cell {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .time-cell i {
      color: #6366f1;
    }
    
    .time-cell .time {
      font-weight: 600;
      color: #1e293b;
    }
    
    /* Patient */
    .patient-cell {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .patient-avatar {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #e0e7ff 0%, #f0e6ff 100%);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #6366f1;
      font-weight: 600;
    }
    
    .patient-info .name {
      font-weight: 600;
      color: #1e293b;
    }
    
    .patient-info .phone {
      font-size: 12px;
      color: #64748b;
    }
    
    /* Service */
    .service-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: #f1f5f9;
      border-radius: 8px;
      font-size: 13px;
      color: #475569;
    }
    
    .service-badge i {
      color: #6366f1;
    }
    
    /* Status */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
    }
    
    .status-badge .dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
    }
    
    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 80px 40px;
    }
    
    .empty-state .icon {
      width: 100px;
      height: 100px;
      background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
      font-size: 40px;
      color: #6366f1;
    }
    
    .empty-state h3 {
      font-size: 20px;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 8px;
    }
    
    .empty-state p {
      color: #64748b;
      font-size: 14px;
    }
    
    /* Today highlight */
    .today-row {
      background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%) !important;
      border-left: 3px solid #6366f1;
    }
    
    .today-row .date-badge {
      background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
      main { margin-left: 0; padding: 20px; }
      .stats-grid { grid-template-columns: repeat(3, 1fr); }
    }
    
    @media (max-width: 768px) {
      .stats-grid { grid-template-columns: 1fr; }
      .page-header { flex-direction: column; align-items: flex-start; }
      .search-box input { width: 100%; }
      table { font-size: 13px; }
      table th, table td { padding: 12px 16px; }
    }
    
    /* Modal Styles */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    
    .modal-overlay.active {
      display: flex;
    }
    
    .modal-content {
      background: white;
      border-radius: 24px;
      width: 100%;
      max-width: 480px;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      animation: modalSlide 0.3s ease;
    }
    
    @keyframes modalSlide {
      from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    
    .modal-header {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      padding: 24px;
      color: white;
      position: relative;
    }
    
    .modal-header h3 {
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    
    .modal-header p {
      font-size: 14px;
      opacity: 0.9;
    }
    
    .modal-close {
      position: absolute;
      top: 20px;
      right: 20px;
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.2);
      border: none;
      color: white;
      font-size: 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
    }
    
    .modal-close:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }
    
    .modal-body {
      padding: 24px;
    }
    
    .detail-item {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 16px 0;
      border-bottom: 1px solid #f1f5f9;
    }
    
    .detail-item:last-child {
      border-bottom: none;
    }
    
    .detail-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    
    .detail-icon.patient { background: #eff6ff; color: #3b82f6; }
    .detail-icon.service { background: #f5f3ff; color: #8b5cf6; }
    .detail-icon.date { background: #ecfdf5; color: #10b981; }
    .detail-icon.time { background: #fff7ed; color: #f97316; }
    .detail-icon.status { background: #fef2f2; color: #ef4444; }
    
    .detail-content {
      flex: 1;
    }
    
    .detail-label {
      font-size: 12px;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    
    .detail-value {
      font-size: 16px;
      font-weight: 600;
      color: #1e293b;
    }
    
    .modal-footer {
      padding: 20px 24px;
      background: #f8fafc;
      border-top: 1px solid #f1f5f9;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }
    
    .modal-btn {
      padding: 12px 24px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      transition: all 0.2s;
    }
    
    .modal-btn-secondary {
      background: white;
      color: #475569;
      border: 1px solid #e2e8f0;
    }
    
    .modal-btn-secondary:hover {
      background: #f8fafc;
    }
    
    .modal-btn-primary {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      color: white;
    }
    
    .modal-btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
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
        <i class="fas fa-calendar-check"></i>
      </div>
      <div>
        <h1>Миний хуваарь</h1>
        <p><?= htmlspecialchars($name) ?> - Ирэх захиалгууд</p>
      </div>
    </div>
    <div class="header-actions">
      <button class="btn btn-secondary" onclick="location.reload()">
        <i class="fas fa-sync-alt"></i>
        Шинэчлэх
      </button>
      <a href="my_hours.php" class="btn btn-primary">
        <i class="fas fa-clock"></i>
        Ажлын цаг
      </a>
    </div>
  </div>
  
  <!-- Stats Grid -->
  <div class="stats-grid">
    <div class="stat-card today">
      <div class="stat-icon">
        <i class="fas fa-calendar-day"></i>
      </div>
      <div class="stat-value"><?= $todayCount ?></div>
      <div class="stat-label">Өнөөдрийн захиалга</div>
    </div>
    <div class="stat-card week">
      <div class="stat-icon">
        <i class="fas fa-calendar-week"></i>
      </div>
      <div class="stat-value"><?= $weekCount ?></div>
      <div class="stat-label">Энэ 7 хоногт</div>
    </div>
    <div class="stat-card total">
      <div class="stat-icon">
        <i class="fas fa-clipboard-list"></i>
      </div>
      <div class="stat-value"><?= count($bookings) ?></div>
      <div class="stat-label">Нийт ирэх захиалга</div>
    </div>
  </div>
  
  <!-- Table Container -->
  <div class="table-container">
    <div class="table-header">
      <h2>
        <i class="fas fa-list-check"></i>
        Захиалгын жагсаалт
      </h2>
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="Хайх..." onkeyup="filterTable()">
      </div>
    </div>
    
    <?php if (empty($bookings)): ?>
      <div class="empty-state">
        <div class="icon">
          <i class="fas fa-calendar-xmark"></i>
        </div>
        <h3>Захиалга байхгүй</h3>
        <p>Танд ирэх хуваарь одоогоор алга байна.</p>
      </div>
    <?php else: ?>
      <table id="scheduleTable">
        <thead>
          <tr>
            <th>Огноо</th>
            <th>Цаг</th>
            <th>Үйлчлүүлэгч</th>
            <th>Үйлчилгээ</th>
            <th>Төлөв</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        $weekdays = ['Ням', 'Дав', 'Мяг', 'Лха', 'Пүр', 'Баа', 'Бям'];
        $months = ['', '1-р сар', '2-р сар', '3-р сар', '4-р сар', '5-р сар', '6-р сар', '7-р сар', '8-р сар', '9-р сар', '10-р сар', '11-р сар', '12-р сар'];
        
        foreach ($bookings as $b): 
          $dateObj = new DateTime($b['date']);
          $dayNum = $dateObj->format('j');
          $monthNum = (int)$dateObj->format('n');
          $weekdayNum = (int)$dateObj->format('w');
          $isToday = $b['date'] === $today;
          
          $status = $b['status'] ?? 'pending';
          $statusInfo = $statusLabels[$status] ?? $statusLabels['pending'];
          
          // JSON data for modal
          $bookingData = htmlspecialchars(json_encode([
            'id' => $b['id'],
            'patient_name' => $b['patient_name'] ?? '-',
            'service_name' => $b['service_name'] ?? '-',
            'date' => $b['date'],
            'start_time' => substr($b['start_time'], 0, 5),
            'end_time' => substr($b['end_time'], 0, 5),
            'status' => $status,
            'status_label' => $statusInfo['label'],
            'status_color' => $statusInfo['color'],
            'status_bg' => $statusInfo['bg'],
            'weekday' => $weekdays[$weekdayNum] . ' гараг'
          ]), ENT_QUOTES);
        ?>
          <tr class="booking-row <?= $isToday ? 'today-row' : '' ?>" data-booking='<?= $bookingData ?>' onclick="showBookingDetail(this)">
            <td>
              <div class="date-cell">
                <div class="date-badge">
                  <span class="day"><?= $dayNum ?></span>
                  <span class="month"><?= $monthNum ?>-р</span>
                </div>
                <div class="date-info">
                  <div class="weekday"><?= $weekdays[$weekdayNum] ?> гараг</div>
                  <div class="full-date"><?= htmlspecialchars($b['date']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="time-cell">
                <i class="fas fa-clock"></i>
                <span class="time"><?= htmlspecialchars(substr($b['start_time'],0,5)) ?> - <?= htmlspecialchars(substr($b['end_time'],0,5)) ?></span>
              </div>
            </td>
            <td>
              <div class="patient-cell">
                <div class="patient-avatar">
                  <?= mb_substr($b['patient_name'] ?? 'Ү', 0, 1, 'UTF-8') ?>
                </div>
                <div class="patient-info">
                  <div class="name"><?= htmlspecialchars($b['patient_name'] ?? '-') ?></div>
                  <div class="phone"><?= htmlspecialchars($b['patient_phone'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="service-badge">
                <i class="fas fa-tooth"></i>
                <?= htmlspecialchars($b['service_name'] ?? '-') ?>
              </span>
            </td>
            <td>
              <span class="status-badge" style="background: <?= $statusInfo['bg'] ?>; color: <?= $statusInfo['color'] ?>;">
                <span class="dot" style="background: <?= $statusInfo['color'] ?>;"></span>
                <?= $statusInfo['label'] ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>

<script>
function filterTable() {
  const input = document.getElementById('searchInput');
  const filter = input.value.toLowerCase();
  const table = document.getElementById('scheduleTable');
  if (!table) return;
  
  const rows = table.getElementsByTagName('tr');
  
  for (let i = 1; i < rows.length; i++) {
    const cells = rows[i].getElementsByTagName('td');
    let found = false;
    
    for (let j = 0; j < cells.length; j++) {
      if (cells[j].textContent.toLowerCase().includes(filter)) {
        found = true;
        break;
      }
    }
    
    rows[i].style.display = found ? '' : 'none';
  }
}

function showBookingDetail(row) {
  const data = JSON.parse(row.dataset.booking);
  
  document.getElementById('modalPatient').textContent = data.patient_name;
  document.getElementById('modalService').textContent = data.service_name;
  document.getElementById('modalDate').textContent = data.date + ' (' + data.weekday + ')';
  document.getElementById('modalTime').textContent = data.start_time + ' - ' + data.end_time;
  
  const statusEl = document.getElementById('modalStatus');
  statusEl.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;background:' + data.status_bg + ';color:' + data.status_color + ';"><span style="width:6px;height:6px;border-radius:50%;background:' + data.status_color + ';"></span>' + data.status_label + '</span>';
  
  document.getElementById('bookingModal').classList.add('active');
}

function closeModal() {
  document.getElementById('bookingModal').classList.remove('active');
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('bookingModal');
  modal.addEventListener('click', function(e) {
    if (e.target === modal) {
      closeModal();
    }
  });
  
  // Close on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeModal();
    }
  });
});
</script>

<!-- Booking Detail Modal -->
<div class="modal-overlay" id="bookingModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-calendar-check"></i> Захиалгын дэлгэрэнгүй</h3>
      <p>Үйлчлүүлэгчийн мэдээлэл</p>
      <button class="modal-close" onclick="closeModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body">
      <div class="detail-item">
        <div class="detail-icon patient">
          <i class="fas fa-user"></i>
        </div>
        <div class="detail-content">
          <div class="detail-label">Үйлчлүүлэгч</div>
          <div class="detail-value" id="modalPatient">-</div>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-icon service">
          <i class="fas fa-tooth"></i>
        </div>
        <div class="detail-content">
          <div class="detail-label">Үйлчилгээ</div>
          <div class="detail-value" id="modalService">-</div>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-icon date">
          <i class="fas fa-calendar"></i>
        </div>
        <div class="detail-content">
          <div class="detail-label">Огноо</div>
          <div class="detail-value" id="modalDate">-</div>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-icon time">
          <i class="fas fa-clock"></i>
        </div>
        <div class="detail-content">
          <div class="detail-label">Цаг</div>
          <div class="detail-value" id="modalTime">-</div>
        </div>
      </div>
      <div class="detail-item">
        <div class="detail-icon status">
          <i class="fas fa-info-circle"></i>
        </div>
        <div class="detail-content">
          <div class="detail-label">Төлөв</div>
          <div class="detail-value" id="modalStatus">-</div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-secondary" onclick="closeModal()">
        <i class="fas fa-times"></i> Хаах
      </button>
    </div>
  </div>
</div>
</body>
</html>