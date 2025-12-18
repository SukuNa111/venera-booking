<?php
require_once __DIR__ . '/../config.php';
// Only admin can access reports
require_role(['admin']);

// 🔹 Хугацааны шүүлтүүр
$period = $_GET['period'] ?? 'week';
switch ($period) {
  case 'day':   
    $range = "CURRENT_DATE"; 
    $label = "Өнөөдөр"; 
    break;
  case 'month': 
    $range = "CURRENT_DATE - INTERVAL '30 days'"; 
    $label = "Сүүлийн 30 хоног"; 
    break;
  default:      
    $range = "CURRENT_DATE - INTERVAL '7 days'";  
    $label = "Сүүлийн 7 хоног";
}

// 🔹 Эмнэлгүүдийн жагсаалт
$clinics = db()->query("SELECT DISTINCT clinic FROM bookings ORDER BY clinic")->fetchAll(PDO::FETCH_COLUMN);

// 🔹 Тасгуудын жагсаалт
$departments = db()->query("SELECT DISTINCT department FROM bookings WHERE department IS NOT NULL ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// Тасгийн код->Монгол нэрийн зураглал
$departmentNames = [
  'general_surgery' => 'Мэс / ерөнхий',
  'face_surgery' => 'Мэс / нүүр',
  'nose_surgery' => 'Мэс / хамар',
  'oral_surgery' => 'Мэс / амны',
  'hair_clinic' => 'Үс',
  'non_surgical' => 'Мэсийн бус',
  'nonsurgical' => 'Мэсийн бус'
];

// 🔹 Бүх идэвхтэй эмчид
$allDoctorsCount = db()->query("SELECT COUNT(DISTINCT id) FROM doctors WHERE active = 1")->fetchColumn();

// 🔹 Сонгогдсон эмнэлэг
// By default show 'all' clinics, but if the user is a doctor we default
// the active clinic to the doctor's assigned clinic (from session/user).
$u = current_user();
$activeClinic = $_GET['clinic'] ?? (
  (isset($u['role']) && $u['role'] === 'doctor') ? ($u['clinic_id'] ?? 'venera') : 'all'
);

// 🔹 Сонгогдсон тасаг
$activeDepartment = $_GET['department'] ?? 'all';

// 🔹 Excel экспорт
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="report_' . date('Y-m-d_H-i') . '.xls"');
  header('Pragma: no-cache');
  header('Expires: 0');
  
  $exportData = [];
  $grandTotalExport = 0;
  $grandPaidExport = 0;
  $grandRevenueExport = 0;
  
  foreach ($clinics as $clinic) {
    if ($activeClinic !== 'all' && $activeClinic !== $clinic) continue;
    
    $where = "b.clinic = ? AND b.date BETWEEN $range AND CURRENT_DATE";
    $params = [$clinic];
    
    if ($activeDepartment !== 'all') {
      $where .= " AND b.department = ?";
      $params[] = $activeDepartment;
    }
    
    $st = db()->prepare("
      SELECT 
        d.name AS doctor_name,
        b.clinic,
        b.department,
        COUNT(b.id) AS total,
        SUM(CASE WHEN b.status='paid' THEN 1 ELSE 0 END) AS paid_count,
        COALESCE(SUM(CASE WHEN b.status='paid' THEN b.price ELSE 0 END), 0) AS paid_revenue
      FROM bookings b
      JOIN doctors d ON d.id = b.doctor_id
      WHERE $where
      GROUP BY d.id, b.clinic, b.department
      ORDER BY total DESC
    ");
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);
    $exportData[$clinic] = $data;
    
    $grandTotalExport += array_sum(array_column($data, 'total'));
    $grandPaidExport += array_sum(array_column($data, 'paid_count'));
    $grandRevenueExport += array_sum(array_column($data, 'paid_revenue'));
  }
  
  echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
  echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
  echo "<tr><th colspan='7' style='background: #3b82f6; color: white; padding: 15px; font-size: 18px;'>ТАЙЛАН - {$label}</th></tr>";
  echo "<tr><th colspan='7' style='background: #f8f9fa; padding: 10px;'>Экспорт хийсэн огноо: " . date('Y-m-d H:i') . "</th></tr>";
  
  foreach ($exportData as $clinic => $rows) {
    $clinicTotal = array_sum(array_column($rows, 'total'));
    $clinicPaid = array_sum(array_column($rows, 'paid_count'));
    $clinicRevenue = array_sum(array_column($rows, 'paid_revenue'));
    $clinicRate = $clinicTotal ? round(($clinicPaid / $clinicTotal) * 100, 1) : 0;
    
    echo "<tr><td colspan='7' style='background: #e9ecef; padding: 12px; font-weight: bold;'>Эмнэлэг: " . strtoupper($clinic) . " (Нийт: {$clinicTotal}, Төлсөн: {$clinicPaid}, Орлого: " . number_format($clinicRevenue, 0, '.', ',') . "₮, Гүйцэтгэл: {$clinicRate}%)</td></tr>";
    echo "<tr style='background: #f1f3f4;'>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>#</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Эмч</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Нийт захиалга</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Төлсөн</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Орлого</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Гүйцэтгэл</th>";
    echo "<th style='padding: 10px; border: 1px solid #ddd;'>Хувь</th>";
    echo "</tr>";
    
    foreach ($rows as $i => $r) {
      $p = $r['total'] ? round(($r['paid_count'] / $r['total']) * 100, 1) : 0;
      echo "<tr>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . ($i + 1) . "</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$r['doctor_name']}</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$r['total']}</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$r['paid_count']}</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd; color: #059669; font-weight: bold;'>" . number_format($r['paid_revenue'], 0, '.', ',') . "₮</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$p}%</td>";
      echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . str_repeat('█', min(10, round($p/10))) . "</td>";
      echo "</tr>";
    }
    echo "<tr style='height: 15px;'><td colspan='7'></td></tr>";
  }
  
  $grandRateExport = $grandTotalExport ? round(($grandPaidExport / $grandTotalExport) * 100, 1) : 0;
  echo "<tr><td colspan='7' style='background: #1f2937; color: white; padding: 12px; font-weight: bold;'>Нийт: Захиалга: {$grandTotalExport}, Төлсөн: {$grandPaidExport}, Орлого: " . number_format($grandRevenueExport, 0, '.', ',') . "₮, Гүйцэтгэл: {$grandRateExport}%</td></tr>";
  echo "</table></body></html>";
  exit;
}

