<?php
require_once __DIR__ . '/../config.php';
require_role(['admin','reception']);

// Fetch data
$logs = [];
$scheduled = [];
try {
  $logs = db()->query("SELECT * FROM sms_log ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $logs = []; }
try {
  $scheduled = db()->query("SELECT * FROM sms_schedule WHERE status = 'pending' ORDER BY scheduled_at LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $scheduled = []; }
$currentUser = current_user();
$role = $currentUser['role'] ?? '';
// Load SMS templates from settings.json for client-side use
$settingsPath = __DIR__ . '/../db/settings.json';
$smsTemplates = ['confirmation'=>'','reminder'=>'','aftercare'=>''];
if (file_exists($settingsPath)) {
  $saved = json_decode(file_get_contents($settingsPath), true);
  if (is_array($saved)) {
    $smsTemplates['confirmation'] = $saved['sms_confirmation_template'] ?? $smsTemplates['confirmation'];
    $smsTemplates['reminder'] = $saved['sms_reminder_template'] ?? $smsTemplates['reminder'];
    $smsTemplates['aftercare'] = $saved['sms_aftercare_template'] ?? $smsTemplates['aftercare'];
  }
}
// Load current user's clinic contact info
$clinicCode = $currentUser['clinic_id'] ?? 'venera';
$clinicData = ['code'=>$clinicCode,'name'=>'','phone'=>'','phone_alt'=>'','address'=>'','map_link'=>''];
try {
  $stc = db()->prepare("SELECT name, phone, phone_alt, address, map_link FROM clinics WHERE code=? LIMIT 1");
  $stc->execute([$clinicCode]);
  $cd = $stc->fetch(PDO::FETCH_ASSOC);
  if ($cd) {
    $clinicData['name'] = $cd['name'] ?? '';
    $clinicData['phone'] = $cd['phone'] ?? '';
    $clinicData['phone_alt'] = $cd['phone_alt'] ?? '';
    $clinicData['address'] = $cd['address'] ?? '';
    $clinicData['map_link'] = $cd['map_link'] ?? '';
  }
} catch (Exception $e) { }

$clinicDefaults = get_clinic_metadata($clinicCode);
if (empty($clinicData['name'])) {
  $clinicData['name'] = $clinicDefaults['name'] ?? $clinicCode;
}
if (empty($clinicData['phone'])) {
  $clinicData['phone'] = $clinicDefaults['phone1'] ?? '';
}
if (empty($clinicData['phone_alt'])) {
  $clinicData['phone_alt'] = $clinicDefaults['phone2'] ?? '';
}
if (empty($clinicData['map_link'])) {
  $clinicData['map_link'] = $clinicDefaults['map'] ?? '';
}
if (empty($clinicData['address'])) {
  $clinicData['address'] = $clinicDefaults['address'] ?? '';
}
?>
<!doctype html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SMS лог & Сануулга</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%); }
    main { margin-left: 250px; padding: 32px; }
    .glass-card { background: white; border-radius: 12px; padding: 18px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); border:1px solid #eef2ff }
    .btn-icon { width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main>
  <div style="display:flex;gap:16px;align-items:center;justify-content:space-between;margin-bottom:18px">
    <h2 style="margin:0">SMS лог & Сануулга</h2>
    <div style="display:flex;gap:12px;align-items:center">
      <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus me-1"></i> Add SMS</button>
      <div style="color:#94a3b8">Хэрэглэгч: <?= htmlspecialchars($currentUser['name'] ?? '') ?></div>
    </div>
  </div>

  <div class="glass-card mb-4">
    <h5>SMS лог (хамгийн сүүлд)</h5>
    <div style="overflow:auto;margin-top:12px">
      <table class="table table-sm table-striped">
        <thead><tr><th>id</th><th>booking_id</th><th>phone</th><th>status</th><th>message</th><th>created_at</th></tr></thead>
        <tbody>
          <?php foreach($logs as $l): ?>
          <tr>
            <td><?= htmlspecialchars($l['id']) ?></td>
            <td><?= htmlspecialchars($l['booking_id']) ?></td>
            <td><?= htmlspecialchars($l['phone']) ?></td>
            <td><?= htmlspecialchars($l['status']) ?></td>
            <td style="max-width:600px;white-space:pre-wrap;"><?= htmlspecialchars($l['message']) ?></td>
            <td><?= htmlspecialchars($l['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="glass-card">
    <h5>Төлөвлөсөн SMS (pending)</h5>
    <div style="overflow:auto;margin-top:12px">
      <table class="table table-sm table-striped">
        <thead><tr><th>id</th><th>booking_id</th><th>phone</th><th>scheduled_at</th><th>message</th><th>үйлдэл</th></tr></thead>
        <tbody>
          <?php foreach($scheduled as $s): ?>
          <?php $dtLocal = date('Y-m-d\TH:i', strtotime($s['scheduled_at'])); ?>
          <tr id="sched-<?= htmlspecialchars($s['id']) ?>">
            <td><?= htmlspecialchars($s['id']) ?></td>
            <td><?= htmlspecialchars($s['booking_id']) ?></td>
            <td><input type="tel" id="phone-<?= $s['id'] ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($s['phone']) ?>" style="width:140px"></td>
            <td><input type="datetime-local" id="schedat-<?= $s['id'] ?>" class="form-control form-control-sm" value="<?= $dtLocal ?>" style="width:220px"></td>
            <td style="max-width:600px;"><textarea id="msg-<?= $s['id'] ?>" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($s['message']) ?></textarea></td>
            <td>
              <button class="btn btn-success btn-sm me-1" onclick="saveSched(<?= $s['id'] ?>)">Хадгалах</button>
              <button class="btn btn-danger btn-sm" onclick="deleteSched(<?= $s['id'] ?>)">Устгах</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<script>
  // Server-side templates injected for Add SMS modal
  const smsTemplates = <?php echo json_encode($smsTemplates, JSON_UNESCAPED_UNICODE); ?>;
</script>

<script>
  // clinic data for placeholder substitution
  const clinicData = <?php echo json_encode($clinicData, JSON_UNESCAPED_UNICODE); ?>;
</script>
</script>

<script>
  function openAddModal() {
    const html = `
      <div id="addSmsModal" style="position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1200;">
        <div id="addSmsModalContent" style="background:white;padding:18px;border-radius:12px;width:520px;max-width:95%;pointer-events:auto;">
          <h5>Загвар сонгох</h5>
          <div style="margin-top:12px">
            <label style="margin-top:8px">Загвар</label>
            <select id="addTemplate" class="form-select">
              <option value="">- Сонгох -</option>
              <option value="confirmation">Баталгаажуулалт</option>
              <option value="reminder">Сануулга</option>
              <option value="aftercare">Aftercare</option>
            </select>
            <label style="margin-top:8px">Message</label>
            <textarea id="addMessage" class="form-control" rows="6"></textarea>
            <div class="form-text">Загварыг сонгож, Хадгалах дарснаар текст clipboard руу хуулна.</div>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
            <button type="button" id="addCancelBtn" class="btn btn-secondary">Болих</button>
            <button type="button" id="addSaveBtn" class="btn btn-primary">Хадгалах</button>
          </div>
        </div>
      </div>`;
    const wrapper = document.createElement('div'); wrapper.innerHTML = html; document.body.appendChild(wrapper);
    const modal = document.getElementById('addSmsModal');
    const sel = document.getElementById('addTemplate');
    const msg = document.getElementById('addMessage');
    const cancel = document.getElementById('addCancelBtn');
    const save = document.getElementById('addSaveBtn');

    if (sel) {
      sel.addEventListener('change', function() {
        const v = this.value;
        // keep template placeholders as-is so they render per booking/clinic
        msg.value = (smsTemplates && smsTemplates[v]) ? smsTemplates[v] : '';
      });
    }
    cancel.addEventListener('click', function() { modal.remove(); });
    save.addEventListener('click', async function() {
      const text = msg.value.trim();
      const v = sel ? sel.value : '';
      if (!v) { alert('Загварын төрлийг сонгоно уу'); return; }
      if (!text) { alert('Message хоосон байна'); return; }
      // Save to settings.json via API, then also copy to clipboard for convenience
      try {
        const res = await fetch('api.php?action=save_sms_template', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ type: v, text }) });
        const j = await res.json();
        if (!j.ok) {
          alert('Хадгалахад алдаа: ' + (j.msg || 'Unknown'));
          return;
        }
        await navigator.clipboard.writeText(text);
        alert('Загвар хадгалж, clipboard руу хууллаа');
        modal.remove();
        // update local templates so reopening shows latest
        smsTemplates[v] = text;
      } catch (e) {
        window.prompt('Copy message (Ctrl+C, Enter):', text);
        modal.remove();
      }
    });
    // focus the select for keyboard users
    if (sel) sel.focus();
  }

  async function submitAddSms() {
    const message = document.getElementById('addMessage').value.trim();
    if (!message) { alert('Message хоосон байна'); return; }
    try {
      await navigator.clipboard.writeText(message);
      alert('Загвар clipboard руу хууллаа');
      document.getElementById('addSmsModal').remove();
    } catch (e) {
      // Fallback: open prompt to let user copy
      window.prompt('Copy message:', message);
      document.getElementById('addSmsModal').remove();
    }
  }
  async function deleteSched(id) {
    if (!confirm('Энэ төлөвлөсөн SMS-г устгах уу?')) return;
    try {
      const res = await fetch('api.php?action=delete_sms', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id }) });
      const j = await res.json();
      if (j.ok) {
        document.getElementById('sched-' + id).remove();
      } else {
        alert('Алдаа: ' + (j.msg || 'Unknown'));
      }
    } catch (e) { alert('Сервертэй холбогдоход алдаа'); }
  }

  async function saveSched(id) {
    const phone = document.getElementById('phone-' + id).value.trim();
    const message = document.getElementById('msg-' + id).value.trim();
    const scheduled_at = document.getElementById('schedat-' + id).value;
    if (!phone || !message) { alert('Утас болон мессеж хоосон байж болохгүй'); return; }
    try {
      const res = await fetch('api.php?action=update_sms', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, phone, message, scheduled_at }) });
      const j = await res.json();
      if (j.ok) {
        const row = document.getElementById('sched-' + id);
        row.style.transition = 'background 0.3s';
        row.style.background = '#e6ffed';
        setTimeout(()=> row.style.background = '', 1200);
        alert('Хадгаллаа');
      } else {
        alert('Алдаа: ' + (j.msg || 'Unknown'));
      }
    } catch (e) { alert('Сервертэй холбогдоход алдаа'); }
  }
</script>
</body>
</html>
