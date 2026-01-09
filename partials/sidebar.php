<?php
require_once __DIR__ . '/../config.php';
require_login();

$u = current_user();
$role   = $u['role'] ?? 'guest';
$name   = $u['name'] ?? 'Хэрэглэгч';
$clinic = $u['clinic_id'] ?? 'venera';

// Clinic-specific branding (logo + name)
// Default clinic name will be replaced if found in DB or map
$clinicName = 'Клиник';
$clinicNameDb = null;
try {
  $stmt = db()->prepare("SELECT name FROM clinics WHERE code = :c LIMIT 1");
  $stmt->execute([':c' => $clinic]);
  $clinicNameDb = $stmt->fetchColumn();
} catch (Exception $e) {
  $clinicNameDb = null;
}
$clinicMap = [
  'venera' => 'Venera V.I.P Clinic',
  'khatan' => 'Goo Khatan Medical',
  'luxor'  => 'Golden Luxor',
  'dent'   => 'Venera Dent',
  'all'    => 'Бүх эмнэлэг'
];
$clinicName = $clinicNameDb ?: ($clinicMap[$clinic] ?? 'Клиник');

// Resolve logo: prefer clinic.png (e.g., khatan.png), then logo-<clinic>.png, fallback to logo.png
$logoFileCandidates = [
  __DIR__ . '/../public/assets/' . $clinic . '.png',
  __DIR__ . '/../public/assets/logo-' . $clinic . '.png',
  __DIR__ . '/../public/assets/logo_flowlab.png',
  __DIR__ . '/../public/assets/logo.png'
];
if ($clinic === 'all') {
  array_unshift($logoFileCandidates, __DIR__ . '/../public/assets/logo_flowlab.png');
}
$logoPath = 'assets/logo.png';
foreach ($logoFileCandidates as $lf) {
  if (file_exists($lf)) {
    $logoPath = 'assets/' . basename($lf);
    break;
  }
}
$logoVer = file_exists(__DIR__ . '/../public/' . $logoPath) ? filemtime(__DIR__ . '/../public/' . $logoPath) : time();

// profile_api.php бүрэн URL (public дотор байгаа)
$profileApiUrl = app_url('profile_api.php');
?>
<style>
:root {
  --sidebar-bg: #111827;
  --sidebar-text: #e5e7eb;
  --sidebar-hover: rgba(255,255,255,0.1);
  --light-bg: #f6f7fb;
}
body {
  background: var(--light-bg);
  font-family: "Inter", sans-serif;
  margin: 0;
  color: #333;
  transition: background 0.3s, color 0.3s;
}

