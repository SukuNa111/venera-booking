<?php
require_once __DIR__ . '/../config.php';
require_role(['admin', 'super_admin']);

$u = current_user();
$isSuper = is_super_admin();
$userClinic = $u['clinic_id'] ?? 'venera';

// 🔹 Date Filtering
$period = $_GET['period'] ?? 'week';
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-6 days'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');

if ($period === 'day') {
    $from_date = $to_date = date('Y-m-d');
} elseif ($period === 'month') {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
}

// 🔹 Clinic & Department Filtering
$clinics = db()->query("SELECT DISTINCT clinic FROM bookings ORDER BY clinic")->fetchAll(PDO::FETCH_COLUMN);
$activeClinic = $isSuper ? ($_GET['clinic'] ?? 'all') : $userClinic;
$activeDepartment = $_GET['department'] ?? 'all';

// Fix: Filter out empty/null departments
$deptSql = "SELECT DISTINCT department FROM bookings WHERE department IS NOT NULL AND department != ''";
$deptParams = [];
if (!$isSuper) {
    $deptSql .= " AND clinic = ?";
    $deptParams[] = $userClinic;
}
$deptSql .= " ORDER BY department";
$stmtDept = db()->prepare($deptSql);
$stmtDept->execute($deptParams);
$departments = $stmtDept->fetchAll(PDO::FETCH_COLUMN);

// Department Mapping
$departmentNames = [
  'general_surgery' => 'Мэс / ерөнхий',
  'face_surgery' => 'Мэс / нүүр',
  'nose_surgery' => 'Мэс / хамар',
  'oral_surgery' => 'Мэс / амны',
  'hair_clinic' => 'Үс',
  'non_surgical' => 'Мэсийн бус',
  'nonsurgical' => 'Мэсийн бус',
  'Мэс засал' => 'Мэс засал',
  'Мэсийн бус' => 'Мэсийн бус',
  'Уламжлалт' => 'Уламжлалт',
  'Шүд' => 'Шүд',
  'Дусал' => 'Дусал',
  'Үзлэг' => 'Үзлэг',
  'Массаж' => 'Массаж'
];

// 🔹 Data Aggregation
$where = "b.date BETWEEN ? AND ?";
$params = [$from_date, $to_date];

if ($activeClinic !== 'all') {
    $where .= " AND b.clinic = ?";
    $params[] = $activeClinic;
}
if ($activeDepartment !== 'all') {
    $where .= " AND u.department = ?";
    $params[] = $activeDepartment;
}

