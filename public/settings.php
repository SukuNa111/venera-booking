<?php
require_once __DIR__ . '/../config.php';
require_login();

$user = current_user();
$role = $user['role'] ?? 'guest';
$isAdmin = ($role === 'admin');
$isDoctor = ($role === 'doctor');

if (!$isAdmin && !$isDoctor) {
    require_role(['admin', 'doctor']);
}

/* === Settings JSON === */
$settingsPath = __DIR__ . '/../db/settings.json';
$defaultSettings = [
    'clinic_name'    => 'Venera-Dent',
    'theme_color'    => '#0f3b57',
    'secondary_color'=> '#8b5cf6',
    'default_view'   => 'week',
    'auto_fill'      => true,
    'send_reminders' => false,
    'patient_lookup_scope' => 'clinic_fallback'
];

$settings = $defaultSettings;
if (file_exists($settingsPath)) {
    $saved = json_decode(file_get_contents($settingsPath), true);
    if (is_array($saved)) $settings = array_merge($defaultSettings, $saved);
}

/* === Save Settings === */
$msg = '';
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $settings = [
        'clinic_name'    => trim($_POST['clinic_name']),
        'theme_color'    => trim($_POST['theme_color']),
        'secondary_color'=> trim($_POST['secondary_color'] ?? ($defaultSettings['secondary_color'] ?? '#8b5cf6')),
        'default_view'   => $_POST['default_view'],
        'auto_fill'      => isset($_POST['auto_fill']),
        'send_reminders' => isset($_POST['send_reminders']),
        'patient_lookup_scope' => $_POST['patient_lookup_scope']
    ];

    file_put_contents(
        $settingsPath,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $msg = "✅ Тохиргоо шинэчлэгдлээ.";
}