/* ===== Sidebar ===== */
.sidebar-dark {
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  width: 250px;
  background: var(--sidebar-bg);
  color: var(--sidebar-text);
  padding: 1rem;
  z-index: 1050;
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sidebar-dark.closed { transform: translateX(-100%); }

/* Mobile state: closed by default on small screens */
@media (max-width: 991.98px) {
  .sidebar-dark {
    transform: translateX(-100%);
    box-shadow: 10px 0 30px rgba(0,0,0,0.3);
  }
  .sidebar-dark.open {
    transform: translateX(0);
  }
}

/* Backdrop for mobile */
.sidebar-backdrop {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 1040;
  display: none;
  backdrop-filter: blur(2px);
}
.sidebar-backdrop.show { display: block; }

.sidebar-dark .header {
  display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1rem;
}
.sidebar-dark .header img {
  width: 36px; height: 36px; border-radius: 50%; background: #06b6d4;
}
.sidebar-dark .header div strong { color: #fff; }
.sidebar-dark .header small { color: #9ca3af; font-size: 0.8rem; }
.sidebar-dark .nav-link {
  display: block;
  color: #94a3b8 !important;
  border-radius: 0.5rem;
  margin-bottom: 0.4rem;
  font-size: 0.95rem;
  padding: 0.45rem 0.75rem;
  text-decoration: none;
  transition: all 0.2s;
}
.sidebar-dark .nav-link:hover,
.sidebar-dark .nav-link.active {
  color: #fff !important;
  background: var(--sidebar-hover);
  transform: translateX(4px);
}

/* Doctor Menu Items - Colorful Style */
.sidebar-dark .nav-link.doctor-menu {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0.45rem 0.75rem;
  margin-bottom: 0.4rem;
  border-radius: 0.5rem;
  background: transparent;
  border: none;
  transition: all 0.2s ease;
}
.sidebar-dark .nav-link.doctor-menu:hover {
  background: var(--sidebar-hover);
  transform: translateX(4px);
}
.sidebar-dark .nav-link.doctor-menu.active {
  background: var(--sidebar-hover);
  color: #fff !important;
}
.sidebar-dark .nav-link.doctor-menu .menu-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 5px;
  font-size: 12px;
  flex-shrink: 0;
}

.sidebar-dark hr {
  border-color: rgba(255,255,255,0.1);
  margin: 0.75rem 0;
}
.sidebar-dark .footer {
  margin-top: 1.5rem;
  font-size: 0.8rem;
  color: #9ca3af;
  text-align: center;
}

/* ===== Main & Main Content Synergy ===== */
main, .main-content {
  margin-left: 250px;
  padding: 1.2rem;
  background: var(--light-bg);
  min-height: 100vh;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
main.full, .main-content.full { margin-left: 0; }

@media (max-width: 991.98px) {
  main, .main-content { margin-left: 0 !important; padding: 1rem; }
}

/* Global Mobile Toggle */
.mobile-toggle-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: var(--primary);
  color: white;
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 1060;
  box-shadow: 0 4px 15px rgba(0,0,0,0.3);
  border: none;
  cursor: pointer;
  transition: transform 0.2s;
}
.mobile-toggle-btn:active { transform: scale(0.9); }

@media (max-width: 991.98px) {
  .mobile-toggle-btn { display: flex; }
}

/* ===== Toolbar ===== */
.calendar-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: space-between;
  background: #0f172a;
  color: #fff;
  border-radius: 0.5rem;
  padding: .6rem .9rem;
  margin-bottom: 1rem;
}
.calendar-toolbar .left,
.calendar-toolbar .right {
  display: flex;
  align-items: center;
  gap: .5rem;
}
.calendar-toolbar select,
.calendar-toolbar button {
  font-size: .9rem;
}

/* ===== Dark mode ===== */
.dark-mode { background: #1e293b; color: #e5e7eb; }
.dark-mode main { background: #0f172a; color: #e5e7eb; }
.dark-mode .sidebar-dark { background: #0f172a; }

/* Profile modal dark readonly/disabled fix (stronger override, global) */
.modal-content input[readonly],
.modal-content input[disabled],
.modal-content textarea[readonly],
.modal-content textarea[disabled] {
  background-color: #1e293b !important;
  color: #cbd5e1 !important;
  border-color: #334155 !important;
  opacity: 1 !important;
  -webkit-text-fill-color: #cbd5e1 !important;
  box-shadow: none !important;
}
.modal-content input[readonly]::placeholder,
.modal-content input[disabled]::placeholder {
  color: #64748b !important;
  opacity: 1 !important;
}
/* Custom thin scrollbar for nav */
.nav-container::-webkit-scrollbar { width: 4px; }
.nav-container::-webkit-scrollbar-track { background: transparent; }
.nav-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.05); border-radius: 10px; }
.nav-container:hover::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); }
</style>