// Summary Stats
$summarySt = db()->prepare("
    SELECT 
        COUNT(b.id) as total_count,
        SUM(CASE WHEN b.status='paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN b.status='cancelled' OR b.status='doctor_cancelled' THEN 1 ELSE 0 END) as cancel_count,
        COALESCE(SUM(CASE WHEN b.status='paid' THEN b.price ELSE 0 END), 0) as revenue
    FROM bookings b
    LEFT JOIN users u ON u.id = b.doctor_id AND u.role='doctor'
    WHERE $where
");
$summarySt->execute($params);
$sum = $summarySt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0, 
    'paid_count' => 0, 
    'cancel_count' => 0, 
    'revenue' => 0
];

// PHP 8+ Fix: Ensure numeric before number_format
$total_bookings = (float)($sum['total_count'] ?? 0);
$paid_bookings = (float)($sum['paid_count'] ?? 0);
$cancelled_bookings = (float)($sum['cancel_count'] ?? 0);
$total_revenue = (float)($sum['revenue'] ?? 0);

// Clinic breakdown
$clinicSt = db()->prepare("
    SELECT b.clinic, COUNT(b.id) as count 
    FROM bookings b
    LEFT JOIN users u ON u.id = b.doctor_id AND u.role='doctor'
    WHERE $where
    GROUP BY b.clinic
");
$clinicSt->execute($params);
$clinicData = array_map(function($c) { $c['clinic'] = strtoupper($c['clinic']); return $c; }, $clinicSt->fetchAll());

// Doctor load
$docSt = db()->prepare("
    SELECT u.name, COUNT(b.id) as count 
    FROM bookings b 
    JOIN users u ON b.doctor_id = u.id 
    WHERE u.role = 'doctor' AND $where
    GROUP BY u.name 
    ORDER BY count DESC 
    LIMIT 10
");
$docSt->execute($params);
$doctorData = $docSt->fetchAll();

// Detailed Table Data
$tableSt = db()->prepare("
    SELECT 
        u.name as doctor_name,
        b.clinic,
        u.department,
        COUNT(b.id) as total,
        SUM(CASE WHEN b.status='paid' THEN 1 ELSE 0 END) as paid,
        COALESCE(SUM(CASE WHEN b.status='paid' THEN b.price ELSE 0 END), 0) as revenue
    FROM bookings b
    JOIN users u ON u.id = b.doctor_id AND u.role='doctor'
    WHERE $where
    GROUP BY u.name, b.clinic, u.department
    ORDER BY total DESC
");
$tableSt->execute($params);
$tableData = $tableSt->fetchAll();

// Export Logic
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="analytics_export_' . date('Ymd') . '.xls"');
    echo "<table border='1'><tr><th>Эмч</th><th>Эмнэлэг</th><th>Тасаг</th><th>Нийт</th><th>Төлсөн</th><th>Орлого</th></tr>";
    foreach($tableData as $r) {
        $dName = $departmentNames[$r['department']] ?? $r['department'];
        echo "<tr><td>{$r['doctor_name']}</td><td>{$r['clinic']}</td><td>{$dName}</td><td>{$r['total']}</td><td>{$r['paid']}</td><td>" . number_format($r['revenue']) . "₮</td></tr>";
    }
    echo "</table>";
    exit;
}

?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <title>Дата Тайлан — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --primary: #6366f1;
      --primary-soft: rgba(99, 102, 241, 0.1);
      --success: #10b981;
      --success-soft: rgba(16, 185, 129, 0.1);
      --warning: #f59e0b;
      --danger: #ef4444;
      --indigo: #4f46e5;
      --slate-50: #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-600: #475569;
      --slate-800: #1e293b;
      --slate-900: #0f172a;
    }
    
    body { 
      font-family: 'Plus Jakarta Sans', sans-serif; 
      background: #f4f7fa; 
      color: var(--slate-800);
      letter-spacing: -0.01em;
    }
    
    .main-content {
      /* Handled globally by sidebar.php */
    }
    
    .glass-card {
      background: rgba(255, 255, 255, 0.8);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 1.25rem;
      box-shadow: 0 4px 20px -4px rgba(0, 0, 0, 0.1);
    }
    
    .stat-card {
      padding: 1.5rem;
      height: 100%;
      position: relative;
      overflow: hidden;
    }
    
    .stat-card::before {
      content: "";
      position: absolute;
      top: 0;
      right: 0;
      width: 100px;
      height: 100px;
      background: radial-gradient(circle at top right, var(--primary-soft), transparent 70%);
      z-index: 0;
    }
    
    .stat-icon {
      width: 42px;
      height: 42px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      margin-bottom: 1rem;
      font-size: 1.2rem;
    }
    
    .bg-primary-soft { background-color: var(--primary-soft); color: var(--primary); }
    .bg-success-soft { background-color: var(--success-soft); color: var(--success); }
    .bg-warning-soft { background-color: rgba(245, 158, 11, 0.1); color: var(--warning); }
    .bg-danger-soft { background-color: rgba(239, 68, 68, 0.1); color: var(--danger); }
    
    .filter-bar {
      margin-bottom: 2rem;
      padding: 1.25rem;
    }
    
    .form-label-premium {
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--slate-600);
      text-transform: uppercase;
      margin-bottom: 0.5rem;
      display: block;
    }
    
    .form-select-premium, .form-control-premium {
      background: white;
      border: 1px solid var(--slate-200);
      border-radius: 0.75rem;
      font-size: 0.9rem;
      padding: 0.6rem 1rem;
      box-shadow: 0 1px 2px rgba(0,0,0,0.05);
      transition: all 0.2s;
    }
    
    .form-select-premium:focus, .form-control-premium:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px var(--primary-soft);
      outline: none;
    }
    
    .btn-sync {
      height: 44px;
      width: 44px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.75rem;
      transition: all 0.2s;
    }
    
    .btn-export-premium {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border: none;
      font-weight: 700;
      border-radius: 0.75rem;
      padding: 0.7rem 1.5rem;
      font-size: 0.85rem;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
      transition: all 0.2s;
    }
    
    .btn-export-premium:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
      color: white;
    }
    
    .table-container-premium {
      margin-top: 2rem;
      overflow: hidden;
    }
    
    .table-premium thead th {
      background: #f8fafc;
      color: var(--slate-600);
      font-weight: 700;
      font-size: 0.7rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      padding: 1.25rem 1rem;
      border: none;
    }
    
    .table-premium tbody td {
      padding: 1rem;
      vertical-align: middle;
      border-bottom: 1px solid var(--slate-100);
      font-size: 0.9rem;
    }
    
    .grand-total-premium {
      background: #f1f5f9;
      font-weight: 800;
      color: var(--slate-900);
    }
    
    .chart-header {
      padding: 1.5rem 1.5rem 0.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .dot-indicator {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 6px;
    }
    
    /* Animation */
    .fade-up {
      animation: fadeUp 0.5s ease-out forwards;
      opacity: 0;
    }
    
    @keyframes fadeUp {
      from { transform: translateY(20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5 fade-up">
      <div>
        <h2 class="fw-800 mb-1" style="color: var(--slate-900); font-weight: 800;">Системийн Аналитик</h2>
        <p class="text-muted small mb-0">Бизнесийн гүйцэтгэлийг хянах ухаалаг самбар</p>
      </div>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-export-premium shadow-sm">
        <i class="fas fa-file-excel me-2"></i>Excel Экспорт
      </a>
    </div>

    <!-- Filters -->
    <div class="glass-card filter-bar fade-up" style="animation-delay: 0.1s;">
      <form class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label-premium">Хугацааны шүүлтүүр</label>
          <select name="period" class="form-select form-select-premium w-100" onchange="this.form.submit()">
            <option value="day" <?= $period==='day'?'selected':'' ?>>Өнөөдөр</option>
            <option value="week" <?= $period==='week'?'selected':'' ?>>Сүүлийн 7 хоног</option>
            <option value="month" <?= $period==='month'?'selected':'' ?>>Энэ сар</option>
            <option value="custom" <?= $period==='custom'?'selected':'' ?>>Сонгосон хугацаа...</option>
          </select>
        </div>
        <?php if ($period === 'custom'): ?>
        <div class="col-md-2">
           <label class="form-label-premium">Эхлэх огноо</label>
           <input type="date" name="from_date" class="form-control form-control-premium" value="<?= $from_date ?>">
        </div>
        <div class="col-md-2">
           <label class="form-label-premium">Дуусах огноо</label>
           <input type="date" name="to_date" class="form-control form-control-premium" value="<?= $to_date ?>">
        </div>
        <?php endif; ?>
        <?php if ($isSuper): ?>
        <div class="col-md-2">
          <label class="form-label-premium">Салбар нэгж</label>
          <select name="clinic" class="form-select form-select-premium w-100" onchange="this.form.submit()">
            <option value="all">Бүх салбар</option>
            <?php foreach($clinics as $c): ?>
              <option value="<?= $c ?>" <?= $activeClinic===$c?'selected':'' ?>><?= strtoupper($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3">
          <label class="form-label-premium">Мэргэжлийн тасаг</label>
          <select name="department" class="form-select form-select-premium w-100" onchange="this.form.submit()">
            <option value="all">Бүх тасаг</option>
            <?php foreach($departments as $d): ?>
              <option value="<?= $d ?>" <?= $activeDepartment===$d?'selected':'' ?>>
                <?= $departmentNames[$d] ?? $d ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-auto">
             <button type="submit" class="btn btn-primary btn-sync shadow-sm"><i class="fas fa-sync-alt"></i></button>
        </div>
      </form>
    </div>

    <!-- Counters -->
    <div class="row g-4 mb-5 fade-up" style="animation-delay: 0.2s;">
      <div class="col-md-3">
        <div class="glass-card stat-card">
          <div class="stat-icon bg-primary-soft"><i class="fas fa-calendar-check"></i></div>
          <div class="text-muted small fw-700 mb-1">НИЙТ ЗАХИАЛГА</div>
          <h2 class="fw-800 mb-0"><?= number_format($total_bookings) ?></h2>
        </div>
      </div>
      <div class="col-md-3">
        <div class="glass-card stat-card">
          <div class="stat-icon bg-success-soft"><i class="fas fa-check-double"></i></div>
          <div class="text-muted small fw-700 mb-1">ТӨЛӨЛТ ХИЙСЭН</div>
          <h2 class="fw-800 mb-0"><?= number_format($paid_bookings) ?></h2>
          <div class="small fw-700 mt-1" style="color: var(--success);">
            Гүйцэтгэл: <?= $total_bookings ? round(($paid_bookings/$total_bookings)*100) : 0 ?>%
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="glass-card stat-card">
          <div class="stat-icon bg-warning-soft"><i class="fas fa-coins"></i></div>
          <div class="text-muted small fw-700 mb-1">НИЙТ ОРЛОГО</div>
          <h2 class="fw-800 mb-0" style="color: var(--indigo);"><?= number_format($total_revenue) ?>₮</h2>
        </div>
      </div>
      <div class="col-md-3">
        <div class="glass-card stat-card">
          <div class="stat-icon bg-danger-soft"><i class="fas fa-user-minus"></i></div>
          <div class="text-muted small fw-700 mb-1">ЦУЦЛАЛТ</div>
          <h2 class="fw-800 mb-0" style="color: var(--danger);"><?= number_format($cancelled_bookings) ?></h2>
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-5 fade-up" style="animation-delay: 0.3s;">
      <div class="col-lg-5">
        <div class="glass-card h-100">
          <div class="chart-header">
            <h6 class="fw-800 mb-0">Салбарын ачаалал</h6>
            <span class="badge bg-light text-dark rounded-pill">Бүх нэгж</span>
          </div>
          <div class="p-4" style="height: 250px;">
             <canvas id="clinicChart"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="glass-card h-100">
          <div class="chart-header">
            <h6 class="fw-800 mb-0">Эмч нарын ачаалал <span class="text-muted fw-normal">(Топ 10)</span></h6>
          </div>
          <div class="p-4" style="height: 250px;">
             <canvas id="doctorChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="glass-card table-container-premium fade-up" style="animation-delay: 0.4s;">
      <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
         <h6 class="fw-800 mb-0">Дэлгэрэнгүй жагсаалт</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-premium table-hover mb-0">
          <thead>
            <tr>
              <th>Эмчийн нэр</th>
              <th>Салбар</th>
              <th>Тасаг</th>
              <th class="text-center">Нийт</th>
              <th class="text-center">Төлсөн</th>
              <th class="text-end">Орлого</th>
              <th class="text-end">Гүйцэтгэл</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $gt_total = 0; $gt_paid = 0; $gt_revenue = 0;
            foreach($tableData as $r): 
              $gt_total += $r['total']; $gt_paid += $r['paid']; $gt_revenue += $r['revenue'];
            ?>
            <tr>
              <td><span class="fw-700 text-dark"><?= htmlspecialchars($r['doctor_name']) ?></span></td>
              <td><span class="badge border bg-white text-primary px-3 py-2 fw-600"><?= $r['clinic'] ?></span></td>
              <td><span class="text-muted fw-500 small"><?= $departmentNames[$r['department']] ?? $r['department'] ?: '-' ?></span></td>
              <td class="text-center fw-700"><?= number_format($r['total']) ?></td>
              <td class="text-center text-success fw-700"><?= number_format($r['paid']) ?></td>
              <td class="text-end fw-800 text-indigo"><?= number_format($r['revenue']) ?>₮</td>
              <td class="text-end">
                 <?php $rate = $r['total'] ? round(($r['paid']/$r['total'])*100) : 0; ?>
                 <div class="d-flex align-items-center justify-content-end">
                    <div class="progress flex-grow-1 me-2" style="height: 6px; width: 40px; border-radius: 10px; max-width: 40px;">
                       <div class="progress-bar <?= $rate > 70 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $rate ?>%"></div>
                    </div>
                    <span class="fw-800 small text-dark"><?= $rate ?>%</span>
                 </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="grand-total-premium">
            <tr style="border-top: 2px solid var(--slate-200);">
              <td colspan="3" class="px-4 py-3">НИЙТ ДҮН (Grand Total)</td>
              <td class="text-center"><?= number_format($gt_total) ?></td>
              <td class="text-center"><?= number_format($gt_paid) ?></td>
              <td class="text-end text-indigo" style="font-size: 1.1rem;"><?= number_format($gt_revenue) ?>₮</td>
              <td class="text-end">
                <span class="badge bg-indigo text-white px-3 py-2 rounded-pill"><?= $gt_total ? round(($gt_paid/$gt_total)*100) : 0 ?>%</span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <div style="margin-top: 4rem;"></div>
  </main>

  <script>
    const q = s => document.querySelector(s);
    
    // Modern Chart Defaults
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
    
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                titleFont: { size: 13, weight: 'bold' },
                bodyFont: { size: 12 },
                padding: 12,
                cornerRadius: 12,
                displayColors: false
            }
        },
        scales: {
            y: { 
                grid: { color: 'rgba(226, 232, 240, 0.5)', drawTicks: false }, 
                border: { display: false }, 
                ticks: { padding: 10 } 
            },
            x: { 
                grid: { display: false }, 
                border: { display: false }, 
                ticks: { padding: 10 } 
            }
        },
        animations: {
            tension: { duration: 1000, easing: 'linear', from: 1, to: 0.4, loop: true }
        }
    };

    new Chart(q('#clinicChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($clinicData, 'clinic')) ?>,
        datasets: [{
          data: <?= json_encode(array_column($clinicData, 'count')) ?>,
          backgroundColor: '#6366f1',
          hoverBackgroundColor: '#4f46e5',
          borderRadius: 8,
          maxBarThickness: 35
        }]
      },
      options: chartOptions
    });

    new Chart(q('#doctorChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($doctorData, 'name')) ?>,
        datasets: [{
          data: <?= json_encode(array_column($doctorData, 'count')) ?>,
          backgroundColor: '#10b981',
          hoverBackgroundColor: '#059669',
          borderRadius: 8,
          maxBarThickness: 25
        }]
      },
      options: { ...chartOptions, indexAxis: 'y' }
    });
  </script>
</body>
</html>
