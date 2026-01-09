<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$u = current_user();
$action = $_GET['action'] ?? 'view';

// Delete old SMS logs (older than 30 days)
if ($action === 'cleanup') {
  try {
    $st = db()->prepare("DELETE FROM sms_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $st->execute();
    $deleted = $st->rowCount();
    header('Content-Type: application/json');
    json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
  }
}

// Get all SMS logs with filters
$page = $_GET['page'] ?? 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$where = "1=1";
$params = [];

// Filter by status
if (!empty($_GET['status'])) {
  $where .= " AND status = ?";
  $params[] = $_GET['status'];
}

// Filter by clinic
if (!empty($_GET['clinic']) && $u['role'] === 'super_admin') {
  $where .= " AND clinic = ?";
  $params[] = $_GET['clinic'];
}

// Filter by date range
if (!empty($_GET['from_date'])) {
  $where .= " AND DATE(created_at) >= ?";
  $params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
  $where .= " AND DATE(created_at) <= ?";
  $params[] = $_GET['to_date'];
}

// Get total count
$st = db()->prepare("SELECT COUNT(*) FROM sms_log WHERE $where");
$st->execute($params);
$total = $st->fetchColumn();
$pages = ceil($total / $per_page);

// Get SMS logs
$st = db()->prepare("SELECT * FROM sms_log WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
$st->execute(array_merge($params, [$per_page, $offset]));
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

// Get clinics
$clinics = db()->query("SELECT DISTINCT clinic FROM bookings ORDER BY clinic")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>📨 SMS Логийн түүх - Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #667eea;
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --info: #06b6d4;
    }

    * { box-sizing: border-box; }

    body {
      background: linear-gradient(135deg, #667eea 0%, #8b5cf6 25%, #7c3aed 50%, #6366f1 100%);
      background-attachment: fixed;
      background-size: 400% 400%;
      animation: gradientShift 15s ease infinite;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      min-height: 100vh;
      color: #1e293b;
      position: relative;
    }

    @keyframes gradientShift {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    main {
      margin-left: 250px;
      padding: 2rem 2.5rem;
      position: relative;
      z-index: 1;
    }

    .page-header {
      background: linear-gradient(135deg, #667eea 0%, #8b5cf6 25%, #7c3aed 50%, #6366f1 100%);
      backdrop-filter: blur(20px);
      border-radius: 24px;
      padding: 2.5rem 2.5rem;
      margin-bottom: 2rem;
      color: white;
      box-shadow: 0 20px 60px rgba(102, 126, 234, 0.3);
      border: 1px solid rgba(255, 255, 255, 0.2);
      position: relative;
      overflow: hidden;
    }

    .page-header h1 {
      font-size: 1.75rem;
      font-weight: 800;
      margin-bottom: 0.5rem;
      color: white;
      position: relative;
      z-index: 2;
    }

    .page-header p {
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.9rem;
      margin: 0;
      font-weight: 500;
      position: relative;
      z-index: 2;
    }

    .card-container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 24px;
      padding: 2.5rem;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.5);
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .filter-section {
      background: rgba(255, 255, 255, 0.95);
      border-radius: 20px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .filter-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .form-control {
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-size: 0.9rem;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
    }

    .status-badge {
      display: inline-block;
      padding: 0.4rem 0.8rem;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-sent {
      background: #ecfdf5;
      color: #047857;
    }

    .status-failed {
      background: #fef2f2;
      color: #b91c1c;
    }

    .status-disabled {
      background: #f1f5f9;
      color: #64748b;
    }

    .table-responsive {
      border-radius: 12px;
      overflow: hidden;
    }

    table {
      font-size: 0.9rem;
    }

    thead {
      background: linear-gradient(135deg, #667eea 0%, #8b5cf6 100%);
      color: white;
    }

    tbody tr {
      border-bottom: 1px solid #e2e8f0;
      transition: all 0.2s ease;
    }

    tbody tr:hover {
      background: #f8fafc;
    }

    .btn {
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.85rem;
      transition: all 0.3s ease;
    }

    .btn-primary {
      background: linear-gradient(135deg, #667eea 0%, #8b5cf6 100%);
      color: white;
      border: none;
    }

    .btn-danger {
      background: #ef4444;
      color: white;
      border: none;
    }

    .btn-danger:hover {
      background: #dc2626;
      transform: translateY(-2px);
    }

    .pagination {
      justify-content: center;
      margin-top: 2rem;
    }

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
    <!-- Page Header -->
    <div class="page-header">
      <h1><i class="fas fa-envelope"></i> SMS Логийн түүх</h1>
      <p>Явсан бүх SMS мессежүүдийн жагсаалт | Нийт: <strong><?= number_format($total) ?></strong></p>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
      <form method="get" class="filter-row">
        <div>
          <label class="form-label"><strong>Статус</strong></label>
          <select name="status" class="form-control">
            <option value="">Бүх статус</option>
            <option value="sent" <?= $_GET['status'] === 'sent' ? 'selected' : '' ?>>✅ Явсан</option>
            <option value="failed" <?= $_GET['status'] === 'failed' ? 'selected' : '' ?>>❌ Алдаа</option>
            <option value="disabled" <?= $_GET['status'] === 'disabled' ? 'selected' : '' ?>>🔇 Идэвхгүй</option>
          </select>
        </div>
        
        <?php if ($u['role'] === 'admin'): ?>
        <div>
          <label class="form-label"><strong>Эмнэлэг</strong></label>
          <select name="clinic" class="form-control">
            <option value="">Бүх эмнэлэг</option>
            <?php foreach ($clinics as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= $_GET['clinic'] === $c ? 'selected' : '' ?>>
                <?= strtoupper($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div>
          <label class="form-label"><strong>Эхлэх</strong></label>
          <input type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>" class="form-control">
        </div>

        <div>
          <label class="form-label"><strong>Төгсгөл</strong></label>
          <input type="date" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>" class="form-control">
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: flex-end;">
          <button type="submit" class="btn btn-primary" style="width: 100%;">
            <i class="fas fa-search"></i> Хайх
          </button>
          <button type="button" class="btn btn-danger" onclick="cleanupOldLogs()" title="30 хоногийн сүүлээс оранхы log устгах">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </form>
    </div>

    <!-- SMS Logs Table -->
    <div class="card-container">
      <h3 style="font-weight: 700; margin-bottom: 1.5rem;">
        <i class="fas fa-list"></i> SMS Мессежүүдийн жагсаалт
      </h3>

      <div class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>#</th>
              <th>Утасны дугаар</th>
              <th>Статус</th>
              <th>Мессеж</th>
              <th>Эмнэлэг</th>
              <th>Огноо</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><strong><?= $log['id'] ?></strong></td>
                <td><code><?= htmlspecialchars(formatPhoneNumber($log['phone'])) ?></code></td>
                <td>
                  <span class="status-badge status-<?= $log['status'] ?>">
                    <?php 
                      if ($log['status'] === 'sent') echo '✅ Явсан';
                      elseif ($log['status'] === 'failed') echo '❌ Алдаа';
                      else echo '🔇 Идэвхгүй';
                    ?>
                  </span>
                </td>
                <td>
                  <small style="color: #64748b; word-break: break-word;">
                    <?= nl2br(htmlspecialchars($log['message'])) ?>
                  </small>
                </td>
                <td><span class="badge bg-light text-dark"><?= strtoupper($log['clinic'] ?? 'N/A') ?></span></td>
                <td><small><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($pages > 1): ?>
        <nav aria-label="Page navigation">
          <ul class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&status=<?= $_GET['status'] ?? '' ?>&from_date=<?= $_GET['from_date'] ?? '' ?>&to_date=<?= $_GET['to_date'] ?? '' ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function cleanupOldLogs() {
      if (confirm('30 хоногийн сүүлээс сүүлийн log устгаж байна? Үйлдэлийг буцаах боломжгүй!')) {
        fetch('?action=cleanup')
          .then(r => r.json())
          .then(data => {
            if (data.ok) {
              alert(data.deleted + ' log устгагдсан!');
              location.reload();
            } else {
              alert('Алдаа: ' + data.error);
            }
          });
      }
    }
  </script>
</body>
</html>