<!-- Backdrop for Mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ===== Sidebar ===== -->
<aside class="sidebar-dark" id="sidebar">
  <div class="header" style="padding-bottom:1rem;border-bottom:1px solid rgba(255,255,255,0.04);">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="display:flex;flex-direction:column;">
          <strong style="color:#fff;font-size:1rem;"><img src="<?= htmlspecialchars($logoPath) ?>?v=<?= $logoVer ?>" alt="<?= htmlspecialchars($clinicName) ?>" id="siteLogo" style="width:44px;height:44px;border-radius:8px;object-fit:cover;vertical-align:middle;margin-right:8px;"><?= htmlspecialchars($clinicName) ?></strong>
          <small style="color:#9ca3af">Захиалгын систем</small>
      </div>
    </div>
  </div>

  <div class="nav-container" style="flex:1; overflow-y:auto; margin-bottom:1rem; padding-right:5px;">
    <nav class="nav flex-column" style="margin-top:0.8rem;">
      <?php if (in_array($role, ['admin', 'super_admin', 'reception', 'doctor'])): ?>
        <a class="nav-link <?= active('index.php') ?>" href="<?= app_url('index.php') ?>">📅 Үзлэгийн хуваарь</a>
        <?php if (in_array($role, ['admin', 'super_admin'])): ?>
          <a class="nav-link <?= active('dashboard.php') ?>" href="<?= app_url('dashboard.php') ?>">📊 Аналитик тайлан</a>
        <?php endif; ?>
        
        <a class="nav-link <?= active('bookings.php') ?>" href="<?= app_url('bookings.php') ?>">📋 Захиалгууд</a>
        <a class="nav-link <?= active('patient_history.php') ?>" href="<?= app_url('patient_history.php') ?>">📜 Өвчтөний түүх</a>
        <a class="nav-link <?= active('inventory.php') ?>" href="<?= app_url('inventory.php') ?>">📦 Материал</a>
        <a class="nav-link <?= active('treatments.php') ?>" href="<?= app_url('treatments.php') ?>">🦷 Эмчилгээ</a>
      <?php endif; ?>

      <?php if ($role === 'super_admin'): ?>
        <a class="nav-link <?= active('sms_messages.php') ?>" href="<?= app_url('sms_messages.php') ?>">💬 Мессежийн тохиргоо</a>
        <a class="nav-link <?= active('sms_logs.php') ?>" href="<?= app_url('sms_logs.php') ?>">📨 SMS Логийн түүх</a>
      <?php endif; ?>
      <?php if ($role === 'reception'): ?>
        <a class="nav-link <?= active('receptionist.php') ?>" href="<?= app_url('receptionist.php') ?>">🧑‍⚕️ Эмч нэмэх</a>
      <?php endif; ?>
      <?php if (in_array($role, ['admin', 'super_admin'])): ?>
        <a class="nav-link <?= active('doctors.php') ?>" href="<?= app_url('doctors.php') ?>">🧑‍⚕️ Эмч нар</a>
        <a class="nav-link <?= active('settings.php') ?>" href="<?= app_url('settings.php') ?>">⚙️ Тохиргоо</a>
      <?php endif; ?>
      <?php if ($role === 'super_admin'): ?>
        <hr>
        <a class="nav-link <?= active('users.php') ?>" href="<?= app_url('users.php') ?>">👥 Хэрэглэгчид</a>
        <a class="nav-link <?= active('clinics.php') ?>" href="<?= app_url('clinics.php') ?>">🏥 Эмнэлгүүд</a>
      <?php endif; ?>
      <?php if ($role === 'doctor'): ?>
        <a class="nav-link doctor-menu <?= active('my_schedule.php') ?>" href="<?= app_url('my_schedule.php') ?>">
          <span class="menu-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">📅</span>
          Миний хуваарь
        </a>
        <a class="nav-link doctor-menu <?= active('my_hours.php') ?>" href="<?= app_url('my_hours.php') ?>">
          <span class="menu-icon" style="background:linear-gradient(135deg,#10b981,#059669);">⏰</span>
          Ажиллах цаг
        </a>
      <?php endif; ?>
      <a class="nav-link <?= active('feedback.php') ?>" href="<?= app_url('feedback.php') ?>">💡 Санал хүсэлт</a>
      <hr>
    </nav>
  </div>

  <!-- ===== Profile area (static at bottom) ===== -->
  <div class="profile-area" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:0.5rem 0;border-top:1px solid rgba(255,255,255,0.05);background:var(--sidebar-bg);">
    <img id="sidebarAvatar" src="https://cdn-icons-png.flaticon.com/512/4140/4140048.png" alt="avatar"
         style="width:40px;height:40px;border-radius:50%;object-fit:cover;cursor:pointer;border:2px solid rgba(255,255,255,0.06);" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
    <div style="text-align:center;color:#e2e8f0;margin-bottom:5px;">
      <div style="font-weight:700;font-size:0.9rem;line-height:1.2;"><?= htmlspecialchars($name) ?></div>
      <div style="font-size:0.75rem;color:#9ca3af;"><?= htmlspecialchars($role) ?></div>
    </div>
    <div style="width:100%;display:flex;flex-direction:column;gap:4px;">
      <button type="button" onclick="openProfileModal()" class="btn btn-sm profile-btn" style="width:100%;font-size:0.75rem;background:rgba(255,255,255,0.04);color:#93c5fd;border:1px solid rgba(255,255,255,0.06);border-radius:6px;padding:.3rem .5rem;">Миний профайл</button>
      <a href="<?= app_url('logout.php') ?>" class="btn btn-sm logout-btn"
         style="width:100%;font-size:0.75rem;background:transparent;color:#f87171;border:1px solid rgba(255,255,255,0.03);border-radius:6px;padding:.3rem .5rem;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
        <i class="fas fa-sign-out-alt me-1"></i>Гарах
      </a>
    </div>
  </div>

  <div class="footer" style="padding-top:10px;font-size:0.75rem;opacity:0.6;">© <?= date('Y') ?> <?= htmlspecialchars($clinicName) ?></div>