// 🔹 Бүх эмнэлгүүдийн мэдээлэл цуглуулах
$allData = [];
$grandTotal = 0;
$grandPaid = 0;
$grandRevenue = 0;
$grandPaidRevenue = 0;

foreach ($clinics as $clinic) {
  if ($activeClinic !== 'all' && $activeClinic !== $clinic) continue;
  
  $st = db()->prepare("
    SELECT 
      d.id AS doctor_id,
      d.name AS doctor_name,
      b.clinic,
      COUNT(b.id) AS total,
      SUM(CASE WHEN b.status='paid' THEN 1 ELSE 0 END) AS paid_count,
      SUM(CASE WHEN b.status='online' THEN 1 ELSE 0 END) AS online_count,
      SUM(CASE WHEN b.status='arrived' THEN 1 ELSE 0 END) AS arrived_count,
      SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending_count,
      SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
      COALESCE(SUM(b.price), 0) AS total_revenue,
      COALESCE(SUM(CASE WHEN b.status='paid' THEN b.price ELSE 0 END), 0) AS paid_revenue
    FROM bookings b
    JOIN doctors d ON d.id = b.doctor_id
    WHERE b.clinic = ?
      AND b.date BETWEEN $range AND CURRENT_DATE
    GROUP BY d.id, b.clinic
    ORDER BY total DESC
  ");
  $st->execute([$clinic]);
  $data = $st->fetchAll(PDO::FETCH_ASSOC);
  
  $allData[$clinic] = $data;
  $grandTotal += array_sum(array_column($data, 'total'));
  $grandPaid += array_sum(array_column($data, 'paid_count'));
  $grandRevenue += array_sum(array_column($data, 'total_revenue'));
  $grandPaidRevenue += array_sum(array_column($data, 'paid_revenue'));
}

$grandRate = $grandTotal ? round(($grandPaid / $grandTotal) * 100, 1) : 0;

// 🔹 Status counts (онлайн, хүлээгдэж буй, ирсэн)
$where = "b.date BETWEEN $range AND CURRENT_DATE";
$params = [];

if ($activeClinic !== 'all') {
  $where .= " AND b.clinic = ?";
  $params[] = $activeClinic;
}

if ($activeDepartment !== 'all') {
  $where .= " AND b.department = ?";
  $params[] = $activeDepartment;
}

$statusSt = db()->prepare("
  SELECT 
    SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) AS online_count,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status='arrived' THEN 1 ELSE 0 END) AS arrived_count
  FROM bookings b
  WHERE $where
");
$statusSt->execute($params);
$statusRow = $statusSt->fetch(PDO::FETCH_ASSOC);
$onlineCount = $statusRow['online_count'] ?? 0;
$pendingCount = $statusRow['pending_count'] ?? 0;
$arrivedCount = $statusRow['arrived_count'] ?? 0;

// 🔹 TOP 3 эмч бүх эмнэлгээс
$allDoctors = [];
foreach ($allData as $clinicData) {
  foreach ($clinicData as $doctor) {
    $allDoctors[] = $doctor;
  }
}

