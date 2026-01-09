<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

// === CLEAN OUTPUT BUFFERING (after session started) ===
if (ob_get_level()) {
    ob_end_clean();
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// Debug log (temporary)
// file_put_contents(__DIR__ . '/debug_global.txt', date('Y-m-d H:i:s') . " - Action: " . $action . " - Method: " . $_SERVER['REQUEST_METHOD'] . " - POST: " . print_r($_POST, true) . "\n", FILE_APPEND);
function json_exit($arr, $code = 200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

if ($action === 'record_usage') {
  require_role(['admin', 'doctor', 'reception']);
  try {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);

    if (!$booking_id || !$inventory_id || $quantity <= 0) {
      json_exit(['ok' => false, 'msg' => 'Мэдээлэл дутуу байна'], 400);
    }

    $st = db()->prepare("SELECT unit_price, stock_quantity FROM inventory WHERE id = ?");
    $st->execute([$inventory_id]);
    $item = $st->fetch();

    if (!$item) json_exit(['ok' => false, 'msg' => 'Материал олдсонгүй'], 404);
    
    $price = $item['unit_price'];

    $st = db()->prepare("INSERT INTO inventory_usage (booking_id, inventory_id, quantity, cost_at_usage) VALUES (?, ?, ?, ?)");
    $st->execute([$booking_id, $inventory_id, $quantity, $price]);

    $st = db()->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
    $st->execute([$quantity, $inventory_id]);

    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

// to_latin and render_template are now in config.php

// Default working hours helper (Mon-Fri 09:00-18:00)
function default_working_hours_payload() {
  $hours = [];
  for ($i=0; $i<7; $i++) {
    $hours[] = [
      'day_of_week' => $i,
      'start_time' => '09:00',
      'end_time' => '18:00',
      'is_available' => 1 // All days available by default
    ];
  }
  return $hours;
}

// Simple column existence check (cached) to avoid errors on older schemas
function column_exists($table, $column) {
  static $cache = [];
  $key = strtolower($table) . '.' . strtolower($column);
  if (isset($cache[$key])) return $cache[$key];
  try {
    $st = db()->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1");
    $st->execute([$table, $column]);
    $cache[$key] = (bool)$st->fetchColumn();
  } catch (Exception $e) {
    $cache[$key] = false;
  }
  return $cache[$key];
}



// Fetch clinic details (DB first, then config fallback)
function load_clinic_info($code) {
  $code = trim($code ?: 'venera');
  $info = [
    'code' => $code,
    'clinic_name' => $code,
    'phone1' => '',
    'phone2' => '',
    'clinic_contacts' => '',
    'clinic_map' => '',
    'clinic_address' => ''
  ];

  try {
    // Build a safe SELECT that works even if phone1/phone2 are missing
    $hasPhone1 = column_exists('clinics', 'phone1');
    $hasPhone2 = column_exists('clinics', 'phone2');
    $hasAddress = column_exists('clinics', 'address');
    $hasMap = column_exists('clinics', 'map_link');

    $select = [
      'name',
      'phone',
      'phone_alt',
      $hasPhone1 ? 'phone1' : "NULL AS phone1",
      $hasPhone2 ? 'phone2' : "NULL AS phone2",
      $hasAddress ? 'address' : "NULL AS address",
      $hasMap ? 'map_link' : "NULL AS map_link"
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM clinics WHERE code=? LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute([$code]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $info['clinic_name'] = $row['name'] ?: $info['clinic_name'];
      $info['phone1'] = trim($row['phone1'] ?? '') ?: trim($row['phone'] ?? '');
      $info['phone2'] = trim($row['phone2'] ?? '') ?: trim($row['phone_alt'] ?? '');
      $info['clinic_address'] = trim($row['address'] ?? '');
      $info['clinic_map'] = trim($row['map_link'] ?? '');
    }
  } catch (Exception $e) {
    // ignore DB errors here, fall back to config
  }

  // Fallback to config directory when DB values are missing
  $fallback = get_clinic_metadata($code);
  if (empty($info['clinic_name']) && !empty($fallback['name'])) $info['clinic_name'] = $fallback['name'];
  if (empty($info['phone1']) && !empty($fallback['phone1'])) $info['phone1'] = $fallback['phone1'];
  if (empty($info['phone2']) && !empty($fallback['phone2'])) $info['phone2'] = $fallback['phone2'];
  if (empty($info['clinic_address']) && !empty($fallback['address'])) $info['clinic_address'] = $fallback['address'];
  if (empty($info['clinic_map']) && !empty($fallback['map'])) $info['clinic_map'] = $fallback['map'];

  $contacts = trim($info['phone1']);
  if (!empty($info['phone2'])) {
    $contacts .= ($contacts ? ' / ' : '') . $info['phone2'];
  }
  $info['clinic_contacts'] = $contacts;

  return $info;
}

// Detect if bookings.clinic_id column exists (avoids hard failure on older schemas)
function has_booking_clinic_id_column() {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;
  try {
    $chk = db()->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = 'bookings' AND column_name = 'clinic_id' LIMIT 1");
    $chk->execute();
    $hasColumn = (bool)$chk->fetchColumn();
  } catch (Exception $e) {
    $hasColumn = false;
  }
  return $hasColumn;
}

function has_booking_clinic_column() {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;
  $hasColumn = column_exists('bookings', 'clinic');
  return $hasColumn;
}

function has_treatment_clinic_column() {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;
  $hasColumn = column_exists('treatments', 'clinic');
  return $hasColumn;
}

function has_treatment_price_editable_column() {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;
  $hasColumn = column_exists('treatments', 'price_editable');
  return $hasColumn;
}

// Public API: treatments (no login required)
if ($action === 'treatments' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? null;
    $hasClinicCol = has_treatment_clinic_column();
    $hasPriceEditable = has_treatment_price_editable_column();
    $priceEditableSelect = $hasPriceEditable ? 'COALESCE(price_editable,0) AS price_editable' : '0 AS price_editable';

    // If clinic not provided but user is logged-in, restrict to their clinic (doctor/reception/admin), except super admin
    if (!$clinic && $hasClinicCol) {
      try {
        $cu = current_user();
        $role = $cu['role'] ?? '';
        $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
        if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
          $clinic = $cu['clinic_id'] ?? null;
        }
      } catch (Exception $e) {
        // ignore
      }
    }

    if ($clinic && $hasClinicCol) {
      $st = db()->prepare("SELECT id, name, category, price, sessions, interval_days, next_visit_mode, aftercare_days, aftercare_message, clinic, {$priceEditableSelect} FROM treatments WHERE (clinic IS NULL OR clinic = '') OR clinic = ? ORDER BY category, name");
      $st->execute([$clinic]);
    } else {
      // No clinic filter or column not present: return all treatments
      $st = db()->prepare("SELECT id, name, category, price, sessions, interval_days, next_visit_mode, aftercare_days, aftercare_message, " . ($hasClinicCol ? 'clinic' : "NULL AS clinic") . ", {$priceEditableSelect} FROM treatments ORDER BY category, name");
      $st->execute();
    }

    $treatments = $st->fetchAll();
    json_exit(['ok'=>true, 'data'=>$treatments]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

// All other actions require login
require_login();

// sendSMS is provided by config.php (uses Twilio if configured, otherwise logs to sms_log)

/* =========================
   GET DOCTOR'S WORKING HOURS (SINGLE DOCTOR)
   ========================= */
if ($action === 'doctor_working_hours' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $doctor_id = (int)($_GET['id'] ?? 0);
    if (!$doctor_id) json_exit(['ok'=>false, 'msg'=>'Doctor ID required'], 400);

    $st = db()->prepare("SELECT day_of_week, start_time, end_time, is_available FROM working_hours WHERE doctor_id = ? ORDER BY day_of_week");
    $st->execute([$doctor_id]);
    $hours = $st->fetchAll(PDO::FETCH_ASSOC);

    if (empty($hours)) {
       $hours = default_working_hours_payload();
    }
    json_exit(['ok'=>true, 'hours'=>$hours]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   UPDATE WORKING HOURS (DOCTOR/ADMIN)
   ========================= */
if ($action === 'update_working_hours' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    require_role(['admin', 'reception', 'doctor']); 
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $doctor_id = (int)($in['doctor_id'] ?? 0);
    $hours = $in['working_hours'] ?? [];

    // Security check: doctors can only update their own hours
    $cu = current_user();
    if ($cu['role'] === 'doctor' && $cu['id'] != $doctor_id) {
        json_exit(['ok'=>false, 'msg'=>'Permission denied'], 403);
    }
    
    if (!$doctor_id || !is_array($hours)) {
      json_exit(['ok'=>false, 'msg'=>'Invalid data'], 422);
    }

    db()->beginTransaction();
    // Clear old hours
    $del = db()->prepare("DELETE FROM working_hours WHERE doctor_id = ?");
    $del->execute([$doctor_id]);

    // Insert new hours
    $ins = db()->prepare("INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?)");
    foreach ($hours as $h) {
        $ins->execute([
            $doctor_id,
            (int)$h['day_of_week'],
            $h['start_time'],
            $h['end_time'],
            (int)($h['is_available'] ?? 1)
        ]);
    }
    db()->commit();
    json_exit(['ok'=>true, 'msg'=>'Saved successfully']);

  } catch (Exception $e) {
    if (db()->inTransaction()) db()->rollBack();
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   UPDATE MY HOURS (DOCTOR SELF)
   ========================= */
if ($action === 'update_treatment' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $id = (int)($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    $category = trim($in['category'] ?? '');
    $price = (float)($in['price'] ?? 0);
    $duration_minutes = (int)($in['duration_minutes'] ?? 30);
    $sessions = (int)($in['sessions'] ?? 1);
    $interval_days = (int)($in['interval_days'] ?? 0);
    $next_visit_mode = in_array($in['next_visit_mode'] ?? '', ['auto', 'manual']) ? $in['next_visit_mode'] : 'auto';
    $aftercare_days = (int)($in['aftercare_days'] ?? 0);
    $aftercare_message = trim($in['aftercare_message'] ?? '');
    $clinic = trim($in['clinic'] ?? '') ?: null;
    $price_editable = isset($in['price_editable']) ? (int)$in['price_editable'] : 0;
    $hasClinicCol = has_treatment_clinic_column();
    $hasPriceEditable = has_treatment_price_editable_column();
    
    if (!$id || !$name) {
      json_exit(['ok'=>false, 'msg'=>'ID болон нэр заавал шаардлагатай'], 422);
    }
    
    $sets = [
      'name = ?',
      'category = ?',
      'price = ?',
      'duration_minutes = ?',
      'sessions = ?',
      'interval_days = ?',
      'next_visit_mode = ?',
      'aftercare_days = ?',
      'aftercare_message = ?'
    ];
    $params = [$name, $category, $price, $duration_minutes, $sessions, $interval_days, $next_visit_mode, $aftercare_days, $aftercare_message];

    if ($hasClinicCol) {
      $sets[] = 'clinic = ?';
      $params[] = $clinic;
    }
    if ($hasPriceEditable) {
      $sets[] = 'price_editable = ?';
      $params[] = $price_editable;
    }

    $params[] = $id;
    $sql = 'UPDATE treatments SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $upd = db()->prepare($sql);
    $upd->execute($params);
    
    json_exit(['ok'=>true, 'msg'=>'Эмчилгээ шинэчлэгдлээ']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   1) Эмч нар (clinic-аар)
   ========================= */
if ($action === 'doctors' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    $department = trim($_GET['department'] ?? '') ?: null;
    // If the current user is a doctor, force the clinic filter to their assigned clinic.
    try {
      $currentUser = current_user();
      $role = $currentUser['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $currentUser['clinic_id'] ?? $clinic;
        // Lock reception to their department
        if ($role === 'reception' && !empty($currentUser['department'])) {
          $department = $currentUser['department'];
        }
      }
    } catch (Exception $ex) {
      // If current_user() is unavailable, ignore and use the provided clinic
    }
    $isAllClinics = ($clinic === 'all');
    $hasColor = column_exists('users', 'color');
    $hasShow = column_exists('users', 'show_in_calendar');
    $hasSort = column_exists('users', 'sort_order');
    $hasActive = column_exists('users', 'active');

    $colorExpr = $hasColor ? "COALESCE(NULLIF(u.color,''),'#0d6efd')" : "'#0d6efd'";
    $showExpr = $hasShow ? 'COALESCE(u.show_in_calendar,1)' : '1';
    $orderClause = $hasSort ? 'ORDER BY COALESCE(sort_order,9999), name' : 'ORDER BY name';
    $activeFilter = $hasActive ? 'COALESCE(u.active,1)=1' : '1=1';

    $sql = "SELECT id, name, {$colorExpr} AS color, clinic_id AS clinic, department, {$showExpr} AS show_in_calendar FROM users u WHERE role='doctor' AND {$activeFilter}";
    $params = [];
    if (!$isAllClinics) {
      $sql .= " AND (clinic_id = ? OR clinic_id = 'all')";
      $params[] = $clinic;
    }
    if ($department) {
      $sql .= " AND (u.department = ? OR u.id IN (SELECT DISTINCT doctor_id FROM bookings WHERE department = ?))";
      $params[] = $department;
      $params[] = $department;
    }
    $sql .= " {$orderClause}";

    $st = db()->prepare($sql);
    $st->execute($params);
    $doctors = $st->fetchAll();

    $docIds = array_column($doctors, 'id');
    if (!empty($docIds)) {
        $in = str_repeat('?,', count($docIds) - 1) . '?';
        $whSt = db()->prepare("SELECT doctor_id, day_of_week, start_time, end_time, is_available FROM working_hours WHERE doctor_id IN ($in) ORDER BY doctor_id, day_of_week");
        $whSt->execute($docIds);
        $allHours = $whSt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        foreach ($doctors as &$doc) {
            $doc['working_hours'] = $allHours[$doc['id']] ?? default_working_hours_payload();
        }
    } else {
        foreach ($doctors as &$doc) { $doc['working_hours'] = default_working_hours_payload(); }
    }

    json_exit(['ok'=>true, 'data'=>$doctors]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   2) Өдрийн захиалгууд
   ========================= */
if ($action === 'bookings' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    $hasClinicCol = has_booking_clinic_column();
    $hasUserColor = column_exists('users', 'color');
    $colorExpr = $hasUserColor ? "COALESCE(NULLIF(u.color,''),'#0d6efd')" : "'#0d6efd'";
    $deptExpr = "COALESCE(NULLIF(u.department,''),'')";
    // Enforce clinic restriction for non-super users
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $date   = $_GET['date']   ?? date('Y-m-d');
    $department = trim($_GET['department'] ?? '') ?: null;
    
    // If clinic is 'all' or clinic column is missing, fetch across all clinics
    if ($clinic === 'all' || !$hasClinicCol) {
        $sqlCount = "SELECT COUNT(*) FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.date=?";
        $sqlBookings = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.date=? ";
        $params = [$date];
        if ($department) {
            $sqlCount .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $sqlBookings .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sqlBookings .= " ORDER BY b.start_time";
        
        $stCount = db()->prepare($sqlCount);
        $stCount->execute($params);
        $count = (int)$stCount->fetchColumn();

        $st = db()->prepare($sqlBookings);
        $st->execute($params);
    } else {
        $sqlCount = "SELECT COUNT(*) FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.clinic=? AND b.date=?";
        $sqlBookings = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.clinic=? AND b.date=? ";
        $params = [$clinic, $date];
        if ($department) {
            $sqlCount .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $sqlBookings .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sqlBookings .= " ORDER BY b.start_time";

        $stCount = db()->prepare($sqlCount);
        $stCount->execute($params);
        $count = (int)$stCount->fetchColumn();

        $st = db()->prepare($sqlBookings);
        $st->execute($params);
    }
    json_exit(['ok'=>true,'data'=>$st->fetchAll()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}
/* ==========================================================
   Doctors list + Working hours
   ========================================================== */
if ($action === 'doctors') {
    // The earlier branch (GET) already handled most requests.
    // This fallback branch ensures we still return working_hours data even if reached.
    // Fetch clinic by ID or code. Prefer the same naming used in the first branch (clinic param).
    $clinicParam = $_GET['clinic'] ?? ($_GET['clinic_id'] ?? 'venera');
    // Restrict doctor users to their own clinic.  Override clinicParam when role=doctor
    try {
        $cu = current_user();
        $role = $cu['role'] ?? '';
        if (in_array($role, ['doctor','reception'])) {
            $clinicParam = $cu['clinic_id'] ?? $clinicParam;
        }
    } catch (Exception $ex) {
        // ignore
    }
    
    $hasColor = column_exists('users', 'color');
    $hasShow = column_exists('users', 'show_in_calendar');
    $hasSort = column_exists('users', 'sort_order');
    $hasActive = column_exists('users', 'active');

    $colorExpr = $hasColor ? "COALESCE(NULLIF(color,''),'#0d6efd')" : "'#0d6efd'";
    $showExpr = $hasShow ? 'COALESCE(show_in_calendar,1)' : '1';
    $orderClause = $hasSort ? 'ORDER BY COALESCE(sort_order,9999), name' : 'ORDER BY name';
    $activeFilter = $hasActive ? 'COALESCE(active,1)=1' : '1=1';

    $sql = "SELECT id, name, {$colorExpr} AS color, department FROM users WHERE role='doctor' AND {$activeFilter} AND (clinic_id = ? OR clinic_id = 'all')";
    if ($hasShow) {
      $sql .= " AND {$showExpr} = 1";
    }
    $sql .= " {$orderClause}";
    $st = db()->prepare($sql);
    $st->execute([$clinicParam]);
    $doctors = $st->fetchAll(PDO::FETCH_ASSOC);

    $docIds = array_column($doctors, 'id');
    if (!empty($docIds)) {
        $in = str_repeat('?,', count($docIds) - 1) . '?';
        $whSt = db()->prepare("SELECT doctor_id, day_of_week, start_time, end_time, is_available FROM working_hours WHERE doctor_id IN ($in) ORDER BY doctor_id, day_of_week");
        $whSt->execute($docIds);
        $allHours = $whSt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        foreach ($doctors as &$doc) {
            $doc['working_hours'] = $allHours[$doc['id']] ?? default_working_hours_payload();
        }
    } else {
        foreach ($doctors as &$doc) { $doc['working_hours'] = default_working_hours_payload(); }
    }

    json_exit(["ok" => true, "data" => $doctors]);
}

/* =========================
   3) Долоо хоног
   ========================= */
if ($action === 'bookings_week' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    $hasClinicCol = has_booking_clinic_column();
    $hasUserColor = column_exists('users', 'color');
    $colorExpr = $hasUserColor ? "COALESCE(NULLIF(u.color,''),'#0d6efd')" : "'#0d6efd'";
    $deptExpr = "COALESCE(NULLIF(u.department,''),'')";
    // Enforce clinic restriction for non-super users
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $start  = $_GET['start']  ?? date('Y-m-d');
    $end    = $_GET['end']    ?? date('Y-m-d');
    $department = trim($_GET['department'] ?? '') ?: null;
    
    // If clinic is 'all' or clinic column is missing, fetch from all clinics. Otherwise, filter by clinic
    if ($clinic === 'all' || !$hasClinicCol) {
        $sql = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.date BETWEEN ? AND ? ";
        $params = [$start, $end];
        if ($department) {
            $sql .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sql .= " ORDER BY b.date, b.start_time";
        $st = db()->prepare($sql);
        $st->execute($params);
    } else {
        $sql = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.clinic=? AND b.date BETWEEN ? AND ? ";
        $params = [$clinic, $start, $end];
        if ($department) {
            $sql .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sql .= " ORDER BY b.date, b.start_time";
        $st = db()->prepare($sql);
        $st->execute($params);
    }
    json_exit(['ok'=>true,'data'=>$st->fetchAll()]);
  } catch (Throwable $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   4) Сар
   ========================= */
if ($action === 'bookings_month' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    $hasClinicCol = has_booking_clinic_column();
    $hasUserColor = column_exists('users', 'color');
    $colorExpr = $hasUserColor ? "COALESCE(NULLIF(u.color,''),'#0d6efd')" : "'#0d6efd'";
    $deptExpr = "COALESCE(NULLIF(u.department,''),'')";
    // Enforce clinic restriction for non-super users
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $month  = $_GET['month']  ?? date('Y-m');
    $department = trim($_GET['department'] ?? '') ?: null;
    
    // If clinic is 'all' or clinic column is missing, fetch from all clinics. Otherwise, filter by clinic
    $cu = current_user();
    $role = $cu['role'] ?? '';
    if ($clinic === 'all' || !$hasClinicCol) {
        $sql = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE TO_CHAR(b.date, 'YYYY-MM')=? ";
        $params = [$month];
        if ($department) {
            $sql .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sql .= " ORDER BY b.date, b.start_time";
        $st = db()->prepare($sql);
        $st->execute($params);
    } else {
        $sql = "SELECT b.*, u.name AS doctor_name, {$colorExpr} AS doctor_color, COALESCE(NULLIF(b.department,''), u.department, '') AS doctor_department FROM bookings b LEFT JOIN users u ON u.id=b.doctor_id AND u.role='doctor' WHERE b.clinic=? AND TO_CHAR(b.date, 'YYYY-MM')=? ";
        $params = [$clinic, $month];
        if ($department) {
            $sql .= " AND (COALESCE(NULLIF(b.department,''), u.department, '') = CAST(? AS text))";
            $params[] = $department;
        }
        $sql .= " ORDER BY b.date, b.start_time";
        $st = db()->prepare($sql);
        $st->execute($params);
    }
    json_exit(['ok'=>true,'data'=>$st->fetchAll()]);
  } catch (Throwable $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   5) Шинэ захиалга үүсгэх
   ========================= */
if ($action === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $doctor_id   = !empty($in['doctor_id']) ? (int)$in['doctor_id'] : null; // Сонголтоор, 0 эсвэл хоосон бол NULL
    $clinic      = trim($in['clinic'] ?? ($_SESSION['clinic_id'] ?? 'venera'));
    $date        = trim($in['date'] ?? '');
    $start       = trim($in['start_time'] ?? '');
    $end         = trim($in['end_time'] ?? '');
    $patient     = trim($in['patient_name'] ?? '');
    $gender      = trim($in['gender'] ?? '');
    $visit_count = (int)($in['visit_count'] ?? 1);
    $phone       = trim($in['phone'] ?? '');
    $note        = trim($in['note'] ?? '');
    $service_name = trim($in['service_name'] ?? '');
    $status      = trim($in['status'] ?? 'online');
    $treatment_id = (int)($in['treatment_id'] ?? 0);   // 🦷 Treatment ID
    $session_number = (int)($in['session_number'] ?? 1); // 🦷 Session number
    $department  = trim($in['department'] ?? '');

    // Determine clinic:
    // 1. If doctor_id is provided, use doctor's clinic (if not 'all')
    // 2. Otherwise use current user's clinic if restricted
    // 3. Otherwise use input clinic
    if ($doctor_id > 0) {
      try {
        $stDocCl = db()->prepare("SELECT clinic_id FROM users WHERE id = ? AND role = 'doctor'");
        $stDocCl->execute([$doctor_id]);
        $docClinic = $stDocCl->fetchColumn();
        if ($docClinic && $docClinic !== 'all') {
          $clinic = $docClinic;
        }
      } catch (Exception $e) {}
    }

    if (!$clinic || $clinic === 'all' || $clinic === '') {
      try {
        $cu = current_user();
        $role = $cu['role'] ?? '';
        $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
        if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
          $clinic = $cu['clinic_id'] ?? $clinic;
        }
      } catch (Exception $e) {}
    }

    if ($clinic === 'all' || $clinic === '') {
       // Final fallback if still 'all' or empty
       $clinic = 'venera';
    }

    if ($clinic === '') {
      json_exit(['ok'=>false, 'msg'=>'Clinic ID шаардлагатай.'], 422);
    }

    // Require either a treatment_id or a free-text service name
    if (!$clinic || !$date || !$start || $patient === '' || (!$treatment_id && $service_name === '')) {
      json_exit(['ok'=>false, 'msg'=>'Талбар дутуу байна.'], 422);
    }

    // Department is required only for Venera clinic
    if ($clinic === 'venera' && $department === '') {
      json_exit(['ok'=>false, 'msg'=>'Тасаг заавал сонгоно уу.'], 422);
    }

    // Load clinic info once for downstream use (SMS + auditing)
    $clinicInfo = load_clinic_info($clinic);
    
    // Get service_name, price and duration from treatment
    $price = 0;
    $duration_minutes = 30; // default
    if ($treatment_id > 0) {
      $stTreat = db()->prepare("SELECT name, price, duration_minutes FROM treatments WHERE id = ?");
      $stTreat->execute([$treatment_id]);
      $treatData = $stTreat->fetch(PDO::FETCH_ASSOC);
      if ($treatData) {
        if (empty($service_name)) {
          $service_name = $treatData['name'] ?? '';
        }
        $price = (float)($treatData['price'] ?? 0);
        $duration_minutes = (int)($treatData['duration_minutes'] ?? 30);
      }
    } elseif ($service_name !== '') {
      // Auto-create treatment if user typed a new service name (so it appears in treatments list)
      try {
        $hasClinicCol = has_treatment_clinic_column();
        $findSql = "SELECT id FROM treatments WHERE name = ?" . ($hasClinicCol ? " AND (clinic = ? OR clinic IS NULL OR clinic = '')" : '');
        $findParams = $hasClinicCol ? [$service_name, $clinic] : [$service_name];
        $chk = db()->prepare($findSql);
        $chk->execute($findParams);
        $existingId = (int)$chk->fetchColumn();
        if ($existingId > 0) {
          $treatment_id = $existingId;
        } else {
          // Insert minimal treatment record
          $cols = ['name','category','price','duration_minutes','sessions','interval_days','next_visit_mode','aftercare_days','aftercare_message'];
          $placeholders = array_fill(0, count($cols), '?');
          $params = [$service_name, '', 0, 30, 1, 0, 'auto', 0, ''];
          if ($hasClinicCol) {
            $cols[] = 'clinic';
            $placeholders[] = '?';
            $params[] = $clinic;
          }
          $insSql = 'INSERT INTO treatments (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
          $ins = db()->prepare($insSql);
          $ins->execute($params);
          $treatment_id = (int)db()->lastInsertId();
        }
      } catch (Exception $e) {
        // ignore auto-create errors; booking will still save
      }
    }
    
    // Auto-calculate end_time based on treatment duration if not provided
    $startTime = $start ? strtotime($date . ' ' . $start) : false;
    if (empty($end) && $startTime) {
      $endTime = $startTime + ($duration_minutes * 60);
      $end = date('H:i:s', $endTime);
    }
    
    if (empty($end)) {
      json_exit(['ok'=>false, 'msg'=>'Дуусах цаг тодорхойлогдохгүй байна.'], 422);
    }

    // === ЭМЧИЙН АЖИЛЛАХ ЦАГ ШАЛГАХ === (fixed 09:00-18:00)
    $start = $start ? date('H:i:s', strtotime($start)) : $start;
    $end = $end ? date('H:i:s', strtotime($end)) : $end;
    if ($doctor_id !== null) {
      $defaultStart = '09:00:00';
      $defaultEnd = '18:00:00';
      if ($start < $defaultStart || $end > $defaultEnd) {
        json_exit(['ok'=>false, 'msg'=>'Энэ цаг эмчийн ажиллах хуваарийн гадна байна. (09:00-18:00)'], 422);
      }
    }

    $visit_count = max(1, $visit_count);
    $normalizedPhone = preg_replace('/\D+/', '', $phone);
    if ($normalizedPhone !== '') {
      $vs = db()->prepare("
        SELECT COUNT(*)
        FROM bookings
        WHERE clinic=?
          AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
      ");
      $vs->execute([$clinic, $normalizedPhone]);
      $existingVisits = (int)$vs->fetchColumn();
      if ($existingVisits >= 1) {
        $visit_count = 2;
      }
    }

    // Давхцал шалгалт (эмч сонгосон байвал л)
    if ($doctor_id !== null) {
      $q = db()->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE doctor_id=? AND clinic=? AND date=?
          AND NOT (end_time<=? OR start_time>=?)
      ");
      $q->execute([$doctor_id, $clinic, $date, $start, $end]);
      if ($q->fetchColumn() > 0) {
        json_exit(['ok'=>false, 'msg'=>'Энэ цагт давхцал байна.'], 409);
      }
    }

    // Хадгалах
    // Use department from request (required). If doctor selected but department empty, fallback to doctor's department.
    $bookingDept = $department;
    if ($bookingDept === '' && $doctor_id !== null) {
      try {
        $deptStmt = db()->prepare("SELECT department FROM users WHERE id=? AND role='doctor'");
        $deptStmt->execute([$doctor_id]);
        $bookingDept = $deptStmt->fetchColumn();
      } catch (Exception $e) {
        // ignore
      }
    }

    $hasClinicId = has_booking_clinic_id_column();
    $insertSql = $hasClinicId
      ? "INSERT INTO bookings (doctor_id, clinic, clinic_id, date, start_time, end_time, patient_name, gender, visit_count, phone, note, service_name, status, department, treatment_id, session_number, price, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
      : "INSERT INTO bookings (doctor_id, clinic, date, start_time, end_time, patient_name, gender, visit_count, phone, note, service_name, status, department, treatment_id, session_number, price, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";

    $insertParams = $hasClinicId
      ? [
          $doctor_id,
          $clinic,
          $clinic,
          $date,
          $start,
          $end,
          $patient,
          $gender,
          $visit_count,
          $phone,
          $note,
          $service_name,
          $status,
          $bookingDept,
          $treatment_id > 0 ? $treatment_id : null,
          $session_number,
          $price
        ]
      : [
          $doctor_id,
          $clinic,
          $date,
          $start,
          $end,
          $patient,
          $gender,
          $visit_count,
          $phone,
          $note,
          $service_name,
          $status,
          $bookingDept,
          $treatment_id > 0 ? $treatment_id : null,
          $session_number,
          $price
        ];

    try {
      $ins = db()->prepare($insertSql);
      $ins->execute($insertParams);
    } catch (PDOException $dbEx) {
      error_log("❌ Booking INSERT failed: " . $dbEx->getMessage());
      json_exit(['ok'=>false, 'msg'=>'Booking үүсгэхэд алдаа: ' . $dbEx->getMessage()], 500);
    }

    $newId = db()->lastInsertId();

    // === Patients Table Upsert ===
    // Ensure patient data is centered in the patients table
    if (!empty($phone)) {
      try {
        $stPat = db()->prepare("
          INSERT INTO patients (name, phone, gender) 
          VALUES (?, ?, ?)
          ON CONFLICT (phone) DO UPDATE SET 
            name = EXCLUDED.name,
            gender = EXCLUDED.gender,
            updated_at = NOW()
        ");
        $stPat->execute([$patient, $phone, $gender]);
      } catch (Exception $e) {
        error_log("❌ Patient upsert failed: " . $e->getMessage());
      }
    }

    // === SMS Notification ===
    // If a phone number is provided, send an SMS notification about the successful booking.
    // This uses the sendSMS helper defined in config.php (Twilio or local logging).
    if (!empty($phone) && function_exists('sendSMS')) {
      // Get clinic and doctor names
      $clinicName = '';
      $doctorName = '';
      try {
        // Get clinic human-readable name
        $stClin = db()->prepare("SELECT name FROM clinics WHERE code=?");
        $stClin->execute([$clinic]);
        $clinicName = $stClin->fetchColumn() ?: $clinic;
      } catch (Exception $e) {
        $clinicName = $clinic;
      }
      $clinicNameLatin = to_latin($clinicName) ?: $clinic;
      if ($doctor_id) {
        try {
          $stDocNm = db()->prepare("SELECT name FROM users WHERE id=? AND role='doctor'");
          $stDocNm->execute([$doctor_id]);
          $doctorName = $stDocNm->fetchColumn() ?: '';
        } catch (Exception $e) {
          $doctorName = '';
        }
      }
      // Load confirmation template from DB - try clinic-specific first, then global
      $confirmTpl = '';
      $isLatin = 1;
      $templateClinicName = '';
      $templateClinicPhone = '';
      try {
        // Try clinic-specific template first
        $stTpl = db()->prepare("SELECT message, is_latin, clinic_name, clinic_phone FROM sms_templates WHERE type = 'confirmation' AND clinic = ? LIMIT 1");
        $stTpl->execute([$clinic]);
        $rowTpl = $stTpl->fetch(PDO::FETCH_ASSOC);
        
        if (!$rowTpl) {
          // Fallback to global template
          $stTpl = db()->prepare("SELECT message, is_latin, clinic_name, clinic_phone FROM sms_templates WHERE type = 'confirmation' AND clinic = 'global' LIMIT 1");
          $stTpl->execute();
          $rowTpl = $stTpl->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($rowTpl) {
           $confirmTpl = $rowTpl['message'];
           $isLatin = (int)$rowTpl['is_latin'];
           $templateClinicName = $rowTpl['clinic_name'] ?? '';
           $templateClinicPhone = $rowTpl['clinic_phone'] ?? '';
        }
      } catch (Exception $e) {}

      // Get appropriate phone number - use template phone as default if available
      $defaultPhone = $templateClinicPhone ?: ($clinicInfo['phone1'] ?: '70115090');
      $deptPhone = getPhoneForDepartment($newId, $clinic, $defaultPhone);
      
      // Use template clinic name if available, otherwise use resolved clinic name
      $finalClinicName = $templateClinicName ?: $clinicNameLatin;

      // Variables for template - keys should be WITHOUT braces to match render_template()
      $repl = [
        'patient_name' => $patient,
        'clinic_name' => $finalClinicName,
        'date' => $date,
        'start_time' => $start,
        'phone' => $deptPhone,
        'doctor' => $doctorName,
        'treatment' => $service_name
      ];

      if ($confirmTpl) {
        $msg = render_template($confirmTpl, $repl);
      } else {
        // Fallback
        $msg = "Sain baina uu {patient_name}! Tany zahialga {clinic_name}-d {date} {start_time}-d batalgaajlaa. Lawlah utas: {phone}.";
        $msg = render_template($msg, $repl);
      }

      if ($isLatin) {
        $msg = to_latin($msg);
      }

      // Send SMS (if configured)
      try {
        sendSMS($phone, $msg, $newId);
      } catch (Exception $smsEx) {
        // ignore SMS errors
      }
    }

    // --- Proactive SMS Scheduling (Reminders & Aftercare) ---
    syncScheduledSMS($newId);

    json_exit(['ok'=>true, 'id'=>$newId, 'msg'=>'Амжилттай бүртгэгдлээ.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   6) Шинэчлэх
   ========================= */
if ($action === 'update' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    if (!$id) json_exit(['ok'=>false,'msg'=>'ID алга.'],422);

    $q = db()->prepare("SELECT * FROM bookings WHERE id=?");
    $q->execute([$id]);
    $old = $q->fetch();
    if (!$old) json_exit(['ok'=>false,'msg'=>'Илэрцгүй.'],404);

    // doctor_id: null/'' бол NULL, бөгөөдөөр хуучин утга ашиглана
    if (array_key_exists('doctor_id', $in)) {
      $doctor_id = ($in['doctor_id'] !== null && $in['doctor_id'] !== '') ? (int)$in['doctor_id'] : null;
    } else {
      $doctor_id = $old['doctor_id'];
    }
    $clinic      = $in['clinic']       ?? $old['clinic'];
    $date        = $in['date']         ?? $old['date'];
    $start       = $in['start_time']   ?? $old['start_time'];
    $end         = $in['end_time']     ?? $old['end_time'];
    $patient     = $in['patient_name'] ?? $old['patient_name'];
    $gender      = $in['gender']       ?? $old['gender'];
    $visit_count = (int)($in['visit_count'] ?? $old['visit_count']);
    $phone       = $in['phone']        ?? $old['phone'];
    $note        = $in['note']         ?? $old['note'];
    $service_name = trim($in['service_name'] ?? $old['service_name']);
    $status      = $in['status']       ?? $old['status'];
    $department  = trim($in['department'] ?? ($old['department'] ?? ''));

    // Lock clinic for non-super admins
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $e) {
      // ignore
    }

    if (trim($clinic) === '') {
      json_exit(['ok'=>false,'msg'=>'Clinic ID шаардлагатай.'],422);
    }

    if ($service_name === '') {
      json_exit(['ok'=>false,'msg'=>'Үйлчилгээний нэр шаардлагатай.'],422);
    }

    // Department is required only for Venera clinic
    if ($clinic === 'venera' && $department === '') {
      json_exit(['ok'=>false,'msg'=>'Тасаг заавал сонгоно уу.'],422);
    }

    // === ЭМЧИЙН АЖИЛЛАХ ЦАГ ШАЛГАХ (UPDATE) === (fixed 09:00-18:00)
    $start = $start ? date('H:i:s', strtotime($start)) : $start;
    $end = $end ? date('H:i:s', strtotime($end)) : $end;
    if ($doctor_id !== null) {
      $defaultStart = '09:00:00';
      $defaultEnd = '18:00:00';
      if ($start < $defaultStart || $end > $defaultEnd) {
        json_exit(['ok'=>false, 'msg'=>'Энэ цаг эмчийн ажиллах хуваарийн гадна байна. (09:00-18:00)'], 422);
      }
    }

    $visit_count = max(1, $visit_count);
    $normalizedPhone = preg_replace('/\D+/', '', $phone);
    if ($normalizedPhone !== '') {
      $vs = db()->prepare("
        SELECT COUNT(*)
        FROM bookings
        WHERE clinic=?
          AND id<>?
          AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
      ");
      $vs->execute([$clinic, $id, $normalizedPhone]);
      $existingVisits = (int)$vs->fetchColumn();
      if ($existingVisits >= 1) {
        $visit_count = 2;
      } else {
        $visit_count = 1;
      }
    }

    // Давхцал шалгалт (эмч сонгосон байвал л)
    if ($doctor_id !== null) {
      $oldStart = date('H:i:s', strtotime($old['start_time']));
      $oldEnd = date('H:i:s', strtotime($old['end_time']));
      
      $scheduleChanged = (
        $doctor_id != $old['doctor_id'] ||
        $date != $old['date'] ||
        $start != $oldStart ||
        $end != $oldEnd
      );

      if ($scheduleChanged) {
        $q2 = db()->prepare("
          SELECT COUNT(*) FROM bookings
          WHERE doctor_id=? AND clinic=? AND date=? AND id<>?
            AND NOT (end_time<=? OR start_time>=?)
        ");
        $q2->execute([$doctor_id,$clinic,$date,$id,$start,$end]);
        if ($q2->fetchColumn() > 0) {
          json_exit(['ok'=>false,'msg'=>'Давхцал байна.'],409);
        }
      }
    }

    // Use department from request; fallback to doctor's department only if empty
    $bookingDept = $department;
    if ($bookingDept === '' && $doctor_id !== null) {
      try {
        $deptStmt = db()->prepare("SELECT department FROM users WHERE id=? AND role='doctor'");
        $deptStmt->execute([$doctor_id]);
        $bookingDept = $deptStmt->fetchColumn();
      } catch (Exception $e) {
        // ignore
      }
    }

    $treatment_id = (int)($in['treatment_id'] ?? $old['treatment_id']);
    $session_number = (int)($in['session_number'] ?? $old['session_number']);
    $price = (float)($in['price'] ?? $old['price']);

    $hasClinicId = has_booking_clinic_id_column();
    if ($hasClinicId) {
      $u = db()->prepare("
        UPDATE bookings SET
          doctor_id=?, clinic=?, clinic_id=?, date=?, start_time=?, end_time=?,
          patient_name=?, gender=?, visit_count=?, phone=?, note=?, service_name=?, status=?, department=?,
          treatment_id=?, session_number=?, price=?
        WHERE id=?
      ");
      $u->execute([
        $doctor_id, $clinic, $clinic, $date, $start, $end,
        $patient, $gender, $visit_count, $phone, $note, $service_name, $status, $bookingDept,
        $treatment_id > 0 ? $treatment_id : null, $session_number, $price,
        $id
      ]);
    } else {
      $u = db()->prepare("
        UPDATE bookings SET
          doctor_id=?, clinic=?, date=?, start_time=?, end_time=?,
          patient_name=?, gender=?, visit_count=?, phone=?, note=?, service_name=?, status=?, department=?,
          treatment_id=?, session_number=?, price=?
        WHERE id=?
      ");
      $u->execute([
        $doctor_id, $clinic, $date, $start, $end,
        $patient, $gender, $visit_count, $phone, $note, $service_name, $status, $bookingDept,
        $treatment_id > 0 ? $treatment_id : null, $session_number, $price,
        $id
      ]);
    }

    // --- Proactive SMS Scheduling (Reminders & Aftercare) ---
    syncScheduledSMS($id);

    json_exit(['ok'=>true,'msg'=>'Шинэчлэгдлээ.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   7) Устгах
   ========================= */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    if (!$id) json_exit(['ok'=>false,'msg'=>'ID алга.'],422);

    $cu = current_user();
    $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
    if (!$isSuper) {
      $chk = db()->prepare("SELECT clinic FROM bookings WHERE id=? LIMIT 1");
      $chk->execute([$id]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);
      $userClinic = $cu['clinic_id'] ?? '';
      if ($row && $row['clinic'] !== $userClinic) {
        json_exit(['ok'=>false,'msg'=>'Энэ клиникт зөвшөөрөгдөөгүй.'],403);
      }
    }

    $d = db()->prepare("DELETE FROM bookings WHERE id=?");
    $d->execute([$id]);
    json_exit(['ok'=>true,'msg'=>'Устгагдлаа.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   8) Утас → өмнөх мэдээлэл (B: Clinic-first then Global Fallback)
   ========================= */
if ($action === 'patient_info' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $phone  = trim($_GET['phone'] ?? '');
    $clinic = $_GET['clinic'] ?? 'venera';
    // Restrict non-super users to their own clinic
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['doctor','reception','admin'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    if ($phone === '') json_exit(['ok'=>false,'msg'=>'Утас хоосон байна']);
    $phone_norm = preg_replace('/\D+/', '', $phone);

    // STEP 1: Try exact normalized match in the CURRENT clinic first
    $st = db()->prepare("
      SELECT patient_name, gender, note, service_name,
             COUNT(*) AS visits, MAX(date) AS last_visit, clinic
      FROM bookings
      WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
        AND clinic=?
      GROUP BY patient_name, gender, note, service_name, clinic
      ORDER BY last_visit DESC
      LIMIT 1
    ");
    $st->execute([$phone_norm,$clinic]);
    $data = $st->fetch(PDO::FETCH_ASSOC);

    // STEP 2: If not found in current clinic, try last 7 digits in current clinic
    if (!$data) {
      $last7 = substr($phone_norm, -7);
      if ($last7) {
        $st2 = db()->prepare("
          SELECT patient_name, gender, note, service_name,
                 COUNT(*) AS visits, MAX(date) AS last_visit, clinic
          FROM bookings
          WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''),7) = ?
            AND clinic=?
          GROUP BY patient_name, gender, note, service_name, clinic
          ORDER BY last_visit DESC
          LIMIT 1
        ");
        $st2->execute([$last7, $clinic]);
        $data = $st2->fetch(PDO::FETCH_ASSOC);
      }
    }

    // STEP 3: If still not found, search GLOBALLY (all clinics) - exact normalized match
    if (!$data) {
      $st3 = db()->prepare("
        SELECT patient_name, gender, note, service_name,
               COUNT(*) AS visits, MAX(date) AS last_visit, clinic
        FROM bookings
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
        GROUP BY patient_name, gender, note, service_name, clinic
        ORDER BY last_visit DESC
        LIMIT 1
      ");
      $st3->execute([$phone_norm]);
      $data = $st3->fetch(PDO::FETCH_ASSOC);
      
      if ($data && $data['clinic'] !== $clinic) {
        $data['source_clinic'] = $data['clinic'];
        $data['is_global_match'] = true;
      }
    }

    // STEP 4: If STILL not found globally, try last 7 digits globally
    if (!$data) {
      $last7 = substr($phone_norm, -7);
      if ($last7) {
        $st4 = db()->prepare("
          SELECT patient_name, gender, note, service_name,
                 COUNT(*) AS visits, MAX(date) AS last_visit, clinic
          FROM bookings
          WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''),7) = ?
          GROUP BY patient_name, gender, note, service_name, clinic
          ORDER BY last_visit DESC
          LIMIT 1
        ");
        $st4->execute([$last7]);
        $data = $st4->fetch(PDO::FETCH_ASSOC);
        
        if ($data && $data['clinic'] !== $clinic) {
          $data['source_clinic'] = $data['clinic'];
          $data['is_global_match'] = true;
        }
      }
    }

    json_exit(['ok'=>true,'data'=>$data ?: null]);
  } catch (Exception $e) {
    json_exit(['ok'=>false,'msg'=>$e->getMessage()],500);
  }
}

/* =========================
   10) Эмч нэмэх
   ========================= */
if ($action === 'add_doctor' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $name = trim($in['name'] ?? '');
    $color = trim($in['color'] ?? '#0d6efd');
    $clinic = trim($in['clinic'] ?? 'venera');
    $department = trim($in['department'] ?? '');
    $specialty = trim($in['specialty'] ?? '');
    $phone = trim($in['phone'] ?? '');
    $pin = trim($in['pin'] ?? '');

    // Lock clinic for non-super users
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      $isSuper = function_exists('is_super_admin') ? is_super_admin() : false;
      if (!$isSuper && in_array($role, ['admin','reception','doctor'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $e) {
      // ignore; fallback to provided clinic
    }
    
    if (!$name) json_exit(['ok'=>false,'msg'=>'Эмчийн нэр оруулна уу.'], 422);
    
    $pinHash = $pin ? password_hash($pin, PASSWORD_DEFAULT) : '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2';
    $ins = db()->prepare("INSERT INTO users (name, phone, pin_hash, role, clinic_id, department, specialty, color, show_in_calendar, active, created_at) VALUES (?, ?, ?, 'doctor', ?, ?, ?, ?, 1, 1, NOW())");
    $ins->execute([$name, $phone, $pinHash, $clinic, $department, $specialty, $color]);
    $doctor_id = db()->lastInsertId();

    json_exit(['ok'=>true, 'id'=>$doctor_id, 'msg'=>'Эмч амжилттай нэмэгдлээ.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   11) Эмч засах
   ========================= */
if ($action === 'edit_doctor' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $id = (int)($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    $color = trim($in['color'] ?? '#0d6efd');
    $clinic = trim($in['clinic'] ?? '');
    $department = trim($in['department'] ?? '');
    $specialty = trim($in['specialty'] ?? '');
    $show = isset($in['show_in_calendar']) ? (int)$in['show_in_calendar'] : 1;
    $active = isset($in['active']) ? (int)$in['active'] : 1;
    
    if (!$id || !$name) json_exit(['ok'=>false,'msg'=>'ID эсвэл нэр алга.'], 422);
    
    $upd = db()->prepare("UPDATE users SET name=?, color=?, clinic_id=COALESCE(NULLIF(?, ''), clinic_id), department=?, specialty=?, show_in_calendar=?, active=? WHERE id=? AND role='doctor'");
    $upd->execute([$name, $color, $clinic, $department, $specialty, $show, $active, $id]);

    json_exit(['ok'=>true, 'msg'=>'Эмч амжилттай засагдлаа.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   12) Эмч хасах
   ========================= */
if ($action === 'delete_doctor' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    
    if (!$id) json_exit(['ok'=>false,'msg'=>'ID алга.'], 422);
    $hasActive = column_exists('users', 'active');
    $hasShow = column_exists('users', 'show_in_calendar');

    $sets = [];
    $params = [];
    if ($hasActive) { $sets[] = 'active = 0'; }
    if ($hasShow) { $sets[] = 'show_in_calendar = 0'; }
    if (empty($sets)) { $sets[] = 'role = role'; }
    $params[] = $id;

    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id=? AND role=\'doctor\'';
    $upd = db()->prepare($sql);
    $upd->execute($params);

    json_exit(['ok'=>true, 'msg'=>'Эмч амжилттай идэвхгүй боллоо.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   ADD TREATMENT (Admin & Reception)
   ========================= */
if ($action === 'add_treatment' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $name = trim($in['name'] ?? '');
    $category = trim($in['category'] ?? '');
    $price = (float)($in['price'] ?? 0);
    $duration_minutes = (int)($in['duration_minutes'] ?? 30);
    $sessions = (int)($in['sessions'] ?? 1);
    $interval_days = (int)($in['interval_days'] ?? 0);
    $next_visit_mode = in_array($in['next_visit_mode'] ?? '', ['auto', 'manual']) ? $in['next_visit_mode'] : 'auto';
    $aftercare_days = (int)($in['aftercare_days'] ?? 0);
    $aftercare_message = trim($in['aftercare_message'] ?? '');
    $clinic = trim($in['clinic'] ?? '') ?: null;
    $price_editable = isset($in['price_editable']) ? (int)$in['price_editable'] : 0;
    $hasClinicCol = has_treatment_clinic_column();
    $hasPriceEditable = has_treatment_price_editable_column();
    
    if (!$name) {
      json_exit(['ok'=>false, 'msg'=>'Эмчилгээний нэр оруулна уу'], 422);
    }
    // Build insert dynamically to tolerate missing columns
    $cols = ['name','category','price','duration_minutes','sessions','interval_days','next_visit_mode','aftercare_days','aftercare_message'];
    $placeholders = array_fill(0, count($cols), '?');
    $params = [$name, $category, $price, $duration_minutes, $sessions, $interval_days, $next_visit_mode, $aftercare_days, $aftercare_message];
    if ($hasClinicCol) {
      $cols[] = 'clinic';
      $placeholders[] = '?';
      $params[] = $clinic;
    }
    if ($hasPriceEditable) {
      $cols[] = 'price_editable';
      $placeholders[] = '?';
      $params[] = $price_editable;
    }
    $sql = 'INSERT INTO treatments (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $ins = db()->prepare($sql);
    $ins->execute($params);
    
    json_exit(['ok'=>true, 'msg'=>'Эмчилгээ нэмэгдлээ', 'id'=>db()->lastInsertId()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   UPDATE TREATMENT (Admin & Reception)
   ========================= */
if ($action === 'update_treatment' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $id = (int)($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    $category = trim($in['category'] ?? '');
    $price = (float)($in['price'] ?? 0);
    $duration_minutes = (int)($in['duration_minutes'] ?? 30);
    $sessions = (int)($in['sessions'] ?? 1);
    $interval_days = (int)($in['interval_days'] ?? 0);
    $next_visit_mode = in_array($in['next_visit_mode'] ?? '', ['auto', 'manual']) ? $in['next_visit_mode'] : 'auto';
    $aftercare_days = (int)($in['aftercare_days'] ?? 0);
    $aftercare_message = trim($in['aftercare_message'] ?? '');
    
    if (!$id || !$name) {
      json_exit(['ok'=>false, 'msg'=>'ID болон нэр заавал шаардлагатай'], 422);
    }
    
    $upd = db()->prepare("
      UPDATE treatments SET 
        name = ?, category = ?, price = ?, duration_minutes = ?, sessions = ?, interval_days = ?, 
        next_visit_mode = ?, aftercare_days = ?, aftercare_message = ?, clinic = ?, price_editable = ?
      WHERE id = ?
    ");
    $upd->execute([$name, $category, $price, $duration_minutes, $sessions, $interval_days, $next_visit_mode, $aftercare_days, $aftercare_message, $clinic, $price_editable, $id]);
    
    json_exit(['ok'=>true, 'msg'=>'Эмчилгээ шинэчлэгдлээ']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
  DELETE TREATMENT (Admin & Reception)
  ========================= */
if ($action === 'delete_treatment' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    
    if (!$id) {
      json_exit(['ok'=>false, 'msg'=>'ID заавал шаардлагатай'], 422);
    }
    
    // Check if treatment is used in bookings
    $check = db()->prepare("SELECT COUNT(*) FROM bookings WHERE treatment_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
      json_exit(['ok'=>false, 'msg'=>'Энэ эмчилгээтэй холбоотой цаг захиалга байгаа тул устгах боломжгүй'], 422);
    }
    
    $del = db()->prepare("DELETE FROM treatments WHERE id = ?");
    $del->execute([$id]);
    
    json_exit(['ok'=>true, 'msg'=>'Эмчилгээ устгагдлаа']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   ADD SMS SCHEDULE
   ========================= */
if ($action === 'add_sms' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin','reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $booking_id = isset($in['booking_id']) ? (int)$in['booking_id'] : null;
    $phone = trim($in['phone'] ?? '');
    $message = trim($in['message'] ?? '');
    $scheduled_at = trim($in['scheduled_at'] ?? '');

    if (!$phone || !$message || !$scheduled_at) {
      json_exit(['ok'=>false,'msg'=>'Бүх талбарыг бөглөнө үү'],422);
    }

    $ins = db()->prepare("INSERT INTO sms_schedule (booking_id, phone, message, scheduled_at, type, status, created_at) VALUES (?, ?, ?, ?, 'manual', 'pending', NOW())");
    $ins->execute([$booking_id, $phone, $message, $scheduled_at]);

    json_exit(['ok'=>true,'msg'=>'SMS төлөвлөсөн','id'=>db()->lastInsertId()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false,'msg'=>$e->getMessage()],500);
  }
}

/* =========================
   UPDATE SMS SCHEDULE
   ========================= */
if ($action === 'update_sms' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $id = (int)($in['id'] ?? 0);
    $phone = trim($in['phone'] ?? '');
    $message = trim($in['message'] ?? '');
    $scheduled_at = trim($in['scheduled_at'] ?? '');
    $booking_id = isset($in['booking_id']) ? (int)$in['booking_id'] : 0;
    
    if (!$id || !$phone || !$message) {
      json_exit(['ok'=>false, 'msg'=>'Бүх талбарыг бөглөнө үү'], 422);
    }
    
    if ($booking_id > 0) {
      $upd = db()->prepare("
        UPDATE sms_schedule SET phone = ?, message = ?, scheduled_at = ?, booking_id = ?
        WHERE id = ? AND status = 'pending'
      ");
      $upd->execute([$phone, $message, $scheduled_at, $booking_id, $id]);
    } else {
      $upd = db()->prepare("
        UPDATE sms_schedule SET phone = ?, message = ?, scheduled_at = ?
        WHERE id = ? AND status = 'pending'
      ");
      $upd->execute([$phone, $message, $scheduled_at, $id]);
    }
    
    json_exit(['ok'=>true, 'msg'=>'SMS шинэчлэгдлээ']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   DELETE SMS SCHEDULE
   ========================= */
if ($action === 'delete_sms' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    
    if (!$id) {
      json_exit(['ok'=>false, 'msg'=>'ID заавал шаардлагатай'], 422);
    }
    
    // Only delete pending SMS
    $del = db()->prepare("DELETE FROM sms_schedule WHERE id = ? AND status = 'pending'");
    $del->execute([$id]);
    
    if ($del->rowCount() > 0) {
      json_exit(['ok'=>true, 'msg'=>'SMS устгагдлаа']);
    } else {
      json_exit(['ok'=>false, 'msg'=>'Устгах боломжгүй (илгээсэн эсвэл олдсонгүй)'], 422);
    }
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   SAVE SMS TEMPLATE (settings.json)
   ========================= */
if ($action === 'save_sms_template' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = trim($in['type'] ?? ''); // confirmation|reminder|aftercare
    $text = trim($in['text'] ?? '');
    $allowed = [
      'confirmation' => 'sms_confirmation_template',
      'reminder' => 'sms_reminder_template',
      'aftercare' => 'sms_aftercare_template'
    ];
    if (!isset($allowed[$type])) {
      json_exit(['ok'=>false, 'msg'=>'Template type буруу'], 422);
    }
    if ($text === '') {
      json_exit(['ok'=>false, 'msg'=>'Message хоосон байна'], 422);
    }

    $settingsPath = __DIR__ . '/../db/settings.json';
    $data = [];
    if (file_exists($settingsPath)) {
      $data = json_decode(@file_get_contents($settingsPath), true);
      if (!is_array($data)) $data = [];
    }
    $data[$allowed[$type]] = $text;

    // Pretty-print JSON for readability
    $saved = @file_put_contents($settingsPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($saved === false) {
      json_exit(['ok'=>false, 'msg'=>'settings.json хадгалах үед алдаа гарлаа'], 500);
    }

    json_exit(['ok'=>true, 'msg'=>'Загвар хадгалагдлаа']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   SEND SMS NOW (immediate)
   ========================= */
if ($action === 'send_sms_now' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin', 'reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    if (!$id) {
      json_exit(['ok'=>false, 'msg'=>'ID заавал шаардлагатай'], 422);
    }
    $st = db()->prepare("SELECT * FROM sms_schedule WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      json_exit(['ok'=>false, 'msg'=>'Олдсонгүй эсвэл аль хэдийн илгээгдсэн'], 404);
    }
    // Send immediately
    $res = sendSMS($row['phone'], $row['message'], $row['booking_id']);
    $status = ($res['ok'] ?? false) ? 'sent' : 'failed';
    $upd = db()->prepare("UPDATE sms_schedule SET status = ?, sent_at = NOW() WHERE id = ?");
    $upd->execute([$status, $id]);
    json_exit(['ok'=>($status==='sent'), 'msg'=> $status==='sent' ? 'SMS илгээгдлээ' : ('Илгээхэд алдаа: '.($res['error'] ?? 'Unknown')) ]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   SEARCH BOOKINGS BY PHONE
   ========================= */
if ($action === 'patient_summary' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    require_role(['admin', 'reception']);
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) json_exit(['ok' => false, 'msg' => 'Утас байхгүй байна'], 400);

    $normalizedPhone = preg_replace('/\D+/', '', $phone);
    
    // Get visit count and last visit
    $st = db()->prepare("
        SELECT COUNT(*) as visit_count, MAX(date) as last_visit
        FROM bookings 
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
    ");
    $st->execute([$normalizedPhone]);
    $stats = $st->fetch(PDO::FETCH_ASSOC);

    // Get last 5 services
    $stHist = db()->prepare("
        SELECT service_name, date, status
        FROM bookings 
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?
        ORDER BY date DESC, start_time DESC
        LIMIT 5
    ");
    $stHist->execute([$normalizedPhone]);
    $history = $stHist->fetchAll(PDO::FETCH_ASSOC);

    json_exit([
        'ok' => true,
        'visit_count' => (int)$stats['visit_count'],
        'last_visit' => $stats['last_visit'],
        'history' => $history
    ]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'search_bookings' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['admin','reception']);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = trim($in['phone'] ?? '');
    if ($phone === '') {
      json_exit(['ok'=>false,'msg'=>'Утас хоосон байна'],422);
    }
    $normalizedPhone = preg_replace('/\D+/', '', $phone);
    $sql = "SELECT id, patient_name, phone, date, start_time, clinic FROM bookings WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')','') = ?";
    $st = db()->prepare($sql . " ORDER BY date DESC, start_time DESC LIMIT 10");
    $st->execute([$normalizedPhone]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
      $st = db()->prepare("SELECT id, patient_name, phone, date, start_time, clinic FROM bookings WHERE phone LIKE ? ORDER BY date DESC, start_time DESC LIMIT 10");
      $st->execute(['%'.$phone.'%']);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    json_exit(['ok'=>true,'results'=>$rows]);
  } catch (Exception $e) {
    json_exit(['ok'=>false,'msg'=>$e->getMessage()],500);
  }
}

if ($action === 'update_patient_info') {
  require_role(['admin', 'reception']);
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = trim($in['phone'] ?? '');
    $birthday = trim($in['birthday'] ?? '') ?: null;

    if (!$phone) json_exit(['ok' => false, 'msg' => 'Утас байхгүй байна'], 400);

    $st = db()->prepare("UPDATE patients SET birthday = ? WHERE phone = ?");
    $st->execute([$birthday, $phone]);

    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'update_patient_notes') {
  require_role(['admin', 'reception']);
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = trim($in['phone'] ?? '');
    $notes = trim($in['notes'] ?? '');

    if (!$phone) json_exit(['ok' => false, 'msg' => 'Утас байхгүй байна'], 400);

    $st = db()->prepare("UPDATE patients SET notes = ? WHERE phone = ?");
    $st->execute([$notes, $phone]);

    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}
if ($action === 'upload_media') {
  require_role(['admin', 'reception', 'doctor']);
  try {
    $phone = $_POST['phone'] ?? '';
    $booking_id = !empty($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
    $media_type = $_POST['media_type'] ?? 'general';
    $notes = $_POST['notes'] ?? '';

    if (!$phone) json_exit(['ok' => false, 'msg' => 'Утас байхгүй байна'], 400);

    // Get patient_id from phone
    $st = db()->prepare("SELECT id FROM patients WHERE phone = ?");
    $st->execute([$phone]);
    $patient_id = $st->fetchColumn();

    if (!$patient_id) {
       // Search in patients again or try harder
       $st = db()->prepare("SELECT id FROM patients WHERE phone = ? LIMIT 1");
       $st->execute([$phone]);
       $patient_id = $st->fetchColumn();
    }

    if (!$patient_id) {
       json_exit(['ok' => false, 'msg' => 'Өвчтөний мэдээлэл олдсонгүй'], 404);
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      json_exit(['ok' => false, 'msg' => 'Файл илгээхэд алдаа гарлаа'], 400);
    }

    // Validation: Type & Size
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
      json_exit(['ok' => false, 'msg' => 'Зөвхөн зураг оруулах боломжтой (jpg, png, webp)'], 400);
    }
    
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
      json_exit(['ok' => false, 'msg' => 'Файлын хэмжээ хэтэрхий том байна (Max: 5MB)'], 400);
    }

    $filename = "media_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
    $target_dir = __DIR__ . "/uploads/media/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    $target_path = $target_dir . $filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
      json_exit(['ok' => false, 'msg' => 'Файлыг хадгалахад алдаа гарлаа'], 500);
    }

    $st = db()->prepare("INSERT INTO patient_media (patient_id, booking_id, file_path, media_type, notes) VALUES (?, ?, ?, ?, ?)");
    $st->execute([$patient_id, $booking_id, "uploads/media/" . $filename, $media_type, $notes]);

    json_exit(['ok' => true, 'path' => "uploads/media/" . $filename]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'get_patient_media') {
  require_role(['admin', 'reception', 'doctor']);
  try {
    $phone = $_GET['phone'] ?? '';
    if (!$phone) json_exit(['ok' => false, 'msg' => 'Утас байхгүй байна'], 400);

    $st = db()->prepare("
      SELECT pm.*, b.date as booking_date 
      FROM patient_media pm
      JOIN patients p ON p.id = pm.patient_id
      LEFT JOIN bookings b ON b.id = pm.booking_id
      WHERE p.phone = ?
      ORDER BY pm.created_at DESC
    ");
    $st->execute([$phone]);
    json_exit(['ok' => true, 'data' => $st->fetchAll()]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'delete_media') {
  require_role(['admin']); // Only admin for deletion
  try {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_exit(['ok' => false, 'msg' => 'ID байхгүй байна'], 400);

    $st = db()->prepare("SELECT file_path FROM patient_media WHERE id = ?");
    $st->execute([$id]);
    $path = $st->fetchColumn();

    if ($path) {
      $full_path = __DIR__ . "/" . $path;
      if (file_exists($full_path)) @unlink($full_path);
      
      $st = db()->prepare("DELETE FROM patient_media WHERE id = ?");
      $st->execute([$id]);
    }
    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'get_inventory') {
  require_role(['admin', 'reception', 'doctor']);
  try {
    $st = db()->prepare("SELECT * FROM inventory WHERE is_active = 1 ORDER BY name ASC");
    $st->execute();
    json_exit(['ok' => true, 'data' => $st->fetchAll()]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'save_inventory') {
  require_role(['admin']);
  try {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? 'Material');
    $unit = trim($_POST['unit'] ?? '');
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $stock_quantity = (float)($_POST['stock_quantity'] ?? 0);

    if (!$name) json_exit(['ok' => false, 'msg' => 'Нэр заавал шаардлагатай'], 400);

    if ($id) {
      $st = db()->prepare("UPDATE inventory SET name=?, category=?, unit=?, unit_price=?, stock_quantity=? WHERE id=?");
      $st->execute([$name, $category, $unit, $unit_price, $stock_quantity, $id]);
    } else {
      $st = db()->prepare("INSERT INTO inventory (name, category, unit, unit_price, stock_quantity) VALUES (?, ?, ?, ?, ?)");
      $st->execute([$name, $category, $unit, $unit_price, $stock_quantity]);
    }
    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'update_stock') {
  require_role(['admin', 'doctor', 'reception']);
  try {
    $id = (int)($_POST['id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);

    if (!$id) json_exit(['ok' => false, 'msg' => 'ID байхгүй байна'], 400);

    $st = db()->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
    $st->execute([$quantity, $id]);
    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}

if ($action === 'update_inventory') {
  require_role(['admin']);
  try {
    $id = (int)($_POST['id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if (!$id) json_exit(['ok' => false, 'msg' => 'ID байхгүй байна'], 400);

    $st = db()->prepare("UPDATE inventory SET is_active = ? WHERE id = ?");
    $st->execute([$is_active, $id]);
    json_exit(['ok' => true]);
  } catch (Exception $e) {
    json_exit(['ok' => false, 'msg' => $e->getMessage()], 500);
  }
}



/* =========================
   9) Fallback
   ========================= */
json_exit(['ok'=>false,'msg'=>'⚠️ Алдаа: үл танигдах action.'],404);