</aside>

<!-- Global Toggle Button for Mobile -->
<button class="mobile-toggle-btn" id="btnToggleSidebarGlobal" title="Цэс нээх">
  <i class="fas fa-bars"></i>
</button>

<!-- ===== Profile Modal (all pages) ===== -->
<!-- Hidden by default to avoid showing when Bootstrap CSS/JS isn't present -->
<div class="modal fade" id="profileModal" tabindex="-1" style="display:none;" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content"
         style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);
                border:1px solid #334155;box-shadow:0 20px 60px rgba(0,0,0,.4);">
      <div class="modal-header"
           style="background:linear-gradient(135deg,#3b82f6 0%,#8b5cf6 100%);
                  color:#fff;border:none;padding:1.5rem;">
        <h5 class="modal-title" style="font-weight:700;font-size:1.25rem;">
          <i class="fas fa-user-circle me-2"></i>Миний профайл
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"
                aria-label="Close"
                style="filter:brightness(0) invert(1);"></button>
      </div>
      <div class="modal-body" style="padding:1.5rem;">
        <div style="text-align:center;margin-bottom:2rem;">
          <div class="profile-avatar"
               style="width:100px;height:100px;margin:0 auto 1rem;
                      background:linear-gradient(135deg,#3b82f6,#8b5cf6);
                      border-radius:50%;display:flex;align-items:center;
                      justify-content:center;color:#fff;font-weight:700;
                      font-size:2.5rem;overflow:hidden;">
            <img id="profileAvatar" src="" alt="avatar"
                 style="width:100%;height:100%;border-radius:50%;
                        object-fit:cover;display:none;">
            <span id="profileAvatarText"
                  style="font-size:2.5rem;color:#fff;font-weight:700;display:none;"></span>
          </div>
          <label style="display:inline-block;margin-top:1rem;">
            <input type="file" id="avatarUpload" accept="image/*"
                   style="display:none;" onchange="window.uploadAvatar(this)">
            <button type="button" class="btn btn-sm"
                    style="background:rgba(59,130,246,0.2);color:#93c5fd;
                           border:1px solid rgba(59,130,246,0.3);border-radius:8px;
                           padding:.5rem 1rem;cursor:pointer;"
                    onclick="document.getElementById('avatarUpload').click()">
              <i class="fas fa-camera me-1"></i>Зураг солих
            </button>
          </label>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Нэр</label>
          <div style="display:flex;gap:.5rem;">
            <input type="text" id="profileName" class="form-control"
                   style="flex:1;background:#0f172a;border:2px solid #334155;
                          color:#e2e8f0;border-radius:8px;padding:.75rem 1rem;">
            <button type="button" id="btnEditName" class="btn btn-sm"
                    style="background:rgba(59,130,246,0.2);color:#93c5fd;
                           border:1px solid rgba(59,130,246,0.3);border-radius:8px;
                           cursor:pointer;">
              <i class="fas fa-edit"></i>
            </button>
          </div>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Хэрэглэгчийн ID</label>
          <div style="color:#94a3b8;padding:.75rem 1rem;background:rgba(15,23,42,0.5);
                      border-radius:8px;border:1px solid #334155;">
            <i class="fas fa-id-card me-1"></i><?= htmlspecialchars($u['id'] ?? 'N/A') ?>
          </div>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Дүрэм</label>
          <div style="color:#94a3b8;padding:.75rem 1rem;background:rgba(15,23,42,0.5);
                      border-radius:8px;border:1px solid #334155;">
            <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($u['role'] ?? 'reception') ?>
          </div>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Клиник</label>
          <div style="color:#94a3b8;padding:.75rem 1rem;background:rgba(15,23,42,0.5);
                      border-radius:8px;border:1px solid #334155;">
            <i class="fas fa-hospital-user me-1"></i><?= htmlspecialchars($u['clinic_id'] ?? 'venera') ?>
          </div>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Утас</label>
          <div style="display:flex;gap:.5rem;">
            <input type="tel" id="profilePhone" class="form-control"
                   style="flex:1;background:#0f172a;border:2px solid #334155;
                          color:#e2e8f0;border-radius:8px;padding:.75rem 1rem;">
            <button type="button" id="btnEditPhone" class="btn btn-sm"
                    style="background:rgba(59,130,246,0.2);color:#93c5fd;
                           border:1px solid rgba(59,130,246,0.3);border-radius:8px;
                           cursor:pointer;">
              <i class="fas fa-edit"></i>
            </button>
          </div>
        </div>

        <div class="profile-field" style="margin-bottom:1.5rem;">
          <label style="color:#e2e8f0;font-weight:600;margin-bottom:.5rem;display:block;">Нууц үг</label>
          <div style="display:flex;gap:.5rem;">
            <input type="password" id="profilePassword" class="form-control"
                   style="flex:1;background:#0f172a;border:2px solid #334155;
                          color:#e2e8f0;border-radius:8px;padding:.75rem 1rem;"
                   placeholder="••••">
            <button type="button" id="btnEditPassword" class="btn btn-sm"
                    style="background:rgba(59,130,246,0.2);color:#93c5fd;
                           border:1px solid rgba(59,130,246,0.3);border-radius:8px;
                           cursor:pointer;">
              <i class="fas fa-edit"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer"
           style="background:#0f172a;border-top:1px solid #334155;padding:1.25rem;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                style="background:#1e293b;border:2px solid #334155;color:#cbd5e1;">
          <i class="fas fa-times me-1"></i>Хаах
        </button>
        <button type="button" id="btnSaveProfile" class="btn btn-primary"
                style="background:linear-gradient(135deg,#3b82f6,#2563eb);
                       border:none;color:#fff;">
          <i class="fas fa-save me-1"></i>Хадгалах
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// profile_api бүрэн URL (PHP-ээс авсан)
const PROFILE_API_URL = "<?= $profileApiUrl ?>";

function profileApiUrl(action) {
  if (action) return PROFILE_API_URL + '?action=' + encodeURIComponent(action);
  return PROFILE_API_URL;
}

let profileModal = null;

document.addEventListener('DOMContentLoaded', function () {
  // === Sidebar toggle / dark mode ===
  const sidebar = document.getElementById('sidebar');
  const main = document.querySelector('main') || document.querySelector('.main-content');
  const btnToggle = document.getElementById('btnToggleSidebar');
  const btnToggleGlobal = document.getElementById('btnToggleSidebarGlobal');
  const btnDark = document.getElementById('btnDarkMode');
  const backdrop = document.getElementById('sidebarBackdrop');
  let dark = false;

  const toggleFn = (e) => {
    if (e) e.stopPropagation();
    const isMobile = window.innerWidth < 992;
    if (isMobile) {
      sidebar.classList.toggle('open');
      if (backdrop) backdrop.classList.toggle('show');
    } else {
      sidebar.classList.toggle('closed');
      if (main) main.classList.toggle('full');
    }
  };

  if (sidebar && btnToggle) {
    btnToggle.addEventListener('click', toggleFn);
  }
  if (sidebar && btnToggleGlobal) {
    btnToggleGlobal.addEventListener('click', toggleFn);
  }

  if (sidebar && backdrop) {
    backdrop.addEventListener('click', () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
    });
  }

  if (sidebar) {
    // Auto-close on mobile when clicking a nav link
    sidebar.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth < 992) {
          sidebar.classList.remove('open');
          if (backdrop) backdrop.classList.toggle('show', false);
        }
      });
    });
  }

  if (btnDark) {
    btnDark.addEventListener('click', () => {
      dark = !dark;
      document.body.classList.toggle('dark-mode');
      btnDark.innerText = dark ? '☀️' : '🌙';
    });
  }

  // === Calendar integration (хэрвээ байгаа бол) ===
  if (typeof window.goToPrev === 'function' && document.getElementById('prev')) {
    document.getElementById('prev').onclick = goToPrev;
  }
  if (typeof window.goToNext === 'function' && document.getElementById('next')) {
    document.getElementById('next').onclick = goToNext;
  }
  if (typeof window.goToToday === 'function' && document.getElementById('today')) {
    document.getElementById('today').onclick = goToToday;
  }
  if (typeof window.changeView === 'function') {
    if (document.getElementById('viewDay')) {
      document.getElementById('viewDay').onclick = () => changeView('day');
    }
    if (document.getElementById('viewWeek')) {
      document.getElementById('viewWeek').onclick = () => changeView('week');
    }
    if (document.getElementById('viewMonth')) {
      document.getElementById('viewMonth').onclick = () => changeView('month');
    }
  }

  // === Bootstrap modal init ===
  const modalEl = document.getElementById('profileModal');
  if (modalEl && window.bootstrap && bootstrap.Modal) {
    profileModal = new bootstrap.Modal(modalEl);
  }

  // Sidebar avatar → open modal
  const avatar = document.getElementById('sidebarAvatar');
  if (avatar) {
    avatar.addEventListener('click', function (e) {
      e.preventDefault();
      openProfileModal();
    });
  }

  // Buttons
  const editNameBtn = document.getElementById('btnEditName');
  const editPhoneBtn = document.getElementById('btnEditPhone');
  const editPassBtn = document.getElementById('btnEditPassword');
  const saveBtn = document.getElementById('btnSaveProfile');

  if (editNameBtn)  editNameBtn.addEventListener('click', editNameField);
  if (editPhoneBtn) editPhoneBtn.addEventListener('click', editPhoneField);
  if (editPassBtn)  editPassBtn.addEventListener('click', editPasswordField);
  if (saveBtn)      saveBtn.addEventListener('click', saveAllProfileChanges);

  // Sidebar avatar зургийг эхэнд нь ачаалчихъя
  loadProfileAvatar();
});