usort($allDoctors, function($a, $b) {
  $rateA = $a['total'] ? ($a['paid_count'] / $a['total']) : 0;
  $rateB = $b['total'] ? ($b['paid_count'] / $b['total']) : 0;
  return $rateB <=> $rateA;
});

$top = array_slice($allDoctors, 0, 3);

// 🔹 Chart data
$chartLabels = [];
$chartTotals = [];
$chartPaids = [];

foreach ($allData as $clinic => $data) {
  foreach ($data as $row) {
    $chartLabels[] = $row['doctor_name'] . " (" . strtoupper(substr($clinic, 0, 3)) . ")";
    $chartTotals[] = $row['total'];
    $chartPaids[] = $row['paid_count'];
  }
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>📊 Тайлан - Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
      padding: 2rem 2.5rem;
      margin-bottom: 2rem;
      color: white;
      box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
    }

    .page-header h1 {
      font-size: 1.75rem;
      font-weight: 800;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .page-header p {
      opacity: 0.9;
      font-size: 0.95rem;
      margin: 0;
    }

    /* Filter Section */
    .filter-section {
      background: white;
      border-radius: 16px;
      padding: 1.25rem 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid var(--border);
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      align-items: center;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .filter-group label {
      font-weight: 600;
      color: #475569;
      font-size: 0.9rem;
      white-space: nowrap;
    }

    .filter-select {
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.6rem 1rem;
      font-weight: 500;
      background: #f8fafc;
      color: #1e293b;
      min-width: 180px;
      transition: all 0.2s ease;
    }

    .filter-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
      outline: none;
    }

    /* Period Buttons */
    .period-buttons {
      display: flex;
      gap: 0;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 10px;
      border: 1px solid #e2e8f0;
    }

    .period-btn {
      padding: 0.5rem 1rem;
      border: none;
      background: transparent;
      color: #64748b;
      font-weight: 600;
      font-size: 0.85rem;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .period-btn:hover {
      color: var(--primary);
    }

    .period-btn.active {
      background: white;
      color: var(--primary);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .btn-export {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border: none;
      padding: 0.6rem 1.25rem;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.9rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.2s ease;
      text-decoration: none;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }

    .btn-export:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
      color: white;
    }

    /* Stats Cards */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 1.25rem;
      margin-bottom: 1rem;
    }

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
      bottom: 0;
      left: 0;
      right: 0;
      height: 4px;
    }

    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    .stat-card .icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      margin-bottom: 1rem;
    }

    .stat-card .label {
      font-size: 0.85rem;
      color: #64748b;
      font-weight: 500;
      margin-bottom: 0.25rem;
    }

    .stat-card .number {
      font-size: 2rem;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 0.25rem;
    }

    .stat-card .sub-label {
      font-size: 0.8rem;
      color: #94a3b8;
    }

    .stat-card.online .icon { background: #eff6ff; color: #3b82f6; }
    .stat-card.online .number { color: #3b82f6; }
    .stat-card.online::before { background: #3b82f6; }

    .stat-card.arrived .icon { background: #fffbeb; color: #f59e0b; }
    .stat-card.arrived .number { color: #f59e0b; }
    .stat-card.arrived::before { background: #f59e0b; }

    .stat-card.pending .icon { background: #f5f3ff; color: #8b5cf6; }
    .stat-card.pending .number { color: #8b5cf6; }
    .stat-card.pending::before { background: #8b5cf6; }

    .stat-card.paid .icon { background: #ecfdf5; color: #10b981; }
    .stat-card.paid .number { color: #10b981; }
    .stat-card.paid::before { background: #10b981; }

    .stat-card.cancelled .icon { background: #fef2f2; color: #ef4444; }
    .stat-card.cancelled .number { color: #ef4444; }
    .stat-card.cancelled::before { background: #ef4444; }

    /* Cards Container */
    .card-container {
      background: white;
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid var(--border);
      margin-bottom: 1.5rem;
    }

    .card-container h5 {
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .card-container h5 i {
      color: var(--primary);
    }

    /* Top 3 Doctors */
    .top-doctors {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1.25rem;
    }

    .top-doctor-card {
      background: #f8fafc;
      border-radius: 14px;
      padding: 1.5rem;
      border: 1px solid #e2e8f0;
      transition: all 0.3s ease;
    }

    .top-doctor-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
    }

    .top-doctor-card:nth-child(1) { border-left: 4px solid #fbbf24; }
    .top-doctor-card:nth-child(2) { border-left: 4px solid #94a3b8; }
    .top-doctor-card:nth-child(3) { border-left: 4px solid #d97706; }

    .medal-icon {
      font-size: 2rem;
      margin-bottom: 0.75rem;
    }

    .top-doctor-card:nth-child(1) .medal-icon { color: #fbbf24; }
    .top-doctor-card:nth-child(2) .medal-icon { color: #94a3b8; }
    .top-doctor-card:nth-child(3) .medal-icon { color: #d97706; }

    .top-doctor-card h6 {
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .clinic-badge {
      display: inline-block;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 0.3rem 0.75rem;
      border-radius: 50px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .top-stats {
      margin-top: 1rem;
    }

    .top-stats .row {
      display: flex;
      justify-content: space-between;
      font-size: 0.85rem;
      padding: 0.3rem 0;
    }

    .top-stats .row span:first-child {
      color: #64748b;
    }

    .top-stats .row span:last-child {
      font-weight: 600;
      color: #1e293b;
    }

    .progress {
      height: 8px;
      border-radius: 8px;
      background: #e2e8f0;
      margin-top: 0.75rem;
      overflow: hidden;
    }

    .progress-bar {
      border-radius: 8px;
      transition: width 0.8s ease;
    }

    /* Charts Grid */
    .charts-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .chart-container {
      position: relative;
      height: 300px;
    }

    /* Table */
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table thead th {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      font-weight: 600;
      font-size: 0.85rem;
      padding: 1rem 1.25rem;
      text-align: left;
    }

    .data-table thead th:first-child {
      border-radius: 12px 0 0 12px;
    }

    .data-table thead th:last-child {
      border-radius: 0 12px 12px 0;
    }

    .data-table tbody tr {
      border-bottom: 1px solid #f1f5f9;
      transition: all 0.2s ease;
    }

    .data-table tbody tr:hover {
      background: #f8fafc;
    }

    .data-table tbody td {
      padding: 1rem 1.25rem;
      vertical-align: middle;
    }

    .badge-total {
      background: #eff6ff;
      color: #3b82f6;
      padding: 0.4rem 0.8rem;
      border-radius: 50px;
      font-weight: 600;
      font-size: 0.85rem;
    }

    .badge-paid {
      background: #ecfdf5;
      color: #10b981;
      padding: 0.4rem 0.8rem;
      border-radius: 50px;
      font-weight: 600;
      font-size: 0.85rem;
    }

    .progress-cell {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .progress-cell .progress {
      flex: 1;
      margin: 0;
    }

    .progress-cell .percent {
      font-weight: 700;
      font-size: 0.9rem;
      min-width: 45px;
      text-align: right;
    }

    .btn-detail {
      background: #f1f5f9;
      color: #6366f1;
      border: 1px solid #e2e8f0;
      padding: 0.4rem 0.8rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-detail:hover {
      background: #ede9fe;
      border-color: #c4b5fd;
    }

    /* Responsive */
    @media (max-width: 1400px) {
      .stats-row {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    @media (max-width: 1200px) {
      .charts-grid {
        grid-template-columns: 1fr;
      }
      .top-doctors {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 992px) {
      main {
        margin-left: 0;
        padding: 1.5rem;
      }
      .stats-row {
        grid-template-columns: repeat(2, 1fr);
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

    .page-header, .filter-section, .stat-card, .card-container {
      animation: fadeInUp 0.5s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stat-card:nth-child(2) { animation-delay: 0.15s; }
    .stat-card:nth-child(3) { animation-delay: 0.2s; }
    .stat-card:nth-child(4) { animation-delay: 0.25s; }
    .stat-card:nth-child(5) { animation-delay: 0.3s; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <!-- Page Header -->
    <div class="page-header">
      <h1><i class="fas fa-chart-line"></i> Дэвшилтэт Тайлан</h1>
      <p>Бүх эмнэлгүүдийн гүйцэтгэлийн цогц тайлан | Бүх эмч: <strong><?= $allDoctorsCount ?></strong></p>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
      <form method="get" class="d-flex flex-wrap gap-3 align-items-center flex-grow-1">
        <div class="filter-group">
          <label>Эмнэлэг:</label>
          <select name="clinic" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $activeClinic=='all'?'selected':'' ?>>🏥 Бүх эмнэлэг</option>
            <?php foreach ($clinics as $c): ?>
              <option value="<?= $c ?>" <?= $c==$activeClinic?'selected':'' ?>><?= strtoupper($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="filter-group">
          <label>Тасаг:</label>
          <select name="department" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $activeDepartment=='all'?'selected':'' ?>>🏷️ Бүх тасаг</option>
            <?php foreach ($departments as $d): 
              $label = $departmentNames[$d] ?? ($d ?: 'Тодорхойгүй');
            ?>
              <option value="<?= htmlspecialchars($d) ?>" <?= $d==$activeDepartment?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <input type="hidden" name="period" value="<?= $period ?>">
        
        <div class="period-buttons ms-auto">
          <a href="?clinic=<?= $activeClinic ?>&department=<?= $activeDepartment ?>&period=day" class="period-btn <?= $period==='day'?'active':'' ?>">
            <i class="fas fa-sun"></i> Өдөр
          </a>
          <a href="?clinic=<?= $activeClinic ?>&department=<?= $activeDepartment ?>&period=week" class="period-btn <?= $period==='week'?'active':'' ?>">
            <i class="fas fa-calendar-week"></i> 7 хоног
          </a>
          <a href="?clinic=<?= $activeClinic ?>&department=<?= $activeDepartment ?>&period=month" class="period-btn <?= $period==='month'?'active':'' ?>">
            <i class="fas fa-calendar-alt"></i> Сар
          </a>
        </div>
      </form>
      
      <a href="?clinic=<?= $activeClinic ?>&department=<?= $activeDepartment ?>&period=<?= $period ?>&export=excel" class="btn-export">
        <i class="fas fa-file-excel"></i> Excel
      </a>
    </div>

    <!-- Stats Cards -->
    <div class="stats-row">
      <div class="stat-card online">
        <div class="icon"><i class="fas fa-wifi"></i></div>
        <div class="label">Онлайн</div>
        <div class="number"><?= number_format($onlineCount) ?></div>
        <div class="sub-label">Идэвхтэй захиалга</div>
      </div>
      <div class="stat-card arrived">
        <div class="icon"><i class="fas fa-user-check"></i></div>
        <div class="label">Ирсэн</div>
        <div class="number"><?= number_format($arrivedCount) ?></div>
        <div class="sub-label">Хүлээлтэд</div>
      </div>
      <div class="stat-card pending">
        <div class="icon"><i class="fas fa-hourglass-half"></i></div>
        <div class="label">Хүлээгдэж буй</div>
        <div class="number"><?= number_format($pendingCount) ?></div>
        <div class="sub-label">Баталгаа хүлээж буй</div>
      </div>
      <div class="stat-card paid">
        <div class="icon"><i class="fas fa-check-circle"></i></div>
        <div class="label">Төлсөн</div>
        <div class="number"><?= number_format($grandPaid) ?></div>
        <div class="sub-label">Амжилттай</div>
      </div>
      <div class="stat-card cancelled">
        <div class="icon"><i class="fas fa-times-circle"></i></div>
        <div class="label">Цуцлагдсан</div>
        <div class="number"><?= number_format(max(0, $grandTotal - $grandPaid - $onlineCount - $arrivedCount - $pendingCount)) ?></div>
        <div class="sub-label">Буцаагдсан</div>
      </div>
    </div>

    <!-- Revenue Stats -->
    <div class="stats-row" style="grid-template-columns: repeat(3, 1fr); margin-top: -0.5rem;">
      <div class="stat-card" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
        <div class="icon" style="background: #10b981; color: white;"><i class="fas fa-money-bill-wave"></i></div>
        <div class="label">Төлөгдсөн орлого</div>
        <div class="number" style="color: #059669; font-size: 1.75rem;"><?= number_format($grandPaidRevenue, 0, '.', ',') ?>₮</div>
        <div class="sub-label">Бодит орлого</div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
        <div class="icon" style="background: #3b82f6; color: white;"><i class="fas fa-chart-line"></i></div>
        <div class="label">Нийт борлуулалт</div>
        <div class="number" style="color: #2563eb; font-size: 1.75rem;"><?= number_format($grandRevenue, 0, '.', ',') ?>₮</div>
        <div class="sub-label">Бүх захиалга</div>
      </div>
      <div class="stat-card" style="background: linear-gradient(135deg, #fefce8 0%, #fef9c3 100%);">
        <div class="icon" style="background: #eab308; color: white;"><i class="fas fa-percentage"></i></div>
        <div class="label">Төлбөрийн хувь</div>
        <div class="number" style="color: #ca8a04; font-size: 1.75rem;"><?= $grandRevenue > 0 ? round(($grandPaidRevenue / $grandRevenue) * 100, 1) : 0 ?>%</div>
        <div class="sub-label">Орлогын гүйцэтгэл</div>
      </div>
    </div>

    <!-- Top 3 Doctors -->
    <div class="card-container">
      <h5><i class="fas fa-trophy"></i> Шилдэг 3 Эмч</h5>
      <div class="top-doctors">
        <?php foreach ($top as $index => $t): 
          $pr = $t['total'] ? round(($t['paid_count'] / $t['total']) * 100, 1) : 0;
          $medals = ['🥇', '🥈', '🥉'];
        ?>
          <div class="top-doctor-card">
            <div class="medal-icon">
              <?php if ($index === 0): ?><i class="fas fa-crown"></i>
              <?php elseif ($index === 1): ?><i class="fas fa-medal"></i>
              <?php else: ?><i class="fas fa-award"></i>
              <?php endif; ?>
            </div>
            <h6><?= $medals[$index] ?> <?= htmlspecialchars($t['doctor_name']) ?></h6>
            <span class="clinic-badge"><?= strtoupper($t['clinic']) ?></span>
            <div class="top-stats">
              <div class="row"><span>Нийт</span><span style="color: #3b82f6;"><?= $t['total'] ?></span></div>
              <div class="row"><span>Төлбөртэй</span><span style="color: #10b981;"><?= $t['paid_count'] ?></span></div>
              <div class="row"><span>Орлого</span><span style="color: #059669; font-weight: 700;"><?= number_format($t['paid_revenue'] ?? 0, 0, '.', ',') ?>₮</span></div>
            </div>
            <div class="progress">
              <div class="progress-bar bg-success" style="width: <?= $pr ?>%"></div>
            </div>
            <div class="row mt-2"><span style="font-size: 0.8rem; color: #64748b;">Гүйцэтгэл</span><span style="font-weight: 700; color: #10b981;"><?= $pr ?>%</span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
      <div class="card-container">
        <h5><i class="fas fa-chart-bar"></i> Захиалгын харьцуулалт</h5>
        <div class="chart-container">
          <canvas id="barChart"></canvas>
        </div>
      </div>
      <div class="card-container">
        <h5><i class="fas fa-chart-pie"></i> Төлбөртэй хуваарилалт</h5>
        <div class="chart-container">
          <canvas id="pieChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="card-container">
      <h5><i class="fas fa-table"></i> Дэлгэрэнгүй статистик</h5>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <?php if ($activeClinic === 'all'): ?>
                <th>Эмнэлэг</th>
              <?php endif; ?>
              <th>Эмч</th>
              <th>Нийт</th>
              <th>Төлсөн</th>
              <th>Орлого</th>
              <th>Гүйцэтгэл</th>
              <th>Үйлдэл</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $counter = 1;
            foreach ($allData as $clinic => $rows): 
              foreach ($rows as $r): 
                $p = $r['total'] ? round(($r['paid_count'] / $r['total']) * 100, 1) : 0;
                $progressColor = $p >= 80 ? 'bg-success' : ($p >= 50 ? 'bg-warning' : 'bg-danger');
            ?>
              <tr>
                <td style="font-weight: 600; color: var(--primary);"><?= $counter++ ?></td>
                <?php if ($activeClinic === 'all'): ?>
                  <td><span class="clinic-badge"><?= strtoupper($clinic) ?></span></td>
                <?php endif; ?>
                <td style="font-weight: 600;">
                  <i class="fas fa-user-md me-2" style="color: #94a3b8;"></i>
                  <?= htmlspecialchars($r['doctor_name']) ?>
                </td>
                <td><span class="badge-total"><?= $r['total'] ?></span></td>
                <td><span class="badge-paid"><?= $r['paid_count'] ?></span></td>
                <td><span class="badge-revenue" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"><?= number_format($r['paid_revenue'] ?? 0, 0, '.', ',') ?>₮</span></td>
                <td>
                  <div class="progress-cell">
                    <div class="progress">
                      <div class="progress-bar <?= $progressColor ?>" style="width: <?= $p ?>%"></div>
                    </div>
                    <span class="percent"><?= $p ?>%</span>
                  </div>
                </td>
                <td>
                  <button class="btn-detail" 
                    data-doctor-id="<?= $r['doctor_id'] ?>"
                    data-doctor-name="<?= htmlspecialchars($r['doctor_name'], ENT_QUOTES) ?>"
                    data-clinic="<?= $clinic ?>"
                    data-total="<?= $r['total'] ?>"
                    data-paid="<?= $r['paid_count'] ?>"
                    data-revenue="<?= $r['paid_revenue'] ?? 0 ?>"
                    data-online="<?= $r['online_count'] ?? 0 ?>"
                    data-arrived="<?= $r['arrived_count'] ?? 0 ?>"
                    data-pending="<?= $r['pending_count'] ?? 0 ?>"
                    data-cancelled="<?= $r['cancelled_count'] ?? 0 ?>"
                    onclick="showDoctorDetailFromButton(this)">
                    <i class="fas fa-chart-line me-1"></i>Дэлгэрэнгүй
                  </button>
                </td>
              </tr>
            <?php endforeach; endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Doctor Detail Modal -->
    <div class="modal fade" id="doctorDetailModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
          <div class="modal-header" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none; padding: 1.5rem 2rem;">
            <h5 class="modal-title text-white" id="modalDoctorName" style="font-weight: 700;"><i class="fas fa-user-md me-2"></i>Эмчийн мэдээлэл</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" style="padding: 2rem;">
            <div class="row mb-4">
              <div class="col-12">
                <div class="d-flex align-items-center gap-3 mb-4" style="background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 100%); padding: 1.25rem; border-radius: 16px;">
                  <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-user-md text-white" style="font-size: 1.5rem;"></i>
                  </div>
                  <div>
                    <h4 id="detailDoctorName" style="font-weight: 700; margin-bottom: 0.25rem; color: #1e293b;">-</h4>
                    <p id="detailClinic" style="margin: 0; color: #64748b; font-size: 0.9rem;"><i class="fas fa-hospital me-1"></i>-</p>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="row g-3 mb-4">
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #eff6ff; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-calendar-check" style="font-size: 1.5rem; color: #3b82f6; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailTotal" style="font-weight: 700; color: #1e40af; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Нийт захиалга</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #f0fdf4; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-check-circle" style="font-size: 1.5rem; color: #10b981; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailPaid" style="font-weight: 700; color: #059669; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Төлсөн</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-money-bill-wave" style="font-size: 1.5rem; color: #059669; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailRevenue" style="font-weight: 700; color: #047857; margin-bottom: 0.25rem; font-size: 1.25rem;">0₮</h3>
                  <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Орлого</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-percentage" style="font-size: 1.5rem; color: #d97706; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailRate" style="font-weight: 700; color: #b45309; margin-bottom: 0.25rem;">0%</h3>
                  <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Гүйцэтгэл</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #dbeafe; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-globe" style="font-size: 1.25rem; color: #2563eb; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailOnline" style="font-weight: 700; color: #1d4ed8; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.75rem; color: #64748b; margin: 0;">Онлайн</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #fef3c7; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-user-check" style="font-size: 1.25rem; color: #d97706; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailArrived" style="font-weight: 700; color: #b45309; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.75rem; color: #64748b; margin: 0;">Ирсэн</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #ede9fe; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-clock" style="font-size: 1.25rem; color: #7c3aed; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailPending" style="font-weight: 700; color: #6d28d9; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.75rem; color: #64748b; margin: 0;">Хүлээгдэж буй</p>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="detail-stat-card" style="background: #fee2e2; border-radius: 12px; padding: 1rem; text-align: center;">
                  <i class="fas fa-times-circle" style="font-size: 1.25rem; color: #dc2626; margin-bottom: 0.5rem;"></i>
                  <h3 id="detailCancelled" style="font-weight: 700; color: #b91c1c; margin-bottom: 0.25rem;">0</h3>
                  <p style="font-size: 0.75rem; color: #64748b; margin: 0;">Цуцлагдсан</p>
                </div>
              </div>
            </div>

            <div style="background: #f8fafc; border-radius: 12px; padding: 1rem;">
              <h6 style="font-weight: 600; color: #475569; margin-bottom: 1rem;"><i class="fas fa-chart-pie me-2"></i>Статус хуваарилалт</h6>
              <div style="position: relative; height: 180px; width: 100%;">
                <canvas id="detailPieChart"></canvas>
              </div>
            </div>
          </div>
          <div class="modal-footer" style="border: none; padding: 1rem 2rem 1.5rem;">
            <button type="button" class="btn" data-bs-dismiss="modal" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border-radius: 10px; padding: 0.75rem 2rem; font-weight: 600;">Хаах</button>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
  // === Chart.js Configuration ===
  const barCtx = document.getElementById('barChart').getContext('2d');
  const barChart = new Chart(barCtx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [
        {
          label: 'Нийт захиалга',
          data: <?= json_encode($chartTotals) ?>,
          backgroundColor: 'rgba(59, 130, 246, 0.8)',
          borderColor: 'rgba(59, 130, 246, 1)',
          borderWidth: 2,
          borderRadius: 8,
          borderSkipped: false,
        },
        {
          label: 'Төлбөртэй',
          data: <?= json_encode($chartPaids) ?>,
          backgroundColor: 'rgba(16, 185, 129, 0.8)',
          borderColor: 'rgba(16, 185, 129, 1)',
          borderWidth: 2,
          borderRadius: 8,
          borderSkipped: false,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            usePointStyle: true,
            padding: 20,
            font: {
              size: 12,
              family: 'Inter'
            }
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          titleColor: '#1f2937',
          bodyColor: '#1f2937',
          borderColor: 'rgba(0, 0, 0, 0.1)',
          borderWidth: 1,
          cornerRadius: 12,
          usePointStyle: true,
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0, 0, 0, 0.05)',
            drawBorder: false
          },
          ticks: {
            font: {
              family: 'Inter'
            }
          }
        },
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: {
              family: 'Inter'
            },
            maxRotation: 45,
            minRotation: 45
          }
        }
      },
      animation: {
        duration: 2000,
        easing: 'easeOutQuart'
      }
    }
  });

  const pieCtx = document.getElementById('pieChart').getContext('2d');
  const pieChart = new Chart(pieCtx, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [{
        data: <?= json_encode($chartPaids) ?>,
        backgroundColor: [
          '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#6366f1', 
          '#14b8a6', '#f97316', '#8b5cf6', '#06b6d4', '#84cc16',
          '#ec4899', '#d946ef', '#0ea5e9', '#22c55e', '#eab308'
        ],
        borderWidth: 3,
        borderColor: '#ffffff',
        hoverOffset: 15
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            usePointStyle: true,
            padding: 20,
            font: {
              size: 11,
              family: 'Inter'
            },
            boxWidth: 12
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          titleColor: '#1f2937',
          bodyColor: '#1f2937',
          borderColor: 'rgba(0, 0, 0, 0.1)',
          borderWidth: 1,
          cornerRadius: 12,
          usePointStyle: true,
        }
      },
      cutout: '65%',
      animation: {
        animateScale: true,
        animateRotate: true,
        duration: 2000,
        easing: 'easeOutQuart'
      }
    }
  });

  // Add smooth scrolling and animations
  document.addEventListener('DOMContentLoaded', function() {
    // Add loading animation
    const elements = document.querySelectorAll('.animate-fade-in');
    elements.forEach((el, index) => {
      el.style.animationDelay = `${index * 0.1}s`;
    });
    
    // Add hover effects to table rows
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach(row => {
      row.addEventListener('mouseenter', function() {
        this.style.transition = 'all 0.3s ease';
      });
    });
  });

  // Update charts on window resize
  window.addEventListener('resize', function() {
    barChart.resize();
    pieChart.resize();
  });

  // Doctor Detail Modal Function
  let detailPieChartInstance = null;

  function showDoctorDetailFromButton(btn) {
    const doctorId = btn.dataset.doctorId;
    const doctorName = btn.dataset.doctorName;
    const clinic = btn.dataset.clinic;
    const total = parseInt(btn.dataset.total) || 0;
    const paid = parseInt(btn.dataset.paid) || 0;
    const revenue = parseFloat(btn.dataset.revenue) || 0;
    const online = parseInt(btn.dataset.online) || 0;
    const arrived = parseInt(btn.dataset.arrived) || 0;
    const pending = parseInt(btn.dataset.pending) || 0;
    const cancelled = parseInt(btn.dataset.cancelled) || 0;
    
    showDoctorDetail(doctorId, doctorName, clinic, total, paid, revenue, online, arrived, pending, cancelled);
  }

  function showDoctorDetail(doctorId, doctorName, clinic, total, paid, revenue, online, arrived, pending, cancelled) {
    // Set values
    document.getElementById('modalDoctorName').innerHTML = '<i class="fas fa-user-md me-2"></i>' + doctorName;
    document.getElementById('detailDoctorName').textContent = doctorName;
    document.getElementById('detailClinic').innerHTML = '<i class="fas fa-hospital me-1"></i>' + clinic.toUpperCase() + ' эмнэлэг';
    document.getElementById('detailTotal').textContent = total;
    document.getElementById('detailPaid').textContent = paid;
    document.getElementById('detailRevenue').textContent = new Intl.NumberFormat('mn-MN').format(revenue) + '₮';
    document.getElementById('detailOnline').textContent = online;
    document.getElementById('detailArrived').textContent = arrived;
    document.getElementById('detailPending').textContent = pending;
    document.getElementById('detailCancelled').textContent = cancelled;
    
    const rate = total > 0 ? Math.round((paid / total) * 100) : 0;
    document.getElementById('detailRate').textContent = rate + '%';

    // Destroy previous chart instance if exists
    if (detailPieChartInstance) {
      detailPieChartInstance.destroy();
    }

    // Create pie chart
    const ctx = document.getElementById('detailPieChart').getContext('2d');
    detailPieChartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Онлайн', 'Ирсэн', 'Хүлээгдэж буй', 'Төлсөн', 'Цуцлагдсан'],
        datasets: [{
          data: [online, arrived, pending, paid, cancelled],
          backgroundColor: [
            '#3b82f6',
            '#f59e0b',
            '#8b5cf6',
            '#10b981',
            '#ef4444'
          ],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              usePointStyle: true,
              padding: 15,
              font: { size: 11, family: 'Inter' }
            }
          }
        },
        cutout: '60%'
      }
    });

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('doctorDetailModal'));
    modal.show();
  }
  </script>
  
  <!-- Bootstrap JS for Modal -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>