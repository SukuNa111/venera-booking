<?php
require_once __DIR__ . '/../config.php';
require_login();
require_role(['admin', 'reception']);

$treatments = db()->query("SELECT * FROM treatments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$scheduled = db()->query("SELECT s.*, b.patient_name FROM sms_schedule s LEFT JOIN bookings b ON s.booking_id = b.id ORDER BY s.scheduled_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Эмчилгээ & SMS тохиргоо</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
      min-height: 100vh;
    }
    main { margin-left: 250px; padding: 32px; }
    
    .page-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
    }
    .page-title { display: flex; align-items: center; gap: 16px; }
    .page-title .icon {
      width: 56px; height: 56px;
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      border-radius: 16px; display: flex; align-items: center; justify-content: center;
      color: white; font-size: 24px;
      box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
    }
    .page-title h1 { font-size: 28px; font-weight: 700; color: #1e293b; }
    .page-title p { color: #64748b; font-size: 14px; }
    
    .tabs { display: flex; gap: 8px; margin-bottom: 24px; }
    .tab {
      padding: 12px 24px; border-radius: 12px; font-weight: 600;
      cursor: pointer; border: none; background: white; color: #64748b;
      transition: all 0.2s;
    }
    .tab.active { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; }
    .tab:hover:not(.active) { background: #f1f5f9; }
    
    .card {
      background: white; border-radius: 20px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.04); overflow: hidden;
      margin-bottom: 24px;
    }
    .card-header {
      padding: 20px 24px; border-bottom: 1px solid #f1f5f9;
      display: flex; justify-content: space-between; align-items: center;
    }
    .card-header h2 { font-size: 18px; font-weight: 700; color: #1e293b; }
    
    table { width: 100%; border-collapse: collapse; }
    table th {
      padding: 14px 20px; text-align: left; font-size: 12px;
      font-weight: 600; color: #64748b; text-transform: uppercase;
      background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    }
    table td {
      padding: 16px 20px; border-bottom: 1px solid #f1f5f9;
      color: #334155; font-size: 14px;
    }
    table tbody tr:hover { background: #f8faff; }
    
    .badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
    }
    .badge-pending { background: #fef3c7; color: #d97706; }
    .badge-sent { background: #d1fae5; color: #059669; }
    .badge-failed { background: #fee2e2; color: #dc2626; }
    .badge-reminder { background: #dbeafe; color: #2563eb; }
    .badge-aftercare { background: #f3e8ff; color: #9333ea; }
    
    .btn {
      padding: 10px 20px; border-radius: 10px; font-weight: 600;
      cursor: pointer; border: none; font-size: 14px; transition: all 0.2s;
    }
    .btn-primary {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      color: white;
    }
    .btn-primary:hover { transform: translateY(-2px); }
    
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .info-box {
      background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
      border: 1px solid #c7d2fe; border-radius: 12px;
      padding: 16px 20px; margin-bottom: 24px;
      display: flex; align-items: center; gap: 12px;
    }
    .info-box i { color: #6366f1; font-size: 20px; }
    .info-box p { color: #475569; font-size: 14px; }
    
    @media (max-width: 1024px) { main { margin-left: 0; padding: 20px; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main>
  <div class="page-header">
    <div class="page-title">
      <div class="icon"><i class="fas fa-bell"></i></div>
      <div>
        <h1>Эмчилгээ & SMS</h1>
        <p>Автомат сануулга, After Care тохиргоо</p>
      </div>
    </div>
  </div>
  
  <div class="tabs">
    <button class="tab active" onclick="showTab('treatments')">
      <i class="fas fa-tooth"></i> Эмчилгээний төрөл
    </button>
    <button class="tab" onclick="showTab('scheduled')">
      <i class="fas fa-clock"></i> Төлөвлөсөн SMS
    </button>
  </div>
  
  <!-- Treatments Tab -->
  <div id="treatments" class="tab-content active">
    <div class="info-box">
      <i class="fas fa-info-circle"></i>
      <p><strong>After Care:</strong> Эмчилгээ дуусаад тодорхой хоногийн дараа автоматаар сануулга SMS илгээнэ.</p>
    </div>
    
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-list"></i> Эмчилгээний төрлүүд</h2>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Нэмэх</button>
      </div>
      <table>
        <thead>
          <tr>
            <th>Нэр</th>
            <th>Үзлэгийн тоо</th>
            <th>Завсарлага (хоног)</th>
            <th>After Care (хоног)</th>
            <th>After Care мессеж</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($treatments as $t): ?>
          <tr>
            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
            <td><?= $t['sessions'] ?> удаа</td>
            <td><?= $t['interval_days'] ?> хоног</td>
            <td>
              <?php if ($t['aftercare_days'] > 0): ?>
                <span class="badge badge-aftercare"><?= $t['aftercare_days'] ?> хоног</span>
              <?php else: ?>
                <span style="color:#94a3b8">-</span>
              <?php endif; ?>
            </td>
            <td style="max-width:300px; font-size:12px; color:#64748b;">
              <?= htmlspecialchars($t['aftercare_message'] ?? '-') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- Scheduled SMS Tab -->
  <div id="scheduled" class="tab-content">
    <div class="info-box">
      <i class="fas fa-info-circle"></i>
      <p><strong>Cron Job:</strong> Төлөвлөсөн SMS илгээхийн тулд <code>cron_sms.php</code> файлыг 5 минут тутам ажиллуулна уу.</p>
    </div>
    
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-paper-plane"></i> Төлөвлөсөн SMS</h2>
      </div>
      <table>
        <thead>
          <tr>
            <th>Төрөл</th>
            <th>Утас</th>
            <th>Үйлчлүүлэгч</th>
            <th>Илгээх огноо</th>
            <th>Төлөв</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($scheduled)): ?>
          <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:40px;">Төлөвлөсөн SMS байхгүй</td></tr>
          <?php else: ?>
          <?php foreach ($scheduled as $s): ?>
          <tr>
            <td>
              <span class="badge badge-<?= $s['type'] ?>">
                <?= $s['type'] === 'reminder' ? '🔔 Сануулга' : '💊 After Care' ?>
              </span>
            </td>
            <td><?= htmlspecialchars($s['phone']) ?></td>
            <td><?= htmlspecialchars($s['patient_name'] ?? '-') ?></td>
            <td><?= $s['scheduled_at'] ?></td>
            <td>
              <span class="badge badge-<?= $s['status'] ?>">
                <?php
                  echo match($s['status']) {
                    'pending' => '⏳ Хүлээгдэж буй',
                    'sent' => '✅ Илгээсэн',
                    'failed' => '❌ Алдаа',
                    default => $s['status']
                  };
                ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Add Treatment Modal -->
<div id="addModal" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-plus-circle"></i> Шинэ эмчилгээ нэмэх</h3>
      <button onclick="closeModal()" class="modal-close">&times;</button>
    </div>
    <form id="addForm" onsubmit="saveTreatment(event)">
      <div class="form-group">
        <label>Эмчилгээний нэр <span class="required">*</span></label>
        <input type="text" name="name" required placeholder="Жишээ: Botox, Filler">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Үзлэгийн тоо</label>
          <input type="number" name="sessions" value="1" min="1">
        </div>
        <div class="form-group">
          <label>Завсарлага (хоног)</label>
          <input type="number" name="interval_days" value="0" min="0">
        </div>
      </div>
      <div class="form-group">
        <label>After Care (хоног)</label>
        <input type="number" name="aftercare_days" value="0" min="0" placeholder="0 бол aftercare SMS илгээхгүй">
        <small>Эмчилгээнээс хэдэн хоногийн дараа SMS илгээх вэ?</small>
      </div>
      <div class="form-group">
        <label>After Care мессеж</label>
        <textarea name="aftercare_message" rows="3" placeholder="Латин үсгээр бичнэ үү. Жишээ: Sain baina uu! Tany emchilgeenii daraa 2 doloon honog bolloo..."></textarea>
        <small>SMS текст (Латин үсгээр)</small>
      </div>
      <div class="form-actions">
        <button type="button" onclick="closeModal()" class="btn btn-secondary">Болих</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Хадгалах</button>
      </div>
    </form>
  </div>
</div>

<style>
.modal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5); z-index: 1000;
  display: flex; align-items: center; justify-content: center;
}
.modal-box {
  background: white; border-radius: 20px; width: 500px; max-width: 95%;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-header {
  padding: 20px 24px; border-bottom: 1px solid #e2e8f0;
  display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { font-size: 18px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
.modal-header h3 i { color: #6366f1; }
.modal-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; }
.modal-close:hover { color: #ef4444; }

#addForm { padding: 24px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 14px; }
.form-group .required { color: #ef4444; }
.form-group input, .form-group textarea {
  width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px;
  font-size: 14px; transition: border-color 0.2s;
}
.form-group input:focus, .form-group textarea:focus { outline: none; border-color: #6366f1; }
.form-group small { display: block; margin-top: 6px; color: #94a3b8; font-size: 12px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
.btn-secondary { background: #f1f5f9; color: #64748b; }
.btn-secondary:hover { background: #e2e8f0; }
</style>

<script>
function openAddModal() {
  document.getElementById('addModal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('addModal').style.display = 'none';
  document.getElementById('addForm').reset();
}

async function saveTreatment(e) {
  e.preventDefault();
  const form = e.target;
  const data = {
    name: form.name.value.trim(),
    sessions: parseInt(form.sessions.value) || 1,
    interval_days: parseInt(form.interval_days.value) || 0,
    aftercare_days: parseInt(form.aftercare_days.value) || 0,
    aftercare_message: form.aftercare_message.value.trim()
  };
  
  if (!data.name) {
    alert('Эмчилгээний нэр оруулна уу!');
    return;
  }
  
  try {
    const res = await fetch('api.php?action=add_treatment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const json = await res.json();
    
    if (json.ok) {
      alert('✅ Эмчилгээ амжилттай нэмэгдлээ!');
      location.reload();
    } else {
      alert('❌ Алдаа: ' + (json.msg || 'Unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('❌ Сервертэй холбогдож чадсангүй');
  }
}

function showTab(tabId) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  
  event.target.closest('.tab').classList.add('active');
  document.getElementById(tabId).classList.add('active');
}
</script>
</body>
</html>
