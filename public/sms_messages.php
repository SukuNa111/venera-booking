<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$u = current_user();

// Template rendering functions
function render_template($tpl, array $vars) {
  return preg_replace_callback('/\{(\w+)\}/', function($m) use ($vars) {
    $key = $m[1];
    return array_key_exists($key, $vars) ? $vars[$key] : $m[0];
  }, (string)$tpl);
}

function to_latin($text) {
  static $map = [
    'А'=>'A','а'=>'a','Б'=>'B','б'=>'b','В'=>'V','в'=>'v','Г'=>'G','г'=>'g','Д'=>'D','д'=>'d','Е'=>'E','е'=>'e','Ё'=>'Yo','ё'=>'yo',
    'Ж'=>'Zh','ж'=>'zh','З'=>'Z','з'=>'z','И'=>'I','и'=>'i','Й'=>'Y','й'=>'y','К'=>'K','к'=>'k','Л'=>'L','л'=>'l','М'=>'M','м'=>'m',
    'Н'=>'N','н'=>'n','О'=>'O','о'=>'o','Ө'=>'O','ө'=>'o','П'=>'P','п'=>'p','Р'=>'R','р'=>'r','С'=>'S','с'=>'s','Т'=>'T','т'=>'t',
    'У'=>'U','у'=>'u','Ү'=>'U','ү'=>'u','Ф'=>'F','ф'=>'f','Х'=>'Kh','х'=>'kh','Ц'=>'Ts','ц'=>'ts','Ч'=>'Ch','ч'=>'ch','Ш'=>'Sh','ш'=>'sh',
    'Щ'=>'Sh','щ'=>'sh','Ъ'=>'','ъ'=>'','Ы'=>'Y','ы'=>'y','Ь'=>'','ь'=>'','Э'=>'E','э'=>'e','Ю'=>'Yu','ю'=>'yu','Я'=>'Ya','я'=>'ya'
  ];
  $out = strtr((string)$text, $map);
  return preg_replace('/[^A-Za-z0-9@#\/:.,\-\s]/', '', $out);
}

// Получить существующие шаблоны сообщений
$templates = [
  'confirmation' => 'Баталгаажуулалтын мессеж',
  'reminder' => 'Сануулга мессеж',
  'aftercare' => 'Aftercare мессеж'
];

$messages = [];
try {
  // Use unified/global templates regardless of clinic
  $st = db()->prepare("SELECT * FROM sms_templates WHERE clinic = 'global' ORDER BY type");
  $st->execute();
  $messages = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $messages = [];
}

