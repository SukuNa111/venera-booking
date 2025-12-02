<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

// === CLEAN OUTPUT BUFFERING (after session started) ===
if (ob_get_level()) {
    ob_end_clean();
}

require_login();

$action = $_GET['action'] ?? '';

/* JSON Exit Helper */
function json_exit($arr, $code = 200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

// sendSMS is provided by config.php (uses Twilio if configured, otherwise logs to sms_log)

/* =========================
   GET DOCTOR'S WORKING HOURS (SINGLE DOCTOR)
   ========================= */
if ($action === 'doctor_working_hours' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $doctor_id = (int)($_GET['id'] ?? 0);
    if (!$doctor_id) json_exit(['ok'=>false, 'msg'=>'Doctor ID required'], 400);
    
    $st = db()->prepare("
      SELECT day_of_week, start_time, end_time, is_available
      FROM working_hours
      WHERE doctor_id=? 
      ORDER BY day_of_week
    ");
    $st->execute([$doctor_id]);
    $hours = $st->fetchAll();
    
    json_exit(['ok'=>true, 'hours'=>$hours]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   UPDATE MY HOURS (DOCTOR SELF)
   ========================= */
if ($action === 'update_my_hours' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    require_role(['doctor','admin']);
    $userId = (int)($_SESSION['uid'] ?? 0);
    if (!$userId) json_exit(['ok'=>false,'msg'=>'Нэвтэрхий байхгүй байна.'],401);

    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $hours = $payload['hours'] ?? [];

    if (!is_array($hours) || count($hours) !== 7) {
      json_exit(['ok'=>false,'msg'=>'7 хоногийн мэдээлэл дамжуулна уу.'],422);
    }

    $del = db()->prepare("DELETE FROM working_hours WHERE doctor_id=?");
    $del->execute([$userId]);

    $ins = db()->prepare("
      INSERT INTO working_hours 
        (doctor_id, day_of_week, start_time, end_time, is_available) 
      VALUES (?,?,?,?,?)
    ");
    foreach ($hours as $row) {
      $dow = isset($row['day_of_week']) ? (int)$row['day_of_week'] : null; // 0–6
      $start = $row['start_time'] ?? null;
      $end   = $row['end_time'] ?? null;
      $avail = isset($row['is_available']) ? (int)$row['is_available'] : 0;

      if ($dow === null || $dow < 0 || $dow > 6) continue;
      if ($avail) {
        if (!$start || !$end) {
          $start = '09:00';
          $end   = '18:00';
        }
      } else {
        $start = $start ?: '09:00';
        $end   = $end   ?: '18:00';
      }
      $ins->execute([$userId, $dow, $start, $end, $avail ? 1 : 0]);
    }

    json_exit(['ok'=>true,'msg'=>'Ажиллах цаг шинэчлэгдлээ.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false,'msg'=>$e->getMessage()],500);
  }
}

/* =========================
   1) Эмч нар (clinic-аар)
   ========================= */
if ($action === 'doctors' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    // If the current user is a doctor, force the clinic filter to their assigned clinic.
    try {
      $currentUser = current_user();
      $role = $currentUser['role'] ?? '';
      if (in_array($role, ['doctor','reception'])) {
        $clinic = $currentUser['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // If current_user() is unavailable, ignore and use the provided clinic
    }
    $st = db()->prepare("
      SELECT id, name,
             COALESCE(NULLIF(color,''),'#0d6efd') AS color,
             clinic,
             department,
             COALESCE(show_in_calendar, 1) as show_in_calendar
      FROM doctors
      WHERE active=1 AND clinic=?
      ORDER BY COALESCE(sort_order,9999), name
    ");
    $st->execute([$clinic]);
    $doctors = $st->fetchAll();
    
    // working_hours оруулах (ажлын хүснэгт байхгүй бол doctor_hours хүснэгтээс fallback)
    foreach ($doctors as &$doc) {
      try {
        $wh = db()->prepare("
          SELECT day_of_week, start_time, end_time, is_available 
          FROM working_hours 
          WHERE doctor_id=? 
          ORDER BY day_of_week
        ");
        $wh->execute([$doc['id']]);
        $rows = $wh->fetchAll();
        // Хэрэв working_hours хоосон бол doctor_hours-оос хувиргаж авна
        if (!$rows) {
          try {
            $oh = db()->prepare("
              SELECT weekday, time_start, time_end
              FROM doctor_hours
              WHERE doctor_id=?
              ORDER BY weekday
            ");
            $oh->execute([$doc['id']]);
            $oldRows = $oh->fetchAll();
            foreach ($oldRows as $or) {
              $weekday = (int)$or['weekday'];
              $rows[] = [
                'day_of_week' => ($weekday == 7 ? 0 : $weekday),
                'start_time' => $or['time_start'],
                'end_time'   => $or['time_end'],
                'is_available' => 1
              ];
            }
          } catch (Exception $ee) {
            // ignore
          }
        }
        $doc['working_hours'] = $rows;
      } catch (Exception $e) {
        $doc['working_hours'] = [];
      }
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
    // Enforce clinic restriction for doctor role
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      if (in_array($role, ['doctor','reception'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $date   = $_GET['date']   ?? date('Y-m-d');
    
    // If clinic is 'all', fetch from all clinics. Otherwise, filter by clinic
    if ($clinic === 'all') {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE b.date=? ORDER BY b.start_time");
      $st->execute([$date]);
    } else {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE b.clinic=? AND b.date=? ORDER BY b.start_time");
      $st->execute([$clinic, $date]);
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
    
    // Эмч жагсаалт (if clinic_id column exists use it, otherwise use clinic)
    // We'll attempt both fields safely in one query by using OR and binding twice.
    $st = db()->prepare("SELECT id, name, COALESCE(NULLIF(color,''),'#0d6efd') AS color, department FROM doctors WHERE (clinic = ? OR clinic_id = ?) AND active=1 ORDER BY COALESCE(sort_order,9999), name");
    $st->execute([$clinicParam, $clinicParam]);
    $doctors = $st->fetchAll(PDO::FETCH_ASSOC);

    // Эмч бүрийн ажиллах цаг хавсаргана – working_hours хүснэгтээс
    foreach ($doctors as &$doc) {
        try {
            $h = db()->prepare("
                SELECT day_of_week, start_time, end_time, is_available
                FROM working_hours
                WHERE doctor_id = ?
                ORDER BY day_of_week
            ");
            $h->execute([$doc['id']]);
            $rows = $h->fetchAll(PDO::FETCH_ASSOC);
            // fallback doctor_hours хүснэгтэд
            if (!$rows) {
                try {
                    $oh = db()->prepare("
                        SELECT weekday, time_start, time_end
                        FROM doctor_hours
                        WHERE doctor_id = ?
                        ORDER BY weekday
                    ");
                    $oh->execute([$doc['id']]);
                    $oldRows = $oh->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($oldRows as $or) {
                        $weekday = (int)$or['weekday'];
                        $rows[] = [
                            'day_of_week' => ($weekday == 7 ? 0 : $weekday),
                            'start_time' => $or['time_start'],
                            'end_time'   => $or['time_end'],
                            'is_available' => 1
                        ];
                    }
                } catch (Exception $ee) {
                    // ignore
                }
            }
            $doc['working_hours'] = $rows;
        } catch (Exception $e) {
            $doc['working_hours'] = [];
        }
    }

    json_exit(["ok" => true, "data" => $doctors]);
}

/* =========================
   3) Долоо хоног
   ========================= */
if ($action === 'bookings_week' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    // Enforce doctor-specific clinic restriction
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      if (in_array($role, ['doctor','reception'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $start  = $_GET['start']  ?? date('Y-m-d');
    $end    = $_GET['end']    ?? date('Y-m-d');
    
    // If clinic is 'all', fetch from all clinics. Otherwise, filter by clinic
    if ($clinic === 'all') {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE b.date BETWEEN ? AND ? ORDER BY b.date, b.start_time");
      $st->execute([$start, $end]);
    } else {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE b.clinic=? AND b.date BETWEEN ? AND ? ORDER BY b.date, b.start_time");
      $st->execute([$clinic, $start, $end]);
    }
    json_exit(['ok'=>true,'data'=>$st->fetchAll()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   4) Сар
   ========================= */
if ($action === 'bookings_month' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $clinic = $_GET['clinic'] ?? 'venera';
    // Enforce doctor-specific clinic restriction
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      if (in_array($role, ['doctor','reception'])) {
        $clinic = $cu['clinic_id'] ?? $clinic;
      }
    } catch (Exception $ex) {
      // ignore
    }
    $month  = $_GET['month']  ?? date('Y-m');
    
    // If clinic is 'all', fetch from all clinics. Otherwise, filter by clinic
    $cu = current_user();
    $role = $cu['role'] ?? '';
    if ($clinic === 'all') {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE DATE_FORMAT(b.date, '%Y-%m')=? ORDER BY b.date, b.start_time");
      $st->execute([$month]);
    } else {
      $st = db()->prepare("SELECT b.*, d.name AS doctor_name, COALESCE(NULLIF(d.color,''),'#0d6efd') AS doctor_color, d.department FROM bookings b LEFT JOIN doctors d ON d.id=b.doctor_id WHERE b.clinic=? AND DATE_FORMAT(b.date, '%Y-%m')=? ORDER BY b.date, b.start_time");
      $st->execute([$clinic, $month]);
    }
    json_exit(['ok'=>true,'data'=>$st->fetchAll()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   5) Шинэ захиалга үүсгэх
   ========================= */
if ($action === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $doctor_id   = (int)($in['doctor_id'] ?? 0); // Сонголтоор
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

    if (!$clinic || !$date || !$start || !$end || $patient === '' || !$treatment_id) {
      json_exit(['ok'=>false, 'msg'=>'Талбар дутуу байна.'], 422);
    }
    
    // Get service_name from treatment if not provided
    if (empty($service_name) && $treatment_id > 0) {
      $stTreatName = db()->prepare("SELECT name FROM treatments WHERE id = ?");
      $stTreatName->execute([$treatment_id]);
      $service_name = $stTreatName->fetchColumn() ?: '';
    }

    // === ЭМЧИЙН АЖИЛЛАХ ЦАГ ШАЛГАХ ===
    if ($doctor_id > 0) {
      // PHP date('w'): 0=Ням, 1=Даваа ... 6=Бямба
      $dow = (int)date('w', strtotime($date));

      $stWh = db()->prepare("
        SELECT start_time, end_time, is_available
        FROM working_hours
        WHERE doctor_id = ? AND day_of_week = ?
        LIMIT 1
      ");
      $stWh->execute([$doctor_id, $dow]);
      $wh = $stWh->fetch(PDO::FETCH_ASSOC);

      if (!$wh || (int)$wh['is_available'] !== 1) {
        json_exit(['ok'=>false, 'msg'=>'Эмч энэ өдөр ажиллах хуваарьгүй байна.'], 422);
      }

      if ($start < $wh['start_time'] || $end > $wh['end_time']) {
        json_exit(['ok'=>false, 'msg'=>'Энэ цаг эмчийн ажиллах хуваарийн гадна байна.'], 422);
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
    if ($doctor_id > 0) {
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
    // Get department from doctor if selected
    $bookingDept = null;
    if ($doctor_id > 0) {
      try {
        $deptStmt = db()->prepare("SELECT department FROM doctors WHERE id=?");
        $deptStmt->execute([$doctor_id]);
        $bookingDept = $deptStmt->fetchColumn();
      } catch (Exception $e) {
        // ignore
      }
    }

    $ins = db()->prepare("
      INSERT INTO bookings
        (doctor_id, clinic, date, start_time, end_time,
         patient_name, gender, visit_count, phone, note, service_name, status, department, treatment_id, session_number, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    
    try {
      $ins->execute([
        $doctor_id > 0 ? $doctor_id : null, $clinic, $date, $start, $end,
        $patient, $gender, $visit_count, $phone, $note, $service_name, $status, $bookingDept,
        $treatment_id > 0 ? $treatment_id : null, $session_number
      ]);
    } catch (PDOException $dbEx) {
      error_log("❌ Booking INSERT failed: " . $dbEx->getMessage());
      json_exit(['ok'=>false, 'msg'=>'Booking үүсгэхэд алдаа: ' . $dbEx->getMessage()], 500);
    }

    $newId = db()->lastInsertId();

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
      if ($doctor_id) {
        try {
          $stDocNm = db()->prepare("SELECT name FROM doctors WHERE id=?");
          $stDocNm->execute([$doctor_id]);
          $doctorName = $stDocNm->fetchColumn() ?: '';
        } catch (Exception $e) {
          $doctorName = '';
        }
      }
      // SMS message in Latin (works on all operators)
      $msg = "Sain baina uu, tany zahialga amjilttai burtgegdlee.";
      if ($clinicName) {
        $msg .= " Emnelеg: {$clinicName}.";
      }
      if ($doctorName) {
        $msg .= " Emch: {$doctorName}.";
      }
      $msg .= " Ognoo: {$date}, tsag: {$start}-{$end}.";
      // Send SMS (if configured) – errors are ignored so booking still succeeds
      try {
        sendSMS($phone, $msg, $newId);
        
        // Schedule reminder SMS for day before appointment
        $reminderDate = date('Y-m-d 10:00:00', strtotime($date . ' -1 day'));
        if (strtotime($reminderDate) > time()) {
          $reminderMsg = "Sain baina uu! Margaash {$date} ognoo {$start} tsagt {$clinicName} emnelegd tsag avsan baina. Hutsleh bol 70001234 ruu zalgarai.";
          $stSched = db()->prepare("INSERT INTO sms_schedule (booking_id, phone, message, scheduled_at, type) VALUES (?, ?, ?, ?, 'reminder')");
          $stSched->execute([$newId, $phone, $reminderMsg, $reminderDate]);
        }
        
        // Schedule aftercare SMS if treatment has aftercare
        if (!empty($treatment_id)) {
          $stTreat = db()->prepare("SELECT aftercare_days, aftercare_message FROM treatments WHERE id = ? AND aftercare_days > 0");
          $stTreat->execute([$treatment_id]);
          $treatment = $stTreat->fetch();
          if ($treatment && $treatment['aftercare_message']) {
            $aftercareDate = date('Y-m-d 10:00:00', strtotime($date . ' +' . $treatment['aftercare_days'] . ' days'));
            $stSched = db()->prepare("INSERT INTO sms_schedule (booking_id, phone, message, scheduled_at, type) VALUES (?, ?, ?, ?, 'aftercare')");
            $stSched->execute([$newId, $phone, $treatment['aftercare_message'], $aftercareDate]);
          }
        }
      } catch (Exception $smsEx) {
        // ignore SMS errors
      }
    }

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

    $doctor_id   = (int)($in['doctor_id'] ?? $old['doctor_id']);
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

    if ($service_name === '') {
      json_exit(['ok'=>false,'msg'=>'Үйлчилгээний нэр шаардлагатай.'],422);
    }

    // === ЭМЧИЙН АЖИЛЛАХ ЦАГ ШАЛГАХ (UPDATE) ===
    if ($doctor_id > 0) {
      $dow = (int)date('w', strtotime($date));

      $stWh = db()->prepare("
        SELECT start_time, end_time, is_available
        FROM working_hours
        WHERE doctor_id = ? AND day_of_week = ?
        LIMIT 1
      ");
      $stWh->execute([$doctor_id, $dow]);
      $wh = $stWh->fetch(PDO::FETCH_ASSOC);

      if (!$wh || (int)$wh['is_available'] !== 1) {
        json_exit(['ok'=>false, 'msg'=>'Эмч энэ өдөр ажиллах хуваарьгүй байна.'], 422);
      }

      if ($start < $wh['start_time'] || $end > $wh['end_time']) {
        json_exit(['ok'=>false, 'msg'=>'Энэ цаг эмчийн ажиллах хуваарийн гадна байна.'], 422);
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

    $q2 = db()->prepare("
      SELECT COUNT(*) FROM bookings
      WHERE doctor_id=? AND clinic=? AND date=? AND id<>?
        AND NOT (end_time<=? OR start_time>=?)
    ");
    $q2->execute([$doctor_id,$clinic,$date,$id,$start,$end]);
    if ($q2->fetchColumn() > 0) {
      json_exit(['ok'=>false,'msg'=>'Давхцал байна.'],409);
    }

    // Get department from doctor if selected
    $bookingDept = null;
    if ($doctor_id > 0) {
      try {
        $deptStmt = db()->prepare("SELECT department FROM doctors WHERE id=?");
        $deptStmt->execute([$doctor_id]);
        $bookingDept = $deptStmt->fetchColumn();
      } catch (Exception $e) {
        // ignore
      }
    }

    $u = db()->prepare("
      UPDATE bookings SET
        doctor_id=?, clinic=?, date=?, start_time=?, end_time=?,
        patient_name=?, gender=?, visit_count=?, phone=?, note=?, service_name=?, status=?, department=?
      WHERE id=?
    ");
    $u->execute([
      $doctor_id, $clinic, $date, $start, $end,
      $patient, $gender, $visit_count, $phone, $note, $service_name, $status, $bookingDept,
      $id
    ]);

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
    // Restrict doctor/reception users to their own clinic
    try {
      $cu = current_user();
      $role = $cu['role'] ?? '';
      if (in_array($role, ['doctor','reception'])) {
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
    $working_hours = $in['working_hours'] ?? [];
    
    if (!$name) json_exit(['ok'=>false,'msg'=>'Эмчийн нэр оруулна уу.'], 422);
    
    $ins = db()->prepare("INSERT INTO doctors (name, color, clinic, active) VALUES (?,?,?,1)");
    $ins->execute([$name, $color, $clinic]);
    $doctor_id = db()->lastInsertId();
    
    foreach ($working_hours as $wh) {
      $whIns = db()->prepare("
        INSERT INTO working_hours 
          (doctor_id, day_of_week, start_time, end_time, is_available) 
        VALUES (?,?,?,?,1)
      ");
      $whIns->execute([
        $doctor_id,
        $wh['day_of_week'],
        $wh['start_time'],
        $wh['end_time']
      ]);
    }
    
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
    $working_hours = $in['working_hours'] ?? [];
    
    if (!$id || !$name) json_exit(['ok'=>false,'msg'=>'ID эсвэл нэр алга.'], 422);
    
    $upd = db()->prepare("UPDATE doctors SET name=?, color=? WHERE id=?");
    $upd->execute([$name, $color, $id]);
    
    $del = db()->prepare("DELETE FROM working_hours WHERE doctor_id=?");
    $del->execute([$id]);
    
    foreach ($working_hours as $wh) {
      $whIns = db()->prepare("
        INSERT INTO working_hours 
          (doctor_id, day_of_week, start_time, end_time, is_available) 
        VALUES (?,?,?,?,1)
      ");
      $whIns->execute([
        $id,
        $wh['day_of_week'],
        $wh['start_time'],
        $wh['end_time']
      ]);
    }
    
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
    
    $del = db()->prepare("DELETE FROM doctors WHERE id=?");
    $del->execute([$id]);
    
    json_exit(['ok'=>true, 'msg'=>'Эмч амжилттай устгагдлаа.']);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   TREATMENTS - List all treatments
   ========================= */
if ($action === 'treatments' && $_SERVER['REQUEST_METHOD']==='GET') {
  try {
    $st = db()->prepare("SELECT * FROM treatments ORDER BY name");
    $st->execute();
    $treatments = $st->fetchAll();
    json_exit(['ok'=>true, 'data'=>$treatments]);
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
    $sessions = (int)($in['sessions'] ?? 1);
    $interval_days = (int)($in['interval_days'] ?? 0);
    $aftercare_days = (int)($in['aftercare_days'] ?? 0);
    $aftercare_message = trim($in['aftercare_message'] ?? '');
    
    if (!$name) {
      json_exit(['ok'=>false, 'msg'=>'Эмчилгээний нэр оруулна уу'], 422);
    }
    
    $ins = db()->prepare("
      INSERT INTO treatments (name, sessions, interval_days, aftercare_days, aftercare_message)
      VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$name, $sessions, $interval_days, $aftercare_days, $aftercare_message]);
    
    json_exit(['ok'=>true, 'msg'=>'Эмчилгээ нэмэгдлээ', 'id'=>db()->lastInsertId()]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}

/* =========================
   9) Fallback
   ========================= */
json_exit(['ok'=>false,'msg'=>'⚠️ Алдаа: үл танигдах action.'],404);
