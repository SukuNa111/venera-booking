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
      // Redirect based on role
      if (isset($u['role']) && $u['role'] === 'doctor') {
        header('Location: my_schedule.php');
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
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Нэвтрэх — flowlabs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      min-height: 100vh;
      display: flex;
      background: #0a0a0f;
      overflow: hidden;
    }
    
    /* Left side - Animated gradient background */
    .hero-section {
      flex: 1;
      background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 3rem;
      position: relative;
      overflow: hidden;
    }
    
    .hero-section::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: 
        radial-gradient(circle at 30% 20%, rgba(34, 197, 94, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 70% 80%, rgba(59, 130, 246, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.08) 0%, transparent 50%);
      animation: pulse 15s ease-in-out infinite;
    }
    
    @keyframes pulse {
      0%, 100% { transform: scale(1) rotate(0deg); opacity: 1; }
      50% { transform: scale(1.1) rotate(5deg); opacity: 0.8; }
    }
    
    .hero-content {
      position: relative;
      z-index: 1;
      text-align: center;
      color: white;
    }
    
    .hero-logo {
      width: 200px;
      margin-bottom: 2rem;
      filter: drop-shadow(0 20px 40px rgba(0,0,0,0.3));
    }
    
    .hero-title {
      font-size: 2.5rem;
      font-weight: 700;
      margin-bottom: 1rem;
      background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    
    .hero-subtitle {
      font-size: 1.1rem;
      color: #94a3b8;
      max-width: 400px;
      line-height: 1.6;
    }
    
    .hero-features {
      margin-top: 3rem;
      display: flex;
      gap: 2rem;
      flex-wrap: wrap;
      justify-content: center;
    }
    
    .feature-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: #64748b;
      font-size: 0.9rem;
    }
    
    .feature-item i {
      color: #22c55e;
      font-size: 1.1rem;
    }
    
    /* Right side - Login form */
    .login-section {
      width: 480px;
      min-width: 400px;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 3rem;
      border-left: 1px solid rgba(255,255,255,0.05);
    }
    
    .login-header {
      margin-bottom: 2.5rem;
    }
    
    .login-header h2 {
      font-size: 1.75rem;
      font-weight: 600;
      color: #f1f5f9;
      margin-bottom: 0.5rem;
    }
    
    .login-header p {
      color: #64748b;
      font-size: 0.95rem;
    }
    
    .form-group {
      margin-bottom: 1.5rem;
    }
    
    .form-label {
      display: block;
      color: #94a3b8;
      font-size: 0.875rem;
      font-weight: 500;
      margin-bottom: 0.5rem;
    }
    
    .input-wrapper {
      position: relative;
    }
    
    .input-wrapper i {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #64748b;
      font-size: 1rem;
    }
    
    .form-input {
      width: 100%;
      padding: 0.875rem 1rem 0.875rem 2.75rem;
      background: rgba(30, 41, 59, 0.5);
      border: 1px solid rgba(148, 163, 184, 0.1);
      border-radius: 12px;
      color: #f1f5f9;
      font-size: 1rem;
      transition: all 0.3s ease;
    }
    
    .form-input:focus {
      outline: none;
      border-color: #3b82f6;
      background: rgba(30, 41, 59, 0.8);
      box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    
    .form-input::placeholder {
      color: #475569;
    }
    
    .password-toggle {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #64748b;
      cursor: pointer;
      padding: 0.25rem;
      transition: color 0.2s;
    }
    
    .password-toggle:hover {
      color: #94a3b8;
    }
    
    .form-options {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    
    .remember-me {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: #94a3b8;
      font-size: 0.875rem;
      cursor: pointer;
    }
    
    .remember-me input {
      width: 1rem;
      height: 1rem;
      accent-color: #3b82f6;
    }
    
    .forgot-link {
      color: #3b82f6;
      text-decoration: none;
      font-size: 0.875rem;
      font-weight: 500;
      transition: color 0.2s;
    }
    
    .forgot-link:hover {
      color: #60a5fa;
    }
    
    .btn-login {
      width: 100%;
      padding: 1rem;
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      border: none;
      border-radius: 12px;
      color: white;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    
    .btn-login:hover {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      transform: translateY(-2px);
      box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
    }
    
    .btn-login:active {
      transform: translateY(0);
    }
    
    .alert-error {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.2);
      color: #fca5a5;
      padding: 1rem;
      border-radius: 12px;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-size: 0.9rem;
    }
    
    .alert-error i {
      color: #ef4444;
    }
    
    .divider {
      display: flex;
      align-items: center;
      margin: 2rem 0;
      color: #475569;
      font-size: 0.8rem;
    }
    
    .divider::before,
    .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: rgba(148, 163, 184, 0.1);
    }
    
    .divider span {
      padding: 0 1rem;
    }
    
    .footer-text {
      text-align: center;
      color: #475569;
      font-size: 0.8rem;
      margin-top: 2rem;
    }
    
    /* Mobile responsive */
    @media (max-width: 900px) {
      body { flex-direction: column; }
      .hero-section { 
        min-height: 200px;
        padding: 2rem; 
      }
      .hero-title { font-size: 1.5rem; }
      .hero-subtitle { font-size: 0.9rem; }
      .hero-features { display: none; }
      .login-section { 
        width: 100%; 
        min-width: auto;
        padding: 2rem;
        border-left: none;
        border-top: 1px solid rgba(255,255,255,0.05);
      }
    }
  </style>
</head>
<body>
  <!-- Hero Section -->
  <div class="hero-section">
    <div class="hero-content">
      <?php $loginLogo = __DIR__.'/assets/logo.png'; $loginLogoVer = file_exists($loginLogo) ? filemtime($loginLogo) : time(); ?>
      <img src="assets/logo.png?v=<?= $loginLogoVer ?>" alt="NG AI" class="hero-logo">
      <h1 class="hero-title">Захиалгын Систем</h1>
      <p class="hero-subtitle">
        Эмнэлгийн цаг захиалга, эмч нарын хуваарь, үйлчлүүлэгчийн мэдээллийг нэг дороос удирдах платформ
      </p>
      <div class="hero-features">
        <div class="feature-item">
          <i class="fas fa-check-circle"></i>
          <span>Хялбар захиалга</span>
        </div>
        <div class="feature-item">
          <i class="fas fa-check-circle"></i>
          <span>SMS мэдэгдэл</span>
        </div>
        <div class="feature-item">
          <i class="fas fa-check-circle"></i>
          <span>Тайлан статистик</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Login Section -->
  <div class="login-section">
    <div class="login-header">
      <h2>Тавтай морил 👋</h2>
      <p>Системд нэвтрэхийн тулд мэдээллээ оруулна уу</p>
    </div>

    <?php if ($error): ?>
      <div class="alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="form-group">
        <label class="form-label">Утасны дугаар</label>
        <div class="input-wrapper">
          <i class="fas fa-phone"></i>
          <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="12"
                 class="form-input" name="phone" placeholder="99991234"
                 value="<?= htmlspecialchars($phone_old) ?>" required autocomplete="tel">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">PIN код</label>
        <div class="input-wrapper">
          <i class="fas fa-lock"></i>
          <input type="password" class="form-input" name="pin" id="pinInput" 
                 placeholder="••••" required autocomplete="current-password">
          <button type="button" class="password-toggle" id="togglePin">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="form-options">
        <label class="remember-me">
          <input type="checkbox" id="remember">
          <span>Намайг сана</span>
        </label>
        <a href="#" class="forgot-link" onclick="alert('Админ руу хандана уу.');return false;">
          PIN мартсан?
        </a>
      </div>

      <button type="submit" class="btn-login">
        <i class="fas fa-sign-in-alt"></i>
        Нэвтрэх
      </button>
    </form>

    <div class="divider">
      <span>Аюулгүй холболт</span>
    </div>

    <div class="footer-text">
      © <?= date('Y') ?> flowlabs. Бүх эрх хуулиар хамгаалагдсан.
    </div>
  </div>

  <script>
    // PIN show/hide toggle
    document.getElementById('togglePin').addEventListener('click', function() {
      const input = document.getElementById('pinInput');
      const icon = this.querySelector('i');
      
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
      input.focus();
    });

    // Auto-focus first empty input
    document.addEventListener('DOMContentLoaded', function() {
      const phone = document.querySelector('input[name="phone"]');
      const pin = document.querySelector('input[name="pin"]');
      
      if (!phone.value) {
        phone.focus();
      } else if (!pin.value) {
        pin.focus();
      }
    });
  </script>
</body>
</html>
