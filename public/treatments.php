<?php
require_once __DIR__ . '/../config.php';
require_login();
require_role(['admin', 'reception']);

// Get categories for filter
$categories = db()->query("SELECT DISTINCT category FROM treatments WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$treatments = db()->query("SELECT * FROM treatments ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
$scheduled = db()->query("SELECT s.*, b.patient_name FROM sms_schedule s LEFT JOIN bookings b ON s.booking_id = b.id ORDER BY s.scheduled_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Эмчилгээ & Үнийн жагсаалт</title>
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
    
    .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
    .tab {
      padding: 12px 24px; border-radius: 12px; font-weight: 600;
      cursor: pointer; border: none; background: white; color: #64748b;
      transition: all 0.2s; display: flex; align-items: center; gap: 8px;
    }
    .tab.active { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; }
    .tab:hover:not(.active) { background: #f1f5f9; }
    
    .filter-bar {
      display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;
    }
    .filter-bar input, .filter-bar select {
      padding: 10px 16px; border: 2px solid #e2e8f0; border-radius: 10px;
      font-size: 14px; outline: none; transition: border-color 0.2s;
    }
    .filter-bar input:focus, .filter-bar select:focus { border-color: #6366f1; }
    .filter-bar input { width: 280px; }
    
    .card {
      background: white; border-radius: 20px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(0, 0, 0, 0.04); overflow: hidden;
      margin-bottom: 24px;
    }
    .card-header {
      padding: 20px 24px; border-bottom: 1px solid #f1f5f9;
      display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
    }
    .card-header h2 { font-size: 18px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    
    .stats-row { display: flex; gap: 12px; }
    .stat-item { padding: 8px 16px; background: #f8fafc; border-radius: 10px; font-size: 13px; }
    .stat-item strong { color: #6366f1; }
    
    table { width: 100%; border-collapse: collapse; }
    table th {
      padding: 14px 16px; text-align: left; font-size: 11px;
      font-weight: 600; color: #64748b; text-transform: uppercase;
      background: #f8fafc; border-bottom: 1px solid #e2e8f0;
      white-space: nowrap;
    }
    table td {
      padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
      color: #334155; font-size: 14px;
    }
    table tbody tr:hover { background: #f8faff; }
    table tbody tr.category-row { background: #f1f5f9; }
    table tbody tr.category-row td { font-weight: 700; color: #6366f1; padding: 10px 16px; font-size: 13px; }
    
    .badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
    }
    .badge-pending { background: #fef3c7; color: #d97706; }
    .badge-sent { background: #d1fae5; color: #059669; }
    .badge-failed { background: #fee2e2; color: #dc2626; }
    .badge-reminder { background: #dbeafe; color: #2563eb; }
    .badge-aftercare { background: #f3e8ff; color: #9333ea; }
    .badge-auto { background: #dbeafe; color: #2563eb; }
    .badge-manual { background: #fef3c7; color: #d97706; }
    .badge-category { background: #e0e7ff; color: #4338ca; }
    
    .price { font-weight: 700; color: #059669; white-space: nowrap; }
    .price-zero { color: #94a3b8; font-weight: 400; }
    
    .btn-group { display: flex; gap: 6px; }
    .btn-icon { 
      width: 32px; height: 32px; border-radius: 8px; border: none; 
      cursor: pointer; display: inline-flex; align-items: center; justify-content: center; 
      transition: all 0.2s; font-size: 13px;
    }
    .btn-edit { background: #dbeafe; color: #2563eb; }
    .btn-edit:hover { background: #2563eb; color: white; }
    .btn-delete { background: #fee2e2; color: #dc2626; }
    .btn-delete:hover { background: #dc2626; color: white; }
    
    .btn {
      padding: 10px 20px; border-radius: 10px; font-weight: 600;
      cursor: pointer; border: none; font-size: 14px; transition: all 0.2s;
      display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-primary {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      color: white;
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }
    .btn-secondary { background: #f1f5f9; color: #64748b; }
    .btn-secondary:hover { background: #e2e8f0; }
    
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    /* Modal */
    .modal-overlay {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.5); z-index: 1000;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
    }
    .modal-box {
      background: white; border-radius: 20px; width: 600px; max-width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto;
    }
    .modal-header {
      padding: 20px 24px; border-bottom: 1px solid #e2e8f0;
      display: flex; justify-content: space-between; align-items: center;
      position: sticky; top: 0; background: white; z-index: 1;
    }
    .modal-header h3 { font-size: 18px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    .modal-header h3 i { color: #6366f1; }
    .modal-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; }
    .modal-close:hover { color: #ef4444; }
    
    .modal-form { padding: 24px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 14px; }
    .form-group .required { color: #ef4444; }
    .form-group input, .form-group textarea, .form-group select {
      width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px;
      font-size: 14px; transition: border-color 0.2s;
    }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: #6366f1; }
    .form-group small { display: block; margin-top: 6px; color: #94a3b8; font-size: 12px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    
    .form-actions { 
      display: flex; justify-content: flex-end; gap: 12px; 
      margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; 
    }
    
    .radio-group { display: flex; gap: 12px; }
    .radio-option { 
      flex: 1; display: flex; align-items: center; gap: 10px; 
      padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; 
      cursor: pointer; transition: all 0.2s; 
    }
    .radio-option:hover { border-color: #c7d2fe; background: #f8faff; }
    .radio-option input[type="radio"] { display: none; }
    .radio-option input[type="radio"]:checked + .radio-box { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; }
    .radio-box { 
      padding: 6px 12px; border-radius: 8px; background: #f1f5f9; 
      font-weight: 600; font-size: 12px; display: flex; align-items: center; gap: 6px; 
      transition: all 0.2s; white-space: nowrap;
    }
    
    @media (max-width: 1024px) { 
      main { margin-left: 0; padding: 20px; } 
      .form-row, .form-row-3 { grid-template-columns: 1fr; }
      .radio-group { flex-direction: column; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main>
  <div class="page-header">
    <div class="page-title">
      <div class="icon"><i class="fas fa-tooth"></i></div>
      <div>
        <h1>Эмчилгээ & Үнийн жагсаалт</h1>
        <p>Эмчилгээний төрөл, үнэ, SMS тохиргоо</p>
      </div>
    </div>
  </div>
  
  <div class="tabs">
    <button class="tab active" onclick="showTab('treatments')">
      <i class="fas fa-list-ul"></i> Эмчилгээнүүд
    </button>
    <button class="tab" onclick="showTab('scheduled')">
      <i class="fas fa-clock"></i> Төлөвлөсөн SMS
    </button>
  </div>
  
  <!-- Treatments Tab -->
  <div id="treatments" class="tab-content active">
    <div class="filter-bar">
      <input type="text" id="searchInput" placeholder="🔍 Эмчилгээ хайх..." onkeyup="filterTable()">
      <select id="categoryFilter" onchange="filterTable()">
        <option value="">Бүх ангилал</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Шинэ нэмэх</button>
    </div>
    
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-coins"></i> Үнийн жагсаалт</h2>
        <div class="stats-row">
          <div class="stat-item">Нийт: <strong><?= count($treatments) ?></strong> эмчилгээ</div>
        </div>
      </div>
      <div style="overflow-x: auto;">
        <table id="treatmentsTable">
          <thead>
            <tr>
              <th>Эмчилгээ</th>
              <th>Ангилал</th>
              <th>Үнэ</th>
              <th>Үзлэг</th>
              <th>Завсар</th>
              <th>Горим</th>
              <th>After Care</th>
              <th style="width:80px">Үйлдэл</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $currentCategory = '';
            foreach ($treatments as $t): 
              $isNewCategory = $t['category'] !== $currentCategory && !empty($t['category']);
              if ($isNewCategory) $currentCategory = $t['category'];
            ?>
            <tr data-name="<?= htmlspecialchars(strtolower($t['name'])) ?>" data-category="<?= htmlspecialchars($t['category'] ?? '') ?>">
              <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
              <td>
                <?php if (!empty($t['category'])): ?>
                <span class="badge badge-category"><?= htmlspecialchars($t['category']) ?></span>
                <?php else: ?>
                <span style="color:#94a3b8">-</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($t['price'] > 0): ?>
                <span class="price"><?= number_format($t['price'], 0, '', ',') ?>₮</span>
                <?php else: ?>
                <span class="price-zero">Үнэгүй</span>
                <?php endif; ?>
              </td>
              <td><?= $t['sessions'] ?> удаа</td>
              <td><?= $t['interval_days'] ?> хоног</td>
              <td>
                <?php if (($t['next_visit_mode'] ?? 'auto') === 'auto'): ?>
                <span class="badge badge-auto"><i class="fas fa-magic"></i> Авто</span>
                <?php else: ?>
                <span class="badge badge-manual"><i class="fas fa-hand-pointer"></i> Гараар</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($t['aftercare_days'] > 0): ?>
                <span class="badge badge-aftercare"><?= $t['aftercare_days'] ?> хоног</span>
                <?php else: ?>
                <span style="color:#94a3b8">-</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-group">
                  <button onclick='openEditModal(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn-icon btn-edit" title="Засах">
                    <i class="fas fa-pen"></i>
                  </button>
                  <button onclick="deleteTreatment(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['name'])) ?>')" class="btn-icon btn-delete" title="Устгах">
                    <i class="fas fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  
  <!-- Scheduled SMS Tab -->
  <div id="scheduled" class="tab-content">
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
            <th>Мессеж</th>
            <th>Илгээх огноо</th>
            <th>Төлөв</th>
            <th style="width:100px">Үйлдэл</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($scheduled)): ?>
          <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:40px;">Төлөвлөсөн SMS байхгүй</td></tr>
          <?php else: ?>
          <?php foreach ($scheduled as $s): ?>
          <tr id="sms-row-<?= $s['id'] ?>">
            <td>
              <span class="badge badge-<?= $s['type'] ?>">
                <?= $s['type'] === 'reminder' ? '🔔 Сануулга' : '💊 After Care' ?>
              </span>
            </td>
            <td><?= htmlspecialchars($s['phone']) ?></td>
            <td><?= htmlspecialchars($s['patient_name'] ?? '-') ?></td>
            <td style="max-width:200px; font-size:12px; color:#64748b;" title="<?= htmlspecialchars($s['message'] ?? '') ?>">
              <?= htmlspecialchars(mb_substr($s['message'] ?? '-', 0, 40)) ?><?= mb_strlen($s['message'] ?? '') > 40 ? '...' : '' ?>
            </td>
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
            <td>
              <?php if ($s['status'] === 'pending'): ?>
              <div class="btn-group">
                <button onclick='openSmsEditModal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn-icon btn-edit" title="Засах">
                  <i class="fas fa-pen"></i>
                </button>
                <button onclick="deleteSms(<?= $s['id'] ?>)" class="btn-icon btn-delete" title="Устгах">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
              <?php else: ?>
              <span style="color:#94a3b8">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Add/Edit Treatment Modal -->
<div id="treatmentModal" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-tooth"></i> <span id="modalTitle">Шинэ эмчилгээ</span></h3>
      <button onclick="closeModal()" class="modal-close">&times;</button>
    </div>
    <form id="treatmentForm" class="modal-form" onsubmit="saveTreatment(event)">
      <input type="hidden" name="id" id="treatmentId">
      
      <div class="form-row">
        <div class="form-group">
          <label>Эмчилгээний нэр <span class="required">*</span></label>
          <input type="text" name="name" id="treatmentName" required placeholder="Жишээ: Сувгийн эмчилгээ">
        </div>
        <div class="form-group">
          <label>Ангилал</label>
          <input type="text" name="category" id="treatmentCategory" list="categoryList" placeholder="Жишээ: Цоорол эмчилгээ">
          <datalist id="categoryList">
            <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
      </div>
      
      <div class="form-row-3">
        <div class="form-group">
          <label>Үнэ (₮)</label>
          <input type="number" name="price" id="treatmentPrice" value="0" min="0" step="1000">
        </div>
        <div class="form-group">
          <label>Үзлэгийн тоо</label>
          <input type="number" name="sessions" id="treatmentSessions" value="1" min="1">
        </div>
        <div class="form-group">
          <label>Завсарлага (хоног)</label>
          <input type="number" name="interval_days" id="treatmentInterval" value="0" min="0">
        </div>
      </div>
      
      <div class="form-group">
        <label>Дараа ирэх горим</label>
        <div class="radio-group">
          <label class="radio-option">
            <input type="radio" name="next_visit_mode" value="auto" id="modeAuto">
            <span class="radio-box"><i class="fas fa-magic"></i> Автомат</span>
          </label>
          <label class="radio-option">
            <input type="radio" name="next_visit_mode" value="manual" id="modeManual">
            <span class="radio-box"><i class="fas fa-hand-pointer"></i> Гараар</span>
          </label>
        </div>
        <small>Автомат: Систем дараагийн цаг үүсгэнэ | Гараар: Эмч/Ресепшн өөрөө оруулна</small>
      </div>
      
      <div class="form-row">
        <div class="form-group">
          <label>After Care (хоног)</label>
          <input type="number" name="aftercare_days" id="treatmentAftercareDays" value="0" min="0">
          <small>0 бол SMS илгээхгүй</small>
        </div>
        <div class="form-group">
          <label>After Care мессеж</label>
          <input type="text" name="aftercare_message" id="treatmentAftercareMsg" placeholder="Латин үсгээр...">
        </div>
      </div>
      
      <div class="form-actions">
        <button type="button" onclick="closeModal()" class="btn btn-secondary">Болих</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Хадгалах</button>
      </div>
    </form>
  </div>
</div>

<script>
let editingId = null;

function showTab(tabId) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  event.target.closest('.tab').classList.add('active');
  document.getElementById(tabId).classList.add('active');
}

function filterTable() {
  const search = document.getElementById('searchInput').value.toLowerCase();
  const category = document.getElementById('categoryFilter').value;
  const rows = document.querySelectorAll('#treatmentsTable tbody tr');
  
  rows.forEach(row => {
    const name = row.dataset.name || '';
    const cat = row.dataset.category || '';
    const matchSearch = name.includes(search);
    const matchCategory = !category || cat === category;
    row.style.display = matchSearch && matchCategory ? '' : 'none';
  });
}

function openAddModal() {
  editingId = null;
  document.getElementById('modalTitle').textContent = 'Шинэ эмчилгээ нэмэх';
  document.getElementById('treatmentForm').reset();
  document.getElementById('treatmentId').value = '';
  document.getElementById('modeAuto').checked = true;
  document.getElementById('treatmentModal').style.display = 'flex';
}

function openEditModal(t) {
  editingId = t.id;
  document.getElementById('modalTitle').textContent = 'Эмчилгээ засах';
  document.getElementById('treatmentId').value = t.id;
  document.getElementById('treatmentName').value = t.name;
  document.getElementById('treatmentCategory').value = t.category || '';
  document.getElementById('treatmentPrice').value = t.price || 0;
  document.getElementById('treatmentSessions').value = t.sessions || 1;
  document.getElementById('treatmentInterval').value = t.interval_days || 0;
  document.getElementById('treatmentAftercareDays').value = t.aftercare_days || 0;
  document.getElementById('treatmentAftercareMsg').value = t.aftercare_message || '';
  
  if ((t.next_visit_mode || 'auto') === 'auto') {
    document.getElementById('modeAuto').checked = true;
  } else {
    document.getElementById('modeManual').checked = true;
  }
  
  document.getElementById('treatmentModal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('treatmentModal').style.display = 'none';
  editingId = null;
}

async function saveTreatment(e) {
  e.preventDefault();
  const form = e.target;
  const data = {
    id: editingId,
    name: form.name.value.trim(),
    category: form.category.value.trim(),
    price: parseFloat(form.price.value) || 0,
    sessions: parseInt(form.sessions.value) || 1,
    interval_days: parseInt(form.interval_days.value) || 0,
    next_visit_mode: form.next_visit_mode.value,
    aftercare_days: parseInt(form.aftercare_days.value) || 0,
    aftercare_message: form.aftercare_message.value.trim()
  };
  
  if (!data.name) {
    alert('Эмчилгээний нэр оруулна уу!');
    return;
  }
  
  const action = editingId ? 'update_treatment' : 'add_treatment';
  
  try {
    const res = await fetch('api.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const json = await res.json();
    
    if (json.ok) {
      location.reload();
    } else {
      alert('❌ Алдаа: ' + (json.msg || 'Unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('❌ Сервертэй холбогдож чадсангүй');
  }
}

async function deleteTreatment(id, name) {
  if (!confirm(`"${name}" эмчилгээг устгах уу?`)) return;
  
  try {
    const res = await fetch('api.php?action=delete_treatment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const json = await res.json();
    
    if (json.ok) {
      location.reload();
    } else {
      alert('❌ Алдаа: ' + (json.msg || 'Unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('❌ Сервертэй холбогдож чадсангүй');
  }
}

// SMS Functions
function openSmsEditModal(sms) {
  document.getElementById('smsId').value = sms.id;
  document.getElementById('smsPhone').value = sms.phone;
  document.getElementById('smsMessage').value = sms.message || '';
  document.getElementById('smsScheduledAt').value = sms.scheduled_at ? sms.scheduled_at.replace(' ', 'T').slice(0, 16) : '';
  document.getElementById('smsModal').style.display = 'flex';
}

function closeSmsModal() {
  document.getElementById('smsModal').style.display = 'none';
}

async function saveSms(e) {
  e.preventDefault();
  const form = e.target;
  const data = {
    id: parseInt(form.id.value),
    phone: form.phone.value.trim(),
    message: form.message.value.trim(),
    scheduled_at: form.scheduled_at.value.replace('T', ' ') + ':00'
  };
  
  try {
    const res = await fetch('api.php?action=update_sms', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const json = await res.json();
    
    if (json.ok) {
      location.reload();
    } else {
      alert('❌ Алдаа: ' + (json.msg || 'Unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('❌ Сервертэй холбогдож чадсангүй');
  }
}

async function deleteSms(id) {
  if (!confirm('Энэ SMS-г устгах уу?')) return;
  
  try {
    const res = await fetch('api.php?action=delete_sms', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const json = await res.json();
    
    if (json.ok) {
      document.getElementById('sms-row-' + id).remove();
    } else {
      alert('❌ Алдаа: ' + (json.msg || 'Unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('❌ Сервертэй холбогдож чадсангүй');
  }
}
</script>

<!-- SMS Edit Modal -->
<div id="smsModal" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="width:450px;">
    <div class="modal-header">
      <h3><i class="fas fa-sms"></i> SMS засах</h3>
      <button onclick="closeSmsModal()" class="modal-close">&times;</button>
    </div>
    <form id="smsForm" class="modal-form" onsubmit="saveSms(event)">
      <input type="hidden" name="id" id="smsId">
      
      <div class="form-group">
        <label>Утасны дугаар</label>
        <input type="text" name="phone" id="smsPhone" required>
      </div>
      
      <div class="form-group">
        <label>Илгээх огноо</label>
        <input type="datetime-local" name="scheduled_at" id="smsScheduledAt" required>
      </div>
      
      <div class="form-group">
        <label>Мессеж (Латин үсгээр)</label>
        <textarea name="message" id="smsMessage" rows="4" required placeholder="Латин үсгээр бичнэ үү..."></textarea>
      </div>
      
      <div class="form-actions">
        <button type="button" onclick="closeSmsModal()" class="btn btn-secondary">Болих</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Хадгалах</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