// Default messages if table doesn't exist
$defaultMessages = [
  'confirmation' => 'Сайн байна уу {patient_name}! Таны захиалга {clinic_name}-д {date} {start_time}-д баталгаажлаа. Хүлээсний хандив {phone}.',
  'reminder' => 'Сайн байна уу {patient_name}! Маргааш {date} {start_time}-д таны үзлэг {clinic_name}-д байна. Дуу хэвлэл хүргүүлэх: {phone}.'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  
  if ($action === 'save_departments') {
    try {
      // Build array with support for 2 phone numbers per department
      $dept_phones = [];
      $departments = ['dental', 'traditional', 'drip', 'nonsurgical', 'surgical'];
      
      foreach ($departments as $dept) {
        $phone1 = trim($_POST["dept_{$dept}_1"] ?? '');
        $phone2 = trim($_POST["dept_{$dept}_2"] ?? '');
        
        if (!empty($phone1)) {
          if (!empty($phone2)) {
            // Both phones provided - store as array
            $dept_phones[$dept] = [$phone1, $phone2];
          } else {
            // Only first phone - store as array with single element
            $dept_phones[$dept] = [$phone1];
          }
        }
      }

      $phonesJson = json_encode($dept_phones, JSON_UNESCAPED_UNICODE);

      // Delete old entry
      db()->prepare("DELETE FROM app_settings WHERE clinic = ? AND key = ?")->execute(['venera', 'department_phones']);

      // Insert new entry
      $st = db()->prepare("INSERT INTO app_settings (clinic, key, value) VALUES (?, ?, ?)");
      $st->execute(['venera', 'department_phones', $phonesJson]);

      $success = "✅ Тасгуудын утасны дугаарууд амжилттай хадгалагдлаа!";
    } catch (Exception $e) {
      $error = "Алдаа: " . $e->getMessage();
    }
  }

  if ($action === 'save_luxor_departments') {
    try {
      $luxor_dept_phones = [];
      $luxor_depts = ['examination', 'massage', 'nonsurgical'];
      
      foreach ($luxor_depts as $dept) {
        $p1 = trim($_POST["luxor_{$dept}_1"] ?? '');
        $p2 = trim($_POST["luxor_{$dept}_2"] ?? '');
        if (!empty($p1)) {
          $p1 = preg_replace('/\D/', '', $p1);
          $p2 = preg_replace('/\D/', '', $p2);
          $luxor_dept_phones[$dept] = !empty($p2) ? [$p1, $p2] : [$p1];
        }
      }

      $phonesJson = json_encode($luxor_dept_phones, JSON_UNESCAPED_UNICODE);
      db()->prepare("DELETE FROM app_settings WHERE clinic = ? AND key = ?")->execute(['luxor', 'department_phones']);
      $st = db()->prepare("INSERT INTO app_settings (clinic, key, value) VALUES (?, ?, ?)");
      $st->execute(['luxor', 'department_phones', $phonesJson]);

      $success = "✅ Golden Luxor тасгуудын утасны дугаарууд амжилттай хадгалагдлаа!";
    } catch (Exception $e) {
      $error = "Алдаа: " . $e->getMessage();
    }
  }

  if ($action === 'save_khatan_phone') {
    try {
      $p1 = trim($_POST['khatan_phone_1'] ?? '');
      $p2 = trim($_POST['khatan_phone_2'] ?? '');

      if (empty($p1)) {
        throw new Exception("Утасны дугаарыг оруулна уу!");
      }

      $khatan_phones = !empty($p2) ? [$p1, $p2] : [$p1];
      $phonesJson = json_encode($khatan_phones, JSON_UNESCAPED_UNICODE);

      db()->prepare("DELETE FROM app_settings WHERE clinic = ? AND key = ?")->execute(['khatan', 'clinic_phone']);
      $st = db()->prepare("INSERT INTO app_settings (clinic, key, value) VALUES (?, ?, ?)");
      $st->execute(['khatan', 'clinic_phone', $phonesJson]);

      $success = "✅ Гоо Хатан эмнэлгийн утасны дугаар амжилттай хадгалагдлаа!";
    } catch (Exception $e) {
      $error = "Алдаа: " . $e->getMessage();
    }
  }
  
  $type = $_POST['type'] ?? '';
  $message = $_POST['message'] ?? '';
  $clinic_name = $_POST['clinic_name'] ?? '';
  $clinic_phone = $_POST['clinic_phone'] ?? '';
  $is_latin = isset($_POST['is_latin']) ? 1 : 0;
  // Always save to unified/global templates
  $clinic = 'global';

  if ($action === 'save') {
    try {
      // Try update first
      $st = db()->prepare("UPDATE sms_templates SET message = ?, clinic_name = ?, clinic_phone = ?, is_latin = ? WHERE type = ? AND clinic = ?");
      $st->execute([$message, $clinic_name, $clinic_phone, $is_latin, $type, $clinic]);
      
      if ($st->rowCount() === 0) {
        // If no rows updated, insert
        $st = db()->prepare("INSERT INTO sms_templates (type, message, clinic, clinic_name, clinic_phone, is_latin) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$type, $message, $clinic, $clinic_name, $clinic_phone, $is_latin]);
      }
      
      $success = "Мессеж амжилттай хадгалагдлаа!";
    } catch (Exception $e) {
      $error = "Алдаа: " . $e->getMessage();
    }
  } elseif ($action === 'test') {
    // Send test SMS
    try {
      $test_phone = $_POST['test_phone'] ?? '';
      if (empty($test_phone)) {
        $error = "Тестийн утасны дугаарыг оруулна уу!";
      } else {
        $message_text = $_POST['message'] ?? '';
        $clinic_name = $_POST['clinic_name'] ?? 'Венера';
        $clinic_phone = $_POST['clinic_phone'] ?? '';
        
        // Prepare test variables
        $test_vars = [
          'patient_name' => 'Тест өвчтөнө',
          'clinic_name' => $clinic_name,
          'phone' => $clinic_phone,
          'date' => date('m-d', strtotime('+1 day')),
          'start_time' => '14:00',
          'end_time' => '15:00',
          'old_date' => date('m-d'),
          'old_time' => '10:00',
          'new_date' => date('m-d', strtotime('+2 days')),
          'new_time' => '15:00'
        ];
        
        $test_message = render_template($message_text, $test_vars);
        
        // Convert to Latin if needed
        if ($is_latin) {
          $test_message = to_latin($test_message);
        }
        
        // Send test SMS
        $result = sendSMS($test_phone, $test_message);
        
        if ($result['ok']) {
          $success = "✅ Тестийн мессеж амжилттай явсан! Статус: " . $result['status'];
        } else {
          $error = "❌ Тестийн мессеж явахад алдаа: " . ($result['error'] ?? 'Үл мэдэгдэх');
        }
      }
    } catch (Exception $e) {
      $error = "Алдаа: " . $e->getMessage();
    }
  }
}

// Get current messages for display
$currentMessages = [];
foreach ($messages as $msg) {
  $currentMessages[$msg['type']] = [
    'message' => $msg['message'] ?? '',
    'clinic_name' => $msg['clinic_name'] ?? '',
    'clinic_phone' => $msg['clinic_phone'] ?? '',
    'is_latin' => (int)($msg['is_latin'] ?? 1)
  ];
}
foreach ($templates as $key => $label) {
  if (!isset($currentMessages[$key])) {
    $currentMessages[$key] = [
      'message' => $defaultMessages[$key] ?? '',
      'clinic_name' => '',
      'clinic_phone' => '',
      'is_latin' => 1
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="mn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>💬 Мессежийн тохиргоо - Venera-Dent</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #667eea;
      --primary-dark: #5560d6;
      --secondary: #8b5cf6;
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --light: #f8fafc;
      --dark: #1e293b;
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

    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: 
        radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.08) 0%, transparent 50%);
      pointer-events: none;
      z-index: 0;
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
      box-shadow: 0 20px 60px rgba(102, 126, 234, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.2) inset;
      border: 1px solid rgba(255, 255, 255, 0.2);
      position: relative;
      overflow: hidden;
    }

    .page-header::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                  radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
      pointer-events: none;
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

    .card-container::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle at 20% 50%, rgba(236, 72, 153, 0.06) 0%, transparent 50%),
                  radial-gradient(circle at 80% 80%, rgba(59, 130, 246, 0.05) 0%, transparent 50%);
      pointer-events: none;
    }

    .card-container > * {
      position: relative;
      z-index: 1;
    }

    .card-container h3 {
      font-weight: 700;
      font-size: 1.5rem;
      color: #1e293b;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .card-container h3 i {
      background: linear-gradient(135deg, #667eea 0%, #8b5cf6 25%, #ec4899 50%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-size: 1.5rem;
    }

    .message-form {
      margin-bottom: 2rem;
      padding: 1.5rem;
      background: rgba(102, 126, 234, 0.05);
      border-radius: 16px;
      border: 1px solid rgba(102, 126, 234, 0.1);
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-group label {
      font-weight: 700;
      color: #334155;
      margin-bottom: 0.75rem;
      display: block;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group textarea {
      width: 100%;
      padding: 1rem;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      font-family: 'Courier New', monospace;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      resize: vertical;
      min-height: 120px;
    }

    .form-group textarea:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
      outline: none;
    }

    .form-group textarea:hover {
      border-color: #cbd5e1;
    }

    .placeholders {
      background: #f1f5f9;
      border-radius: 12px;
      padding: 1rem;
      margin-top: 0.75rem;
      font-size: 0.85rem;
      color: #64748b;
    }

    .placeholders strong {
      color: #334155;
    }

    .placeholders .tag {
      display: inline-block;
      background: white;
      border: 1px solid #e2e8f0;
      padding: 0.25rem 0.75rem;
      border-radius: 6px;
      margin: 0.25rem;
      font-family: monospace;
      color: #667eea;
    }

    .btn-save {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
      border: none;
      padding: 0.95rem 2rem;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .btn-save:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      color: white;
    }

    .btn-test {
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      color: white;
      border: none;
      padding: 0.95rem 2rem;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .btn-test:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: white;
    }

    .alert {
      border-radius: 12px;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
      border: none;
      font-weight: 500;
    }

    .alert-success {
      background: #ecfdf5;
      color: #047857;
    }

    .alert-danger {
      background: #fef2f2;
      color: #b91c1c;
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
      <h1><i class="fas fa-comment-dots"></i> Мессежийн тохиргоо</h1>
      <p>SMS мессежийн шаблон болон текстийн хэлбэрүүлэлт</p>
    </div>

    <?php if (isset($success)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
      <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- Message Templates -->
    <div class="card-container">
      <h3><i class="fas fa-message"></i> SMS Шаблонууд</h3>

      <?php foreach ($templates as $key => $label): ?>
        <div class="message-form">
          <form method="post">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="type" value="<?= htmlspecialchars($key) ?>">

            <div class="form-row" style="margin-bottom: 1.5rem;">
              <div class="form-group">
                <label>Эмнэлгийн нэр (Мессежинд харагдах)</label>
                <input type="text" name="clinic_name" class="form-control" 
                       value="<?= htmlspecialchars($currentMessages[$key]['clinic_name'] ?? '') ?>"
                       placeholder="Венера V.I.P Clinic">
                <input type="hidden" name="clinic_phone" value="">
              </div>
            </div>

            <div class="form-group">
              <label><?= htmlspecialchars($label) ?></label>
              <textarea name="message" required><?= htmlspecialchars($currentMessages[$key]['message'] ?? '') ?></textarea>
              <div class="placeholders">
                <strong>💡 Ашиглаж болох шошго:</strong><br>
                <span class="tag">{patient_name}</span>
                <span class="tag">{clinic_name}</span>
                <span class="tag">{date}</span>
                <span class="tag">{start_time}</span>
                <span class="tag">{phone}</span>
                <span class="tag">{old_date}</span>
                <span class="tag">{old_time}</span>
                <span class="tag">{new_date}</span>
                <span class="tag">{new_time}</span>
              </div>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
              <label style="margin: 0; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_latin" <?= ($currentMessages[$key]['is_latin'] ?? 1) ? 'checked' : '' ?>>
                <strong>Латинаар явуулах</strong>
              </label>
              <small style="color: #64748b;">(Кириллиц текстийг Latin болгон хөрвүүлэх)</small>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center;">
              <button type="submit" class="btn-save">
                <i class="fas fa-save"></i> Хадгалах
              </button>
              <button type="button" class="btn-test" onclick="showTestModal('<?= htmlspecialchars($key) ?>', '<?= htmlspecialchars($label) ?>')">
                <i class="fas fa-envelope"></i> Тест явуулах
              </button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Department Phone Routing -->
    <div class="card-container">
      <h3><i class="fas fa-stethoscope"></i> Тасгуудын утасны дугаар (Венера эмнэлэг)</h3>
      <p style="color: #64748b; margin-bottom: 1.5rem;">
        Өвчтөн аль тасгийн үйлчилгээ авахаа сонгосны дагуу SMS мессеж тус тусын утасны дугаартай явуулагдана
      </p>

      <?php
        // Load department phones from app_settings
        $dept_phones = [];
        try {
          $st = db()->prepare("SELECT value FROM app_settings WHERE clinic = ? AND key = ?");
          $st->execute(['venera', 'department_phones']);
          $result = $st->fetch(PDO::FETCH_ASSOC);
          if ($result) {
            $dept_phones = json_decode($result['value'], true) ?: [];
          }
        } catch (Exception $e) {
          $dept_phones = [];
        }

        // Set defaults (as arrays)
        $defaults = [
          'dental' => ['70115090'],
          'traditional' => ['70115090'],
          'drip' => ['70115090'],
          'nonsurgical' => ['70115090'],
          'surgical' => ['70115090']
        ];

        $dept_phones = array_merge($defaults, $dept_phones);
        
        // Helper to get phone values
        $getPhone = function($dept, $index) use ($dept_phones) {
          if (!isset($dept_phones[$dept])) return '';
          $phones = is_array($dept_phones[$dept]) ? $dept_phones[$dept] : [$dept_phones[$dept]];
          return $phones[$index] ?? '';
        };
      ?>

      <form method="post" id="dept-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <input type="hidden" name="action" value="save_departments">
        
        <div class="form-group">
          <label><i class="fas fa-tooth"></i> <strong>Шүдний тасаг</strong></label>
          <input type="text" name="dept_dental_1" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('dental', 0)) ?>"
                 placeholder="70115090" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="dept_dental_2" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('dental', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Шүдний үзлэг, эчэм үлгэр эмчилгээ</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-leaf"></i> <strong>Уламжлалт анагаа</strong></label>
          <input type="text" name="dept_traditional_1" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('traditional', 0)) ?>"
                 placeholder="70115090" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="dept_traditional_2" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('traditional', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Хөнгөний эмчилгээ, уламжлалт массаж</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-droplet"></i> <strong>Дусал / Сувилахуй</strong></label>
          <input type="text" name="dept_drip_1" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('drip', 0)) ?>"
                 placeholder="70115090" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="dept_drip_2" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('drip', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">IV дусал, инъекц</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-spa"></i> <strong>Мэсийн бус гоо сайхан</strong></label>
          <input type="text" name="dept_nonsurgical_1" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('nonsurgical', 0)) ?>"
                 placeholder="70115090" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="dept_nonsurgical_2" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('nonsurgical', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Ботокс, филлер, ласер</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-hospital"></i> <strong>Мэс засал</strong></label>
          <input type="text" name="dept_surgical_1" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('surgical', 0)) ?>"
                 placeholder="70115090" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="dept_surgical_2" class="form-control" 
                 value="<?= htmlspecialchars($getPhone('surgical', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Хирургийн үйлчилгээ, үстээс салгалт</small>
        </div>

        <div style="grid-column: 1 / -1;">
          <button type="submit" class="btn-save">
            <i class="fas fa-save"></i> Бүх тасгийн утасны дугаарыг хадгалах
          </button>
        </div>
      </form>
    </div>

    <!-- Golden Luxor Department Phone Routing -->
    <div class="card-container">
      <h3><i class="fas fa-crown"></i> Тасгуудын утасны дугаар (Golden Luxor эмнэлэг)</h3>
      <p style="color: #64748b; margin-bottom: 1.5rem;">
        Golden Luxor эмнэлгийн тасгуудын утасны дугаарыг тохируулна
      </p>

      <?php
        // Load Golden Luxor department phones from app_settings
        $luxor_dept_phones = [];
        try {
          $st = db()->prepare("SELECT value FROM app_settings WHERE clinic = ? AND key = ?");
          $st->execute(['luxor', 'department_phones']);
          $result = $st->fetch(PDO::FETCH_ASSOC);
          if ($result) {
            $luxor_dept_phones = json_decode($result['value'], true) ?: [];
          }
        } catch (Exception $e) {
          $luxor_dept_phones = [];
        }

        // Set defaults for Golden Luxor
        $luxor_defaults = [
          'examination' => '80806780',
          'massage' => '80332070',
          'nonsurgical' => '70115092'
        ];

        $luxor_dept_phones = array_merge($luxor_defaults, $luxor_dept_phones);
      ?>

      <form method="post" id="luxor-dept-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <input type="hidden" name="action" value="save_luxor_departments">
        
        <?php
          $getLuxPhone = function($dept, $index) use ($luxor_dept_phones) {
            if (!isset($luxor_dept_phones[$dept])) return '';
            $phones = is_array($luxor_dept_phones[$dept]) ? $luxor_dept_phones[$dept] : [$luxor_dept_phones[$dept]];
            return $phones[$index] ?? '';
          };
        ?>

        <div class="form-group">
          <label><i class="fas fa-stethoscope"></i> <strong>Үзлэг</strong></label>
          <input type="text" name="luxor_examination_1" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('examination', 0)) ?>"
                 placeholder="80806780" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="luxor_examination_2" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('examination', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Үзлэг сувилахуй</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-hand-holding-heart"></i> <strong>Массаж</strong></label>
          <input type="text" name="luxor_massage_1" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('massage', 0)) ?>"
                 placeholder="80332070" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="luxor_massage_2" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('massage', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Уламжлалт массаж</small>
        </div>

        <div class="form-group">
          <label><i class="fas fa-spa"></i> <strong>Мэсийн бус</strong></label>
          <input type="text" name="luxor_nonsurgical_1" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('nonsurgical', 0)) ?>"
                 placeholder="70115092" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="luxor_nonsurgical_2" class="form-control" 
                 value="<?= htmlspecialchars($getLuxPhone('nonsurgical', 1)) ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">Мэсийн бус гоо сайхан</small>
        </div>

        <div style="grid-column: 1 / -1;">
          <button type="submit" class="btn-save">
            <i class="fas fa-save"></i> Golden Luxor тасгийн утасны дугаарыг хадгалах
          </button>
        </div>
      </form>
    </div>

    <!-- Khatan Clinic Phone Configuration -->
    <div class="card-container">
      <h3><i class="fas fa-hospital"></i> Эмнэлгийн утас (Гоо Хатан)</h3>
      <p style="color: #64748b; margin-bottom: 1.5rem;">
        Гоо Хатан эмнэлгийн SMS мессежийн дагалдах утасны дугаарыг оруулна
      </p>

      <?php
        // Load Khatan clinic phone from app_settings
        $khatan_phone = '';
        try {
          $st = db()->prepare("SELECT value FROM app_settings WHERE clinic = ? AND key = ?");
          $st->execute(['khatan', 'clinic_phone']);
          $result = $st->fetch(PDO::FETCH_ASSOC);
          if ($result) {
            $khatan_phone = $result['value'];
          }
        } catch (Exception $e) {
          $khatan_phone = '';
        }
      ?>

      <form method="post" style="max-width: 400px; margin-bottom: 2rem;">
        <input type="hidden" name="action" value="save_khatan_phone">
        
        <?php 
          $khatanPhonesList = is_array(json_decode($khatan_phone, true)) ? json_decode($khatan_phone, true) : [$khatan_phone];
        ?>

        <div class="form-group">
          <label><i class="fas fa-phone"></i> <strong>Гоо Хатан эмнэлгийн утас</strong></label>
          <input type="text" name="khatan_phone_1" class="form-control" 
                 value="<?= htmlspecialchars($khatanPhonesList[0] ?? '') ?>"
                 placeholder="70115094" maxlength="8" pattern="[0-9]{8}" style="margin-bottom: 0.5rem;">
          <input type="text" name="khatan_phone_2" class="form-control" 
                 value="<?= htmlspecialchars($khatanPhonesList[1] ?? '') ?>"
                 placeholder="2-р утас (заавал биш)" maxlength="8" pattern="[0-9]{8}">
          <small style="color: #64748b;">8 орны утасны дугаар</small>
        </div>

        <button type="submit" class="btn-save">
          <i class="fas fa-save"></i> Утасны дугаарыг хадгалах
        </button>
      </form>
    </div>

    <!-- Help Section -->
    <div class="card-container">
      <h3><i class="fas fa-question-circle"></i> Тусламж</h3>
      <p style="color: #64748b; line-height: 1.8;">
        <strong>Шошгыг хэрхэн ашиглах:</strong><br>
        Мессежийн текст дээр доорх шошгуудийг ашигласнаар автоматаар түүнээр солигдох болно:
      </p>
      <div style="background: #f1f5f9; padding: 1.5rem; border-radius: 12px;">
        <ul style="margin: 0; padding-left: 1.5rem;">
          <li><code>{patient_name}</code> - Өвчтөний нэр</li>
          <li><code>{clinic_name}</code> - Эмнэлгийн нэр</li>
          <li><code>{date}</code> - Үзлэгийн өдөр</li>
          <li><code>{start_time}</code> - Үзлэгийн эхлэх цаг</li>
          <li><code>{end_time}</code> - Үзлэгийн дуусах цаг</li>
          <li><code>{phone}</code> - Эмнэлгийн утас</li>
        </ul>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function saveDepartmentPhones() {
      const form = document.createElement('form');
      form.method = 'POST';
      form.innerHTML = `
        <input type="hidden" name="action" value="save_departments">
        <input type="hidden" name="dept_dental" value="${document.querySelector('input[name="dept_dental"]').value}">
        <input type="hidden" name="dept_traditional" value="${document.querySelector('input[name="dept_traditional"]').value}">
        <input type="hidden" name="dept_drip" value="${document.querySelector('input[name="dept_drip"]').value}">
        <input type="hidden" name="dept_nonsurgical" value="${document.querySelector('input[name="dept_nonsurgical"]').value}">
        <input type="hidden" name="dept_surgical" value="${document.querySelector('input[name="dept_surgical"]').value}">
      `;
      document.body.appendChild(form);
      form.submit();
    }

    function showTestModal(type, label) {
      const modal = new bootstrap.Modal(document.getElementById('testModal'));
      document.getElementById('testType').value = type;
      document.getElementById('testLabel').textContent = label;
      document.getElementById('testPhone').value = '';
      modal.show();
    }

    function sendTestSMS() {
      const type = document.getElementById('testType').value;
      const phone = document.getElementById('testPhone').value;
      
      if (!phone) {
        alert('Утасны дугаарыг оруулна уу!');
        return;
      }

      // Find the form for this type
      const forms = document.querySelectorAll('form');
      let targetForm = null;
      
      forms.forEach(form => {
        const typeInput = form.querySelector('input[name="type"]');
        if (typeInput && typeInput.value === type) {
          targetForm = form;
        }
      });

      if (!targetForm) {
        alert('Форм олдсонгүй!');
        return;
      }

      const form = new FormData();
      form.append('action', 'test');
      form.append('type', type);
      form.append('test_phone', phone);
      
      const messageTextarea = targetForm.querySelector('textarea[name="message"]');
      const clinicNameInput = targetForm.querySelector('input[name="clinic_name"]');
      const clinicPhoneInput = targetForm.querySelector('input[name="clinic_phone"]');
      const isLatinCheckbox = targetForm.querySelector('input[name="is_latin"]');
      
      if (messageTextarea) form.append('message', messageTextarea.value);
      if (clinicNameInput) form.append('clinic_name', clinicNameInput.value);
      if (clinicPhoneInput) form.append('clinic_phone', clinicPhoneInput.value);
      form.append('is_latin', isLatinCheckbox ? (isLatinCheckbox.checked ? 1 : 0) : 0);

      fetch('', { method: 'POST', body: form })
        .then(r => r.text())
        .then(html => {
          location.reload();
        })
        .catch(err => {
          alert('Алдаа: ' + err.message);
        });
    }
  </script>
  <script>
    function showTestModal(type, label) {
      const modal = new bootstrap.Modal(document.getElementById('testModal'));
      document.getElementById('testType').value = type;
      document.getElementById('testLabel').textContent = label;
      modal.show();
    }

    function sendTestSMS() {
      const type = document.getElementById('testType').value;
      const phone = document.getElementById('testPhone').value;
      
      if (!phone) {
        alert('Утасны дугаарыг оруулна уу!');
        return;
      }

      const form = new FormData();
      form.append('action', 'test');
      form.append('type', type);
      form.append('test_phone', phone);
      form.append('message', document.querySelector(`textarea[name="message"]`).value);
      form.append('clinic_name', document.querySelector(`input[name="clinic_name"]`).value);
      form.append('clinic_phone', document.querySelector(`input[name="clinic_phone"]`).value);
      form.append('is_latin', document.querySelector(`input[name="is_latin"]`).checked ? 1 : 0);

      fetch('', { method: 'POST', body: form })
        .then(r => r.text())
        .then(html => {
          location.reload();
        });
    }
  </script>

  <!-- Test SMS Modal -->
  <div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius: 16px; border: none;">
        <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; padding: 1.5rem;">
          <h5 class="modal-title" style="font-weight: 700;">
            <i class="fas fa-envelope me-2"></i>Тестийн SMS явуулах
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding: 2rem;">
          <div class="form-group">
            <label style="font-weight: 700; margin-bottom: 0.75rem; display: block;">Мессеж төрөл</label>
            <input type="hidden" id="testType">
            <p style="color: #667eea; font-weight: 700;" id="testLabel">-</p>
          </div>
          <div class="form-group">
            <label style="font-weight: 700; margin-bottom: 0.75rem; display: block;">Утасны дугаар</label>
            <input type="text" id="testPhone" class="form-control" placeholder="88168812" style="border: 2px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 1rem;">
            <small style="color: #64748b; margin-top: 0.5rem; display: block;">8 оронтой утасны дугаарыг оруулна уу</small>
          </div>
        </div>
        <div class="modal-footer" style="border: none; padding: 1.5rem;">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Хаах</button>
          <button type="button" class="btn-test" onclick="sendTestSMS()">
            <i class="fas fa-paper-plane"></i> Явуулах
          </button>
        </div>
      </div>
    </div>
  </div>