// ===== Modal open =====
function openProfileModal() {
  loadProfileAvatar();
  loadProfileInfo();
  if (profileModal) profileModal.show();
}

// ===== Avatar load =====
function loadProfileAvatar() {
  fetch(profileApiUrl('get_avatar'))
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(text => {
      try {
        return JSON.parse(text);
      } catch(e) {
        console.error('JSON parse error:', text.substring(0, 200));
        throw e;
      }
    })
    .then(data => {
      const sidebarAvatar = document.getElementById('sidebarAvatar');
      if (data.avatar && sidebarAvatar) {
        sidebarAvatar.src = data.avatar;
        sidebarAvatar.style.width = '36px';
        sidebarAvatar.style.height = '36px';
        sidebarAvatar.style.objectFit = 'cover';
      }

      const modalImg = document.getElementById('profileAvatar');
      const modalTxt = document.getElementById('profileAvatarText');

      if (modalImg && modalTxt) {
        if (data.avatar) {
          modalImg.src = data.avatar;
          modalImg.style.display = 'block';
          modalTxt.style.display = 'none';
        } else {
          modalImg.style.display = 'none';
          modalTxt.textContent = '<?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>';
          modalTxt.style.display = 'block';
        }
      }
    })
    .catch(e => console.error('Avatar load error:', e));
}