/* === Recent Activity (Admin only) === */
$recentActivity = [];
if ($isAdmin) {
    $clinic = $user['clinic_id'] ?? 'venera';
    
    try {
        $st = db()->prepare("
            SELECT b.name, b.phone, b.date, b.time, b.status, b.created_at, s.name as service_name
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.clinic = ?
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $st->execute([$clinic]);
        $recentActivity = $st->fetchAll();
    } catch (Exception $e) {
        // Log error but don't break the page
        error_log("Error fetching recent activity: " . $e->getMessage());
    }
}

/* === Doctor Hours (fallback) === */
$doctorHours = [];
if ($isDoctor) {
    foreach (range(0,6) as $d) {
        $doctorHours[$d] = [
            'day_of_week'  => $d,
            'start_time'   => '09:00',
            'end_time'     => '18:00',
            'is_available' => 1
        ];
    }

    // Load from DB
    try {
        $st = db()->prepare("SELECT day_of_week,start_time,end_time,is_available 
                             FROM working_hours WHERE doctor_id=? ORDER BY day_of_week");
        $st->execute([$user['id']]);

        foreach ($st->fetchAll() as $row) {
            $d = (int)$row['day_of_week'];
            if ($d>=0 && $d<=6) {
                $doctorHours[$d] = [
                    'day_of_week'  => $d,
                    'start_time'   => $row['start_time'] ?: '09:00',
                    'end_time'     => $row['end_time'] ?: '18:00',
                    'is_available' => (int)$row['is_available']
                ];
            }
        }
    } catch (Exception $e) {}
}

$weekDays = ['Даваа','Мягмар','Лхагва','Пүрэв','Баасан','Бямба','Ням'];

?>
<!doctype html>
<html lang="mn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>⚙️ Тохиргооны Хянах Самбар</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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

* { box-sizing: border-box; }

body {
  background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 50%, #f0fdfa 100%);
  background-attachment: fixed;
  min-height: 100vh;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  color: #1e293b;
  line-height: 1.6;
}

main { 
  margin-left: 250px; 
  padding: 2rem 2.5rem;
}

@media (max-width: 992px) {
  main {
    margin-left: 0;
    padding: 1rem;
  }
}

/* Page Header */
.page-header {
  background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
  border-radius: 20px;
  padding: 1.75rem 2rem;
  margin-bottom: 2rem;
  color: white;
  box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.page-title {
  font-weight: 800;
  font-size: 1.5rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-chip {
  background: rgba(255, 255, 255, 0.2);
  backdrop-filter: blur(10px);
  border-radius: 50px;
  padding: 0.6rem 1.25rem;
  font-size: 0.875rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
}

/* Glass Card */
.glass-card {
  background: white;
  border-radius: 20px;
  border: 1px solid var(--border);
  padding: 1.75rem;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
  transition: all 0.3s ease;
  margin-bottom: 1.5rem;
}

.glass-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1);
}

.card-header {
  display: flex;
  align-items: center;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid #f1f5f9;
}

.card-icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 1rem;
  font-size: 1.25rem;
  color: white;
}

.card-icon.purple { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
.card-icon.green { background: linear-gradient(135deg, #10b981, #34d399); }
.card-icon.blue { background: linear-gradient(135deg, #3b82f6, #60a5fa); }
.card-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }

.card-title {
  font-weight: 700;
  font-size: 1.15rem;
  margin: 0;
  color: #1e293b;
}

/* Stats Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 2rem;
}

@media (max-width: 1200px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
  .stats-grid { grid-template-columns: 1fr; }
}

.stat-card {
  background: white;
  border-radius: 16px;
  padding: 1.5rem;
  border: 1px solid var(--border);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
}

.stat-card.purple::before { background: linear-gradient(90deg, #6366f1, #8b5cf6); }
.stat-card.green::before { background: linear-gradient(90deg, #10b981, #34d399); }
.stat-card.blue::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.stat-card.orange::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
}

.stat-value {
  font-size: 2.5rem;
  font-weight: 800;
  line-height: 1;
  margin: 0.5rem 0;
}

.stat-card.purple .stat-value { color: #6366f1; }
.stat-card.green .stat-value { color: #10b981; }
.stat-card.blue .stat-value { color: #3b82f6; }
.stat-card.orange .stat-value { color: #f59e0b; }

.stat-label {
  font-size: 0.8rem;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
}

.stat-icon {
  position: absolute;
  top: 1.25rem;
  right: 1.25rem;
  font-size: 1.5rem;
  opacity: 0.3;
}

/* Form Controls */
.form-control, .form-select {
  background: #f8fafc;
  border: 2px solid var(--border);
  color: #1e293b;
  border-radius: 12px;
  padding: 0.85rem 1rem;
  transition: all 0.2s ease;
  font-weight: 500;
}

.form-control:focus, .form-select:focus {
  background: white;
  border-color: #6366f1;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
  color: #1e293b;
}

.form-control-color {
  height: 50px;
  padding: 6px;
  border-radius: 12px;
}

.form-label {
  font-weight: 600;
  margin-bottom: 0.6rem;
  color: #374151;
  font-size: 0.9rem;
}

/* Toggle Switch */
.toggle-switch {
  position: relative;
  display: inline-block;
  width: 56px;
  height: 28px;
}

.toggle-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.toggle-slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: #e2e8f0;
  transition: .3s;
  border-radius: 34px;
}

.toggle-slider:before {
  position: absolute;
  content: "";
  height: 20px;
  width: 20px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
  box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

input:checked + .toggle-slider {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
}

input:checked + .toggle-slider:before {
  transform: translateX(28px);
}

.toggle-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 0;
  border-bottom: 1px solid #f1f5f9;
}

.toggle-label:last-child {
  border-bottom: none;
}

.toggle-text {
  font-weight: 600;
  color: #374151;
}

/* Buttons */
.btn-save {
  background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
  border: none;
  font-weight: 700;
  padding: 0.9rem 2rem;
  border-radius: 12px;
  color: white;
  font-size: 0.95rem;
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-save:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
  color: white;
}

.btn-refresh {
  background: white;
  border: 2px solid var(--border);
  font-weight: 600;
  padding: 0.75rem 1.5rem;
  border-radius: 12px;
  color: #64748b;
  transition: all 0.2s;
}

.btn-refresh:hover {
  border-color: #6366f1;
  color: #6366f1;
  background: #f5f3ff;
}

/* Alert */
.alert-success {
  background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
  border: 2px solid #a7f3d0;
  color: #065f46;
  border-radius: 14px;
  padding: 1rem 1.5rem;
  font-weight: 600;
}

/* Activity List */
.activity-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.activity-item {
  display: flex;
  align-items: center;
  padding: 1rem 0;
  border-bottom: 1px solid #f1f5f9;
}

.activity-item:last-child {
  border-bottom: none;
}

.activity-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 1rem;
  background: #eff6ff;
  color: #3b82f6;
}

.activity-content {
  flex: 1;
}

.activity-title {
  font-weight: 600;
  margin-bottom: 0.25rem;
  color: #1e293b;
}

.activity-meta {
  font-size: 0.85rem;
  color: #64748b;
}

/* Settings Grid */
.settings-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1.5rem;
}

.settings-group {
  margin-bottom: 1.25rem;
}

.settings-group-title {
  font-weight: 700;
  font-size: 0.9rem;
  margin-bottom: 1rem;
  padding-bottom: 0.5rem;
  border-bottom: 2px solid #f1f5f9;
  color: #374151;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.color-preview {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: inline-block;
  margin-right: 12px;
  vertical-align: middle;
  border: 3px solid white;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 3rem 2rem;
  color: #94a3b8;
}

.empty-state i {
  font-size: 3.5rem;
  margin-bottom: 1rem;
  opacity: 0.4;
}

.empty-state p {
  font-size: 0.95rem;
}

/* System Info */
.system-info {
  background: #f8fafc;
  border-radius: 12px;
  padding: 1rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  padding: 0.6rem 0;
  border-bottom: 1px solid #e2e8f0;
}

.info-row:last-child {
  border-bottom: none;
}

.info-label {
  color: #64748b;
  font-size: 0.85rem;
}

.info-value {
  font-weight: 600;
  color: #1e293b;
  font-size: 0.85rem;
}

/* Progress Bar */
.progress-bar-custom {
  height: 8px;
  border-radius: 10px;
  background: #e2e8f0;
  overflow: hidden;
  margin-top: 0.5rem;
}

.progress-fill {
  height: 100%;
  border-radius: 10px;
  transition: width 0.3s;
}

.progress-fill.purple { background: linear-gradient(90deg, #6366f1, #8b5cf6); }
.progress-fill.green { background: linear-gradient(90deg, #10b981, #34d399); }
</style>
</head>

<body>

<?php include __DIR__.'/../partials/sidebar.php'; ?>

<main>

<div class="page-header">
  <h1 class="page-title">
    <i class="fas fa-cog"></i> Тохиргооны Хянах Самбар
  </h1>
  <div class="user-chip">
    <i class="fas fa-user-shield"></i>
    <?= htmlspecialchars($user['name']) ?> — <?= htmlspecialchars($role) ?>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success d-flex align-items-center" role="alert">
  <i class="fas fa-check-circle me-2"></i>
  <?= $msg ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>

<div class="stats-grid">
  <div class="stat-card purple">
    <div class="stat-label">Сүүлийн 7 хоног</div>
    <div class="stat-value">24</div>
    <div class="stat-icon">
      <i class="fas fa-calendar-check" style="color: #6366f1;"></i>
    </div>
  </div>
  
  <div class="stat-card green">
    <div class="stat-label">Идэвхтэй эмч нар</div>
    <div class="stat-value">5</div>
    <div class="stat-icon">
      <i class="fas fa-user-md" style="color: #10b981;"></i>
    </div>
  </div>
  
  <div class="stat-card blue">
    <div class="stat-label">Нийт үйлчилгээ</div>
    <div class="stat-value">12</div>
    <div class="stat-icon">
      <i class="fas fa-procedures" style="color: #3b82f6;"></i>
    </div>
  </div>
  
  <div class="stat-card orange">
    <div class="stat-label">Дундаж үнэлгээ</div>
    <div class="stat-value">4.8</div>
    <div class="stat-icon">
      <i class="fas fa-star" style="color: #f59e0b;"></i>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="glass-card">
      <div class="card-header">
        <div class="card-icon purple">
          <i class="fas fa-cogs"></i>
        </div>
        <h3 class="card-title">Системийн Тохиргоо</h3>
      </div>

      <form method="post" class="settings-grid">
        <div class="settings-group">
          <div class="settings-group-title">Ерөнхий тохиргоо</div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-clinic-medical me-2" style="color: #6366f1;"></i>Клиникийн нэр</label>
            <input type="text" name="clinic_name" class="form-control" required
                   value="<?= htmlspecialchars($settings['clinic_name']) ?>">
          </div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-palette me-2" style="color: #8b5cf6;"></i>Үндсэн өнгө</label>
            <div class="d-flex align-items-center gap-3">
              <span class="color-preview" style="background: <?= htmlspecialchars($settings['theme_color']) ?>"></span>
              <input type="color" name="theme_color" class="form-control form-control-color"
                     value="<?= htmlspecialchars($settings['theme_color']) ?>">
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-paint-brush me-2" style="color: #06b6d4;"></i>Нэмэлт өнгө</label>
            <div class="d-flex align-items-center gap-3">
              <span class="color-preview" style="background: <?= htmlspecialchars($settings['secondary_color'] ?? ($defaultSettings['secondary_color'] ?? '#8b5cf6')) ?>"></span>
              <input type="color" name="secondary_color" class="form-control form-control-color"
                     value="<?= htmlspecialchars($settings['secondary_color'] ?? ($defaultSettings['secondary_color'] ?? '#8b5cf6')) ?>">
            </div>
          </div>
        </div>
        
        <div class="settings-group">
          <div class="settings-group-title">Цагийн тохиргоо</div>
          
          <div class="mb-3">
            <label class="form-label">Анхны харагдац</label>
            <select name="default_view" class="form-select">
              <option value="day"   <?= $settings['default_view']==='day'?'selected':''?>>Өдөр</option>
              <option value="week"  <?= $settings['default_view']==='week'?'selected':''?>>7 хоног</option>
              <option value="month" <?= $settings['default_view']==='month'?'selected':''?>>Сар</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-clock me-2" style="color: #10b981;"></i>Цаг бүртгэх горим</label>
            <select name="time_slot_duration" class="form-select">
              <option value="15">15 минут</option>
              <option value="30" selected>30 минут</option>
              <option value="60">60 минут</option>
            </select>
          </div>
        </div>
        
        <div class="settings-group">
          <div class="settings-group-title">Өгөгдлийн тохиргоо</div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-search me-2" style="color: #f59e0b;"></i>Өвчтөн хайх хүрээ</label>
            <select name="patient_lookup_scope" class="form-select">
              <option value="clinic_only"    <?= $settings['patient_lookup_scope']==='clinic_only'?'selected':''?>>Зөвхөн энэ клиник</option>
              <option value="clinic_fallback"<?= $settings['patient_lookup_scope']==='clinic_fallback'?'selected':''?>>Энэ клиник → Глобал fallback</option>
              <option value="global"         <?= $settings['patient_lookup_scope']==='global'?'selected':''?>>Бүх клиник</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label class="form-label"><i class="fas fa-database me-2" style="color: #ef4444;"></i>Өгөгдлийн нөөцлөлт</label>
            <select name="backup_frequency" class="form-select">
              <option value="daily">Өдөр бүр</option>
              <option value="weekly" selected>Долоо хоног бүр</option>
              <option value="monthly">Сар бүр</option>
            </select>
          </div>
        </div>
        
        <div class="settings-group">
          <div class="settings-group-title">Автомат үйлдлүүд</div>
          
          <div class="toggle-label">
            <span class="toggle-text"><i class="fas fa-magic me-2" style="color: #6366f1;"></i>Автоматаар бөглөх</span>
            <label class="toggle-switch">
              <input type="checkbox" name="auto_fill" <?= $settings['auto_fill']?'checked':'' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
          
          <div class="toggle-label">
            <span class="toggle-text"><i class="fas fa-bell me-2" style="color: #f59e0b;"></i>Сануулга илгээх</span>
            <label class="toggle-switch">
              <input type="checkbox" name="send_reminders" <?= $settings['send_reminders']?'checked':'' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
          
          <div class="toggle-label">
            <span class="toggle-text"><i class="fas fa-cloud-upload-alt me-2" style="color: #10b981;"></i>Автомат нөөцлөлт</span>
            <label class="toggle-switch">
              <input type="checkbox" checked>
              <span class="toggle-slider"></span>
            </label>
          </div>
          
          <div class="toggle-label">
            <span class="toggle-text"><i class="fas fa-globe me-2" style="color: #06b6d4;"></i>Онлайн захиалга</span>
            <label class="toggle-switch">
              <input type="checkbox" checked>
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>
        
        <div class="col-12 mt-4">
          <button class="btn btn-save">
            <i class="fas fa-save"></i> Тохиргоо хадгалах
          </button>
        </div>
      </form>
    </div>
  </div>
  
  <div class="col-lg-4">
    <div class="glass-card mb-4">
      <div class="card-header">
        <div class="card-icon green">
          <i class="fas fa-history"></i>
        </div>
        <h3 class="card-title">Сүүлийн Үйл Ажиллагаа</h3>
      </div>
      
      <?php if (!empty($recentActivity)): ?>
        <ul class="activity-list">
          <?php foreach ($recentActivity as $activity): ?>
            <li class="activity-item">
              <div class="activity-icon">
                <i class="fas fa-calendar-plus"></i>
              </div>
              <div class="activity-content">
                <div class="activity-title"><?= htmlspecialchars($activity['name']) ?></div>
                <div class="activity-meta">
                  <?= htmlspecialchars($activity['service_name'] ?? 'Үйлчилгээ') ?> · 
                  <?= htmlspecialchars($activity['date']) ?> <?= htmlspecialchars($activity['time']) ?>
                </div>
              </div>
              <div class="activity-time">
                <?php
                  $statusClass = 'badge-info';
                  if ($activity['status'] === 'paid') $statusClass = 'badge-success';
                  if ($activity['status'] === 'cancelled') $statusClass = 'badge-warning';
                ?>
                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($activity['status']) ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-inbox"></i>
          <p>Сүүлийн үйл ажиллагааны тэмдэглэл байхгүй байна</p>
        </div>
      <?php endif; ?>
    </div>
    
    <div class="glass-card">
      <div class="card-header">
        <div class="card-icon blue">
          <i class="fas fa-server"></i>
        </div>
        <h3 class="card-title">Системийн Мэдээлэл</h3>
      </div>
      
      <div class="system-info">
        <div class="mb-4">
          <div class="d-flex justify-content-between mb-2">
            <span style="font-weight: 600; color: #374151;">Системийн ачаалал</span>
            <span style="font-weight: 700; color: #6366f1;">42%</span>
          </div>
          <div class="progress-bar-custom">
            <div class="progress-fill purple" style="width: 42%;"></div>
          </div>
        </div>
        
        <div class="mb-4">
          <div class="d-flex justify-content-between mb-2">
            <span style="font-weight: 600; color: #374151;">Хадгалах зай</span>
            <span style="font-weight: 700; color: #10b981;">1.2GB / 5GB</span>
          </div>
          <div class="progress-bar-custom">
            <div class="progress-fill green" style="width: 24%;"></div>
          </div>
        </div>
        
        <div class="info-row">
          <span class="info-label"><i class="fas fa-users me-2"></i>Хэрэглэгчид</span>
          <span class="info-value">24</span>
        </div>
        
        <div class="info-row">
          <span class="info-label"><i class="fas fa-code-branch me-2"></i>Системийн хувилбар</span>
          <span class="info-value">v2.4.1</span>
        </div>
      </div>
      
      <div class="mt-4">
        <button class="btn btn-refresh w-100">
          <i class="fas fa-sync-alt me-2"></i> Шинэчлэх
        </button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Add some interactive elements
document.addEventListener('DOMContentLoaded', function() {
  // Color preview update
  const colorInputs = document.querySelectorAll('input[type="color"]');
  colorInputs.forEach(input => {
    input.addEventListener('input', function() {
      const preview = this.parentElement.querySelector('.color-preview');
      if (preview) {
        preview.style.backgroundColor = this.value;
      }
    });
  });
  
  // Add animation to stat cards
  document.querySelectorAll('.stat-card').forEach((card, index) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    
    setTimeout(() => {
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, 100 * (index + 1));
  });
});
</script>
</body>
</html>