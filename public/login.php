<?php
require_once __DIR__ . '/../config.php';

// Нэвтэрсэн бол index рүү
if (!empty($_SESSION['uid'])) {
  header('Location: index.php');
  exit;
}

$error = '';
$phone_old = '';

// Rate limiting - 5 оролдлого 15 минутанд
function checkLoginAttempts($phone) {
    $key = 'login_attempts_' . md5($phone . $_SERVER['REMOTE_ADDR']);
    $file = sys_get_temp_dir() . '/' . $key;
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && time() - $data['first_attempt'] < 900) { // 15 минут
            if ($data['count'] >= 5) {
                $remaining = 900 - (time() - $data['first_attempt']);
                return ['blocked' => true, 'remaining' => ceil($remaining / 60)];
            }
            return ['blocked' => false, 'count' => $data['count']];
        }
    }
    return ['blocked' => false, 'count' => 0];
}

function recordLoginAttempt($phone, $success = false) {
    $key = 'login_attempts_' . md5($phone . $_SERVER['REMOTE_ADDR']);
    $file = sys_get_temp_dir() . '/' . $key;
    
    if ($success) {
        @unlink($file);
        return;
    }
    
    $data = ['count' => 1, 'first_attempt' => time()];
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true);
        if ($existing && time() - $existing['first_attempt'] < 900) {
            $data = ['count' => $existing['count'] + 1, 'first_attempt' => $existing['first_attempt']];
        }
    }
    file_put_contents($file, json_encode($data));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $phone = trim($_POST['phone'] ?? '');
  $pin   = trim($_POST['pin'] ?? '');
  $phone_old = $phone;
  
  // Rate limit шалгах
  $rateCheck = checkLoginAttempts($phone);
  if ($rateCheck['blocked']) {
    $error = "⏳ Хэт олон оролдлого. {$rateCheck['remaining']} минут хүлээнэ үү.";
  } elseif ($phone === '' || $pin === '') {
    $error = '📱 Утасны дугаар болон PIN заавал оруулна уу.';
  } else {
    $st = db()->prepare("SELECT * FROM users WHERE phone=? LIMIT 1");
    $st->execute([$phone]);
    $u = $st->fetch();

    if ($u && password_verify($pin, $u['pin_hash'])) {
      recordLoginAttempt($phone, true); // Амжилттай - counter устгах
      $_SESSION['uid']       = (int)$u['id'];
      $_SESSION['name']      = $u['name'];
      $_SESSION['role']      = $u['role'];
      $_SESSION['clinic_id'] = $u['clinic_id'] ?? 'venera';
      // If a doctor logs in, send them directly to the reports (clinic-scoped).
      
      if (isset($u['role']) && $u['role'] === 'doctor') {
        header('Location: reports.php');
      } else {
        header('Location: index.php');
      }
      exit;
    } else {
      recordLoginAttempt($phone, false); // Амжилтгүй - counter нэмэх
      $error = '❌ Нэвтрэх мэдээлэл буруу байна.';
    }
  }
}
?>
<!doctype html>
<html lang="mn" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Нэвтрэх — Venera-Dent</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Интер шрифт (сонголт) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      --brand:#0f3b57;
      --brand-2:#1b5f84;
    }
    html,body{ height:100%; }
    body{
      margin:0;
      font-family:"Inter", system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
      background:
        radial-gradient(1200px 600px at 100% 0%, rgba(27,95,132,.25), rgba(27,95,132,0) 60%),
        radial-gradient(1000px 700px at 0% 100%, rgba(15,59,87,.35), rgba(15,59,87,0) 60%),
        linear-gradient(135deg, var(--brand), var(--brand-2));
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
    }
    .login-card{
      width:min(420px, 100%);
      background:#ffffff;
      border-radius:24px;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
      overflow:hidden;
    }
    .login-hero{
      background:linear-gradient(135deg, #e8f3f9, #fff);
      padding:22px 22px 16px 22px;
      border-bottom:1px solid rgba(15,59,87,.08);
    }
    .app-badge{
      display:inline-flex;align-items:center;gap:10px;
      color:#0f3b57;text-decoration:none;
    }
    .brand-dot{
      width:36px;height:36px;border-radius:12px;background:linear-gradient(135deg, #06b6d4, #22d3ee);
      display:inline-flex;align-items:center;justify-content:center;color:#0b3a4f;font-weight:700;
      box-shadow:0 6px 16px rgba(6,182,212,.35);
    }
    .login-body{ padding:24px; }
    .form-label{ font-weight:600; }
    .btn-brand{
      background:var(--brand);border-color:var(--brand);
    }
    .btn-brand:hover{ background:#0c2f45;border-color:#0c2f45; }
    .muted{ color:#6b7280;font-size:.9rem; }
    .input-group .form-control{
      padding-top:.6rem;padding-bottom:.6rem;
    }
    .footer-note{
      text-align:center;color:#9ca3af;font-size:.85rem;padding:14px 0;
    }
  </style>
</head>
<body>

  <div class="login-card">

    <!-- Дээд хэсэг -->
    <div class="login-hero">
      <div class="d-flex align-items-center justify-content-between">
        <div class="app-badge">
          <img src="assets/logo.svg" alt="logo" style="width:56px;height:56px;border-radius:8px;object-fit:cover;" onerror="this.style.display='none'">
          <div>
            <div class="fw-bold">Venera-Dent</div>
            <div class="muted">Захиалгын системд нэвтрэх</div>
          </div>
        </div>
        <!-- Light/Dark switch (сонголт) -->
        <button id="themeBtn" class="btn btn-sm btn-outline-dark">🌙</button>
      </div>
    </div>

    <!-- Гол форм -->
    <div class="login-body">
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <div class="mb-3">
          <label class="form-label">Утасны дугаар</label>
          <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="12"
                 class="form-control" name="phone" placeholder="99991234"
                 value="<?= htmlspecialchars($phone_old) ?>" required>
        </div>

        <div class="mb-2">
          <label class="form-label">PIN код</label>
          <div class="input-group">
            <input type="password" class="form-control" name="pin" id="pinInput" placeholder="••••" required>
            <button class="btn btn-outline-secondary" type="button" id="togglePin">Харах</button>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember" disabled>
            <label class="form-check-label" for="remember">Намайг сана</label>
          </div>
          <a class="text-decoration-none muted" href="#" onclick="alert('Админ руу хандана уу.');return false;">PIN мартсан?</a>
        </div>

        <button class="btn btn-brand text-white w-100 py-2" type="submit">
          Нэвтрэх
        </button>
      </form>
    </div>

    <div class="footer-note">
      © <?= date('Y') ?> Venera Group
    </div>
  </div>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // PIN show/hide
    document.getElementById('togglePin').addEventListener('click', function(){
      const i = document.getElementById('pinInput');
      const isPwd = i.type === 'password';
      i.type = isPwd ? 'text' : 'password';
      this.textContent = isPwd ? 'Нуух' : 'Харах';
      i.focus();
    });

    // Light/Dark theme toggle (client-side only)
    const themeBtn = document.getElementById('themeBtn');
    themeBtn.addEventListener('click', ()=>{
      const html = document.documentElement;
      const cur = html.getAttribute('data-bs-theme') || 'light';
      const next = cur === 'light' ? 'dark' : 'light';
      html.setAttribute('data-bs-theme', next);
      themeBtn.textContent = next === 'dark' ? '☀️' : '🌙';
    });
  </script>
</body>
</html>