// ===== Profile info load =====
function loadProfileInfo() {
  fetch(profileApiUrl('get_profile'))
    .then(r => r.json())
    .then(data => {
      const nameField = document.getElementById('profileName');
      const phoneField = document.getElementById('profilePhone');
      const passwordField = document.getElementById('profilePassword');

      if (nameField) {
        nameField.value = data.name || '';
        nameField.readOnly = true;
        nameField.style.borderColor = '#334155';
      }
      if (phoneField) {
        phoneField.value = data.phone || '';
        phoneField.readOnly = true;
        phoneField.style.borderColor = '#334155';
      }
      if (passwordField) {
        passwordField.value = '••••';
        passwordField.readOnly = true;
        passwordField.style.borderColor = '#334155';
      }
    })
    .catch(e => console.error('Profile load error:', e));
}

// ===== Avatar upload =====
function uploadAvatar(input) {
  if (!input.files || !input.files[0]) return;

  const formData = new FormData();
  formData.append('action', 'upload_avatar');
  formData.append('avatar', input.files[0]);

  fetch(profileApiUrl(), {
    method: 'POST',
    body: formData
  })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        alert('✅ Зураг солигдлоо');
        loadProfileAvatar();
      } else {
        alert('❌ Алдаа: ' + (res.msg || 'Алдаа гарлаа'));
      }
    })
    .catch(e => alert('❌ Хүсэлт алдаа: ' + e.message));
}

