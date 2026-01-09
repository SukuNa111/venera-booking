<?php
require_once __DIR__ . '/../config.php';
require_role(['admin', 'reception', 'doctor']);

// Search logic
$search = trim($_GET['search'] ?? '');
$phone = trim($_GET['phone'] ?? '');

$patient = null;
$history = [];

if ($phone) {
    $st = db()->prepare("SELECT * FROM patients WHERE phone = ? LIMIT 1");
    $st->execute([$phone]);
    $patient = $st->fetch();
} elseif ($search) {
    $st = db()->prepare("SELECT * FROM patients WHERE phone LIKE ? OR name ILIKE ? LIMIT 1");
    $st->execute(["%$search%", "%$search%"]);
    $patient = $st->fetch();
}

if ($patient) {
    $st = db()->prepare("
        SELECT b.*, u.name as doctor_name 
        FROM bookings b 
        LEFT JOIN users u ON b.doctor_id = u.id 
        WHERE b.phone = ? 
        ORDER BY b.date DESC, b.start_time DESC
    ");
    $st->execute([$patient['phone']]);
    $history = $st->fetchAll();
}

$pageTitle = $patient ? "Түүх: " . htmlspecialchars($patient['name']) : "Өвчтөний түүх";
?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $pageTitle ?> — Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="css/style.css" rel="stylesheet">
  <style>
    .patient-header {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
      color: white;
      padding: 2rem;
      border-radius: 1rem;
      margin-bottom: 2rem;
      box-shadow: 0 10px 25px rgba(99, 102, 241, 0.2);
    }
    .history-card {
      background: white;
      border-radius: 1rem;
      border: 1px solid #e2e8f0;
      margin-bottom: 1rem;
      transition: all 0.2s;
    }
    .history-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .status-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 2rem;
      font-size: 0.8rem;
      font-weight: 600;
    }
    .note-box {
      background: #f8fafc;
      border-left: 4px solid #cbd5e1;
      padding: 1rem;
      border-radius: 0.5rem;
      margin-top: 1rem;
      font-style: italic;
    }
    /* Gallery Styles */
    .gallery-container {
      background: white;
      border-radius: 1rem;
      border: 1px solid #e2e8f0;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }
    .media-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }
    .media-card {
      position: relative;
      border-radius: 0.75rem;
      overflow: hidden;
      aspect-ratio: 1;
      cursor: pointer;
      border: 2px solid transparent;
      transition: all 0.2s;
    }
    .media-card:hover {
      transform: scale(1.02);
      border-color: #6366f1;
    }
    .media-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .media-card .type-badge {
      position: absolute;
      top: 0.5rem;
      right: 0.5rem;
      background: rgba(0,0,0,0.6);
      color: white;
      font-size: 0.65rem;
      padding: 0.2rem 0.5rem;
      border-radius: 0.5rem;
      backdrop-filter: blur(4px);
    }
    .media-card.selected {
      border-color: #6366f1;
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
    }
    .comparison-view {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .comparison-box {
      text-align: center;
    }
    .comparison-box img {
      width: 100%;
      border-radius: 0.75rem;
      border: 1px solid #ddd;
    }
    .comparison-label {
      font-weight: 700;
      margin-top: 0.5rem;
      color: #6366f1;
    }
    .upload-zone {
      border: 2px dashed #cbd5e1;
      border-radius: 1rem;
      padding: 2rem;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
    }
    .upload-zone:hover {
      background: #f8fafc;
      border-color: #6366f1;
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2 class="fw-bold"><i class="fas fa-history me-2 text-primary"></i>Өвчтөний түүх</h2>
      <form class="d-flex gap-2" method="GET">
        <input type="text" name="search" class="form-control" placeholder="Утас эсвэл нэрээр хайх..." value="<?= htmlspecialchars($search) ?>" required>
        <button type="submit" class="btn btn-primary px-4">Хайх</button>
      </form>
    </div>

    <?php if ($patient): ?>
      <div class="patient-header">
        <div class="row align-items-center">
          <div class="col-md-auto">
            <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 2rem;">
              <i class="fas fa-user"></i>
            </div>
          </div>
          <div class="col">
            <h1 class="mb-0 fw-bold"><?= htmlspecialchars($patient['name']) ?></h1>
            <div class="d-flex gap-4 mt-2 opacity-75">
              <span><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($patient['phone']) ?></span>
              <span id="headerBirthday"><i class="fas fa-birthday-cake me-1"></i> <?= $patient['birthday'] ?: 'Бүртгэлгүй' ?></span>
              <span><i class="fas fa-calendar-check me-1"></i> Нийт үзлэг: <?= count($history) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-8">
          <!-- Image Gallery Section -->
          <div class="gallery-container mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h4 class="fw-bold mb-0">Зургийн сан (Галерей)</h4>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" onclick="showComparisonModal()">
                  <i class="fas fa-columns me-1"></i> Харьцуулах
                </button>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
                  <i class="fas fa-plus me-1"></i> Зураг нэмэх
                </button>
              </div>
            </div>
            
            <div id="mediaGrid" class="media-grid">
              <div class="text-center py-4 w-100">
                <i class="fas fa-spinner fa-spin text-muted h3"></i>
                <p class="text-muted small">Зургийг ачаалж байна...</p>
              </div>
            </div>
          </div>

          <h4 class="mb-3 fw-bold">Үзлэгийн түүх</h4>
          <?php if (empty($history)): ?>
            <div class="text-center py-5 bg-white rounded-4 border">
              <i class="fas fa-calendar-times display-4 text-muted mb-3"></i>
              <p class="text-muted">Үзлэгийн түүх байхгүй байна.</p>
            </div>
          <?php else: ?>
            <?php foreach ($history as $h): ?>
              <div class="history-card p-4">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <div>
                    <span class="text-muted small fw-semibold"><?= htmlspecialchars($h['date']) ?> | <?= htmlspecialchars(substr($h['start_time'], 0, 5)) ?></span>
                    <h5 class="mb-0 mt-1 fw-bold"><?= htmlspecialchars($h['service_name']) ?></h5>
                  </div>
                  <span class="badge bg-light text-dark border"><?= htmlspecialchars($h['status']) ?></span>
                </div>
                <div class="mt-2 text-muted fw-medium">
                  <i class="fas fa-user-md me-1"></i> <?= htmlspecialchars($h['doctor_name'] ?? '—') ?>
                  <span class="mx-2">|</span>
                  <i class="fas fa-hospital me-1"></i> <?= htmlspecialchars($h['clinic']) ?>
                </div>
                <?php if ($h['note']): ?>
                  <div class="note-box">
                    <i class="fas fa-quote-left text-muted me-2"></i>
                    <?= nl2br(htmlspecialchars($h['note'])) ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="col-lg-4">
          <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
              <h5 class="fw-bold mb-3">Өвчтөний мэдээлэл</h5>
              <div class="mb-3">
                <label class="form-label small fw-bold">Төрсөн өдөр</label>
                <input type="date" id="patientBirthday" class="form-control" value="<?= htmlspecialchars($patient['birthday'] ?? '') ?>">
              </div>
              <button onclick="savePatientInfo()" class="btn btn-outline-primary w-100 py-2 fw-bold">Мэдээлэл хадгалах</button>
            </div>
          </div>

          <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
              <h5 class="fw-bold mb-3">Эмнэлгийн тэмдэглэл</h5>
              <textarea id="patientNotes" class="form-control mb-3" rows="8" placeholder="Энэ өвчтөний талаарх ерөнхий тэмдэглэл..."><?= htmlspecialchars($patient['notes'] ?? '') ?></textarea>
              <button onclick="savePatientNotes()" class="btn btn-primary w-100 py-2 fw-bold">Тэмдэглэл хадгалах</button>
            </div>
          </div>
        </div>
      </div>
    <?php elseif ($search || $phone): ?>
      <div class="text-center py-5 bg-white rounded-4 border">
        <i class="fas fa-search display-4 text-muted mb-3"></i>
        <h4 class="text-muted">Өвчтөн олдсонгүй</h4>
        <p>Уучлаарай, таны хайсан мэдээлэлд тохирох өвчтөн олдсонгүй.</p>
      </div>
    <?php else: ?>
      <div class="text-center py-5">
        <img src="https://cdn-icons-png.flaticon.com/512/3771/3771444.png" style="width: 200px; opacity: 0.5;" alt="history">
        <p class="mt-4 text-muted">Өвчтөний нэр эсвэл утсаар хайж түүхийг харна уу.</p>
      </div>
    <?php endif; ?>

    <!-- Modal: Upload Media -->
    <div class="modal fade" id="uploadMediaModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1.5rem;">
          <div class="modal-header border-0 pb-0">
            <h5 class="fw-bold">Зураг нэмэх</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="uploadMediaForm">
            <div class="modal-body">
              <input type="hidden" name="phone" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>">
              
              <div class="mb-3">
                <label class="form-label small fw-bold">Зургийн төрөл</label>
                <select name="media_type" class="form-select border-0 bg-light" style="border-radius: 0.75rem;">
                  <option value="general">Ерөнхий</option>
                  <option value="before">Доорх (Before)</option>
                  <option value="after">Дараах (After)</option>
                  <option value="xray">Рентген (X-Ray)</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label small fw-bold">Тэмдэглэл (заавал биш)</label>
                <input type="text" name="notes" class="form-control border-0 bg-light" placeholder="Жишээ: Зүүн дээд 4-р шүд" style="border-radius: 0.75rem;">
              </div>

              <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
                <i class="fas fa-cloud-upload-alt display-6 text-primary mb-2"></i>
                <p class="mb-0 text-muted small">Зураг сонгохын тулд энд дарна уу</p>
                <input type="file" id="fileInput" name="image" class="d-none" accept="image/*" required>
                <div id="filePreview" class="mt-2 d-none">
                  <span class="badge bg-success" id="fileName"></span>
                </div>
              </div>
            </div>
            <div class="modal-footer border-0">
              <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Болих</button>
              <button type="submit" class="btn btn-primary px-4" style="border-radius: 0.75rem;">Хадгалах</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: Image Comparison -->
    <div class="modal fade" id="comparisonModal" tabindex="-1">
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1.5rem;">
          <div class="modal-header border-0">
            <h5 class="fw-bold">Зураг харьцуулалт</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body pt-0">
            <div class="row g-3">
              <div class="col-md-6 mb-3">
                 <div class="comparison-box">
                    <div id="compLeft" class="bg-light rounded-4 d-flex align-items-center justify-content-center border" style="height: 400px; overflow: hidden;">
                      <p class="text-muted">Зүүн зураг сонгоогүй</p>
                    </div>
                    <div class="comparison-label">ӨМНӨ</div>
                 </div>
              </div>
              <div class="col-md-6 mb-3">
                 <div class="comparison-box">
                    <div id="compRight" class="bg-light rounded-4 d-flex align-items-center justify-content-center border" style="height: 400px; overflow: hidden;">
                      <p class="text-muted">Баруун зураг сонгоогүй</p>
                    </div>
                    <div class="comparison-label">ДАРАА</div>
                 </div>
              </div>
            </div>
            <hr>
            <p class="small fw-bold text-muted mb-2">Сонгох зургууд:</p>
            <div id="compGrid" class="media-grid" style="grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));">
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function savePatientInfo() {
      const birthday = document.getElementById('patientBirthday').value;
      const phone = '<?= $patient['phone'] ?? '' ?>';
      if (!phone) return;

      fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          action: 'update_patient_info',
          phone: phone,
          birthday: birthday
        })
      })
      .then(r => r.json())
      .then(res => {
        if (res.ok) {
           alert('Мэдээлэл хадгалагдлаа');
           document.getElementById('headerBirthday').innerHTML = '<i class="fas fa-birthday-cake me-1"></i> ' + (birthday || 'Бүртгэлгүй');
        } else alert('Алдаа: ' + res.msg);
      });
    }

    function savePatientNotes() {
      const notes = document.getElementById('patientNotes').value;
      const phone = '<?= $patient['phone'] ?? '' ?>';
      if (!phone) return;

      fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          action: 'update_patient_notes',
          phone: phone,
          notes: notes
        })
      })
      .then(r => r.json())
      .then(res => {
        if (res.ok) alert('Тэмдэглэл хадгалагдлаа');
        else alert('Алдаа: ' + res.msg);
      });
    }

    // === Gallery Logic ===
    let patientMedia = [];
    const patientPhone = '<?= $patient['phone'] ?? '' ?>';

    async function loadMedia() {
      if (!patientPhone) return;
      try {
        const res = await fetch(`api.php?action=get_patient_media&phone=${patientPhone}&t=${Date.now()}`);
        const data = await res.json();
        if (data.ok) {
          patientMedia = data.data;
          renderMedia();
        }
      } catch (e) { console.error('Load media error:', e); }
    }

    function renderMedia() {
      const grid = document.getElementById('mediaGrid');
      if (!patientMedia.length) {
        grid.innerHTML = '<div class="text-center py-4 w-100 text-muted small">Зураг олдсонгүй</div>';
        return;
      }
      grid.innerHTML = patientMedia.map(m => `
        <div class="media-card" onclick="viewImage('${m.file_path}')">
          <img src="${m.file_path}" alt="media">
          <span class="type-badge">${m.media_type.toUpperCase()}</span>
          ${'<?= $u['role'] ?>' === 'admin' ? `<button onclick="event.stopPropagation(); deleteMedia(${m.id})" class="btn btn-danger btn-sm p-1" style="position:absolute; bottom:5px; right:5px; opacity:0.7"><i class="fas fa-trash"></i></button>` : ''}
        </div>
      `).join('');
    }

    function viewImage(path) {
      window.open(path, '_blank');
    }

    async function deleteMedia(id) {
       if (!confirm('Энэ зургийг устгах уу?')) return;
       const fd = new FormData();
       fd.append('action', 'delete_media');
       fd.append('id', id);
       const res = await fetch('api.php', { method: 'POST', body: fd });
       if ((await res.json()).ok) loadMedia();
    }

    // File input preview
    document.getElementById('fileInput').addEventListener('change', function(e) {
      const preview = document.getElementById('filePreview');
      const name = document.getElementById('fileName');
      if (this.files && this.files[0]) {
        preview.classList.remove('d-none');
        name.textContent = this.files[0].name;
      } else {
        preview.classList.add('d-none');
      }
    });

    // Upload Form
    document.getElementById('uploadMediaForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      const btn = this.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Хадгалж байна...';

      const fd = new FormData(this);
      fd.append('action', 'upload_media');

      try {
        const res = await fetch('api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          bootstrap.Modal.getInstance(document.getElementById('uploadMediaModal')).hide();
          this.reset();
          document.getElementById('filePreview').classList.add('d-none');
          loadMedia();
        } else alert('Алдаа: ' + data.msg);
      } catch (e) { alert('Алдаа гарлаа'); }
      finally {
        btn.disabled = false;
        btn.innerHTML = 'Хадгалах';
      }
    });

    // Comparison Logic
    let leftImg = null, rightImg = null;
    function showComparisonModal() {
      leftImg = null; rightImg = null;
      document.getElementById('compLeft').innerHTML = '<p class="text-muted small">Өмнөх зургийг сонгоно уу</p>';
      document.getElementById('compRight').innerHTML = '<p class="text-muted small">Дараах зургийг сонгоно уу</p>';
      
      const grid = document.getElementById('compGrid');
      grid.innerHTML = patientMedia.map(m => `
        <div class="media-card" onclick="selectForComp(this, '${m.file_path}')">
          <img src="${m.file_path}">
        </div>
      `).join('');
      
      new bootstrap.Modal(document.getElementById('comparisonModal')).show();
    }

    function selectForComp(el, path) {
      if (!leftImg) {
        leftImg = path;
        document.getElementById('compLeft').innerHTML = `<img src="${path}" style="max-height:100%; max-width:100%; object-fit:contain">`;
        el.classList.add('selected');
      } else if (!rightImg) {
        rightImg = path;
        document.getElementById('compRight').innerHTML = `<img src="${path}" style="max-height:100%; max-width:100%; object-fit:contain">`;
        el.classList.add('selected');
      } else {
        // Reset both and start over
        leftImg = path; rightImg = null;
        document.querySelectorAll('#compGrid .media-card').forEach(c => c.classList.remove('selected'));
        el.classList.add('selected');
        document.getElementById('compLeft').innerHTML = `<img src="${path}" style="max-height:100%; max-width:100%; object-fit:contain">`;
        document.getElementById('compRight').innerHTML = '<p class="text-muted small">Дараах зургийг сонгоно уу</p>';
      }
    }

    if (patientPhone) loadMedia();
  </script>
</body>
</html>