// ===== Edit toggles =====
function editNameField() {
  const f = document.getElementById('profileName');
  if (!f) return;
  if (f.readOnly) {
    f.readOnly = false;
    f.style.borderColor = '#3b82f6';
    f.focus(); f.select();
  } else {
    f.readOnly = true;
    f.style.borderColor = '#334155';
  }
}

function editPhoneField() {
  const f = document.getElementById('profilePhone');
  if (!f) return;
  if (f.readOnly) {
    f.readOnly = false;
    f.style.borderColor = '#3b82f6';
    f.focus(); f.select();
  } else {
    f.readOnly = true;
    f.style.borderColor = '#334155';
  }
}

function editPasswordField() {
  const f = document.getElementById('profilePassword');
  if (!f) return;
  if (f.readOnly) {
    f.readOnly = false;
    f.value = '';
    f.style.borderColor = '#3b82f6';
    f.focus(); f.select();
  } else {
    f.readOnly = true;
    f.value = '••••';
    f.style.borderColor = '#334155';
  }
}

// ===== Save changes =====
function saveAllProfileChanges() {
  const nameField = document.getElementById('profileName');
  const phoneField = document.getElementById('profilePhone');
  const passwordField = document.getElementById('profilePassword');

  const newName = (nameField?.value || '').trim();
  const newPhone = (phoneField?.value || '').trim();
  const newPassword = (passwordField?.value || '').trim();

  const promises = [];

  if (nameField && !nameField.readOnly && newName) {
    promises.push(
      fetch(profileApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'update_profile',
          name: newName
        })
      }).then(r => r.json())
    );
  }

  if (phoneField && !phoneField.readOnly && newPhone) {
    promises.push(
      fetch(profileApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'update_profile',
          phone: newPhone
        })
      }).then(r => r.json())
    );
  }

  if (passwordField && !passwordField.readOnly && newPassword.length >= 4) {
    promises.push(
      fetch(profileApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'update_profile',
          password: newPassword
        })
      }).then(r => r.json())
    );
  }

  if (promises.length === 0) {
    alert('❌ Хадгалахын өмнө өөрчлөлт оруулна уу');
    return;
  }

  Promise.all(promises)
    .then(results => {
      const allOk = results.every(r => r && r.ok);
      if (allOk) {
        alert('✅ Бүх өөрчлөлтүүд хадгалаглаа');
        loadProfileInfo();

        // Update sidebar displayed name immediately
        try {
          const newNameVal = (document.getElementById('profileName')?.value || '').trim();
          const headerName = document.querySelector('.sidebar-dark .header strong');
          if (headerName && newNameVal) headerName.textContent = newNameVal;
        } catch (e) { /* ignore */ }

        // If calendar script is present, reload doctors and bookings so calendar shows updated name
        try {
          if (typeof window.loadDoctors === 'function') {
            window.loadDoctors().then(() => { if (typeof window.loadBookings === 'function') window.loadBookings(); });
          } else if (window.parent && window.parent !== window && typeof window.parent.postMessage === 'function') {
            // If embedded in iframe, notify parent to refresh doctors
            window.parent.postMessage({ reloadDoctors: true }, '*');
          }
        } catch (e) { /* ignore */ }

      } else {
        const err = results.find(r => !r.ok);
        alert('❌ Хадгалахад алдаа: ' + (err?.msg || ''));
      }
    })
    .catch(e => alert('❌ Хүсэлт алдаа: ' + e.message));
}

function logout() {
  if (confirm('Та чухамхүү гарахын уу?')) {
    location.href = '<?= app_url('logout.php') ?>';
  }
}

// Global
window.uploadAvatar = uploadAvatar;
window.openProfileModal = openProfileModal;
window.loadProfileAvatar = loadProfileAvatar;
window.loadProfileInfo = loadProfileInfo;
window.editNameField = editNameField;
window.editPhoneField = editPhoneField;
window.editPasswordField = editPasswordField;
window.saveAllProfileChanges = saveAllProfileChanges;
</script>

<?php
// Debug output for $clinic and $logoPath
echo '<!-- DEBUG: clinic=' . htmlspecialchars($clinic) . ' | logoPath=' . htmlspecialchars($logoPath) . ' -->';
