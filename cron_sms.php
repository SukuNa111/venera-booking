<?php
/**
 * SMS Cron Job - Сануулга мессеж илгээх
 * 
 * Энэ файлыг cron-оор 5-10 минут тутамд ажиллуулна:
 * /5 * * * * php /opt/booking/cron_sms.php
 */

require __DIR__ . '/config.php';

// Local column existence helper (cached) to tolerate older schemas
if (!function_exists('column_exists')) {
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
}

$now = date('Y-m-d H:i:s');
echo "[" . date('Y-m-d H:i:s') . "] SMS Cron эхлүүлээ\n";

// === Сануулга мессеж (Reminder) ===
// Reminder Window: 50 minutes to 3 hours from NOW.
// Widening the window to ensure appointments are caught even if cron is slightly delayed.
$nowObj = new DateTime('now');
$windowStart = $nowObj->format('Y-m-d H:i:s'); // Catch everything from now onwards
$windowEnd = (new DateTime('now'))->add(new DateInterval('PT3H'))->format('Y-m-d H:i:s');

$hasPhone1 = column_exists('clinics', 'phone1');
$clinicPhoneCol = $hasPhone1 ? 'c.phone1' : "NULL";

$st = db()->prepare("
  SELECT 
    b.id as booking_id,
    b.patient_name,
    b.phone,
    b.date,
    b.start_time,
    b.end_time,
    b.clinic,
    d.name as doctor_name,
    {$clinicPhoneCol} as clinic_phone,
    c.name as clinic_name,
    (b.date::timestamp + b.start_time::interval) as start_ts
  FROM bookings b
  LEFT JOIN users d ON d.id = b.doctor_id AND d.role='doctor'
  LEFT JOIN clinics c ON c.code = b.clinic
  WHERE (b.date::timestamp + b.start_time::interval) BETWEEN ? AND ?
    AND (b.date::timestamp + b.start_time::interval) > NOW()
    AND b.status IN ('online', 'arrived', 'pending', 'paid')
    -- Check if we already sent a reminder for this booking today
    AND NOT EXISTS (
      SELECT 1 FROM sms_log 
      WHERE booking_id = b.id 
        AND (message LIKE '%uzleg%baina%' OR message LIKE '%sanulga%')
        AND created_at > (NOW() - INTERVAL '12 hours')
    )
  ORDER BY start_ts
");

$st->execute([$windowStart, $windowEnd]);
$reminders = $st->fetchAll(PDO::FETCH_ASSOC);

echo "📋 Маргаа сануулга SMS: " . count($reminders) . " ширхэг (Window: {$windowStart} -> {$windowEnd})\n";

/**
 * OLD REMINDER LOGIC - Commented out because reminders are now proactively 
 * scheduled in the 'sms_schedule' table for better visibility and control.
 * 
foreach ($reminders as $booking) {
  // ... (skipped)
}
*/

// === III. Төрсөн өдрийн мэндчилгээ (Birthday Greeting) ===
// Only run once per day (e.g. at 10:00 AM)
if (date('H:i') === '10:00') {
    echo "🎂 Төрсөн өдрийн шалгалт эхэллээ...\n";
    $stBday = db()->query("
        SELECT name, phone FROM patients 
        WHERE TO_CHAR(birthday, 'MM-DD') = TO_CHAR(CURRENT_DATE, 'MM-DD')
        AND NOT EXISTS (
            SELECT 1 FROM sms_log 
            WHERE phone = patients.phone 
            AND message LIKE '%torson odriin%'
            AND created_at > (NOW() - INTERVAL '24 hours')
        )
    ");
    $bdays = $stBday->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bdays as $p) {
        $msg = "Sain baina uu {$p['name']}! Venera-Dent emneleg tany torson odriin mendiig hurgeye! Tany emneleg.";
        // Try to load template
        $tst = db()->prepare("SELECT message FROM sms_templates WHERE type='birthday' LIMIT 1");
        $tst->execute();
        $t = $tst->fetchColumn();
        if ($t) $msg = str_replace('{clinic_name}', 'Venera-Dent', $t);

        echo "📤 Birthday for {$p['phone']}\n";
        sendSMS($p['phone'], $msg);
        usleep(800000);
    }
}

// === IV. 6-сарын дараах үзлэг (Follow-up) ===
if (date('H:i') === '11:00') {
    echo "📞 6-сарын дараах үзлэг шалгалт эхэллээ...\n";
    $stFollow = db()->query("
        SELECT p.name, p.phone, MAX(b.date) as last_visit
        FROM patients p
        JOIN bookings b ON p.phone = b.phone
        GROUP BY p.name, p.phone
        HAVING MAX(b.date) = CURRENT_DATE - INTERVAL '6 months'
        AND NOT EXISTS (
            SELECT 1 FROM sms_log 
            WHERE phone = p.phone 
            AND message LIKE '%6 sar%'
            AND created_at > (NOW() - INTERVAL '30 days')
        )
    ");
    $follows = $stFollow->fetchAll(PDO::FETCH_ASSOC);
    foreach ($follows as $p) {
        $msg = "Sain baina uu {$p['name']}! Tany suulchiin uzlegees hoish 6 sar bolloo. Ta urguulj emchilgee, shinjilgee hiilgeh bol lawlax utas: 70115090";
        // Try to load template
        $tst = db()->prepare("SELECT message FROM sms_templates WHERE type='followup' LIMIT 1");
        $tst->execute();
        $t = $tst->fetchColumn();
        if ($t) $msg = str_replace('{clinic_name}', 'Venera-Dent', $t);

        echo "📤 Follow-up for {$p['phone']}\n";
        sendSMS($p['phone'], $msg);
        usleep(800000);
    }
}

// === V. Төлөвлөсөн SMS (Manual / Queue) ===
// Some DBs use UTC; we filter in PHP with local timezone to avoid timezone mismatch.
$due = db()->prepare("SELECT * FROM sms_schedule WHERE status = 'pending' ORDER BY scheduled_at ASC LIMIT 500");
$due->execute();
$queueRaw = $due->fetchAll(PDO::FETCH_ASSOC);
$nowTs = time();
$earliest = $nowTs - 600; // 10 минутын өмнөхөөс доошгүй due
$staleCutoff = $nowTs - 86400; // 24 цагийн өмнөх pending-г цэвэрлэх

// Convert stored datetime to local timestamp safely
$toLocalTs = function($dtStr) {
  if (!$dtStr) return null;
  try {
    // If the string contains an offset (e.g. +08), DateTime constructor handles it and ignores the second param
    $dt = new DateTimeImmutable($dtStr, new DateTimeZone('Asia/Ulaanbaatar'));
    return $dt->getTimestamp();
  } catch (Exception $e) {
    $ts = strtotime($dtStr);
    return $ts === false ? null : $ts;
  }
};

// Mark older pending as failed to avoid backlog. 
// "Missing window" means it's older than our -10m threshold.
$stale = array_filter($queueRaw, function($r) use ($earliest, $toLocalTs) {
  $ts = $toLocalTs($r['scheduled_at'] ?? '');
  return $ts !== null && $ts < $earliest;
});
if ($stale) {
  $ids = array_column($stale, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $updStale = db()->prepare("UPDATE sms_schedule SET status='failed', sent_at=NOW(), error='Missed window (auto-fail)' WHERE id IN ($in)");
  $updStale->execute($ids);
}

// Due window: scheduled_at <= now AND >= now-10m
$queue = array_filter($queueRaw, function($r) use ($nowTs, $earliest, $toLocalTs) {
  $ts = $toLocalTs($r['scheduled_at'] ?? '');
  return $ts !== null && $ts <= $nowTs && $ts >= $earliest;
});

echo "📋 Төлөвлөсөн SMS илгээх: " . count($queue) . " / " . count($queueRaw) . " ширхэг (due/total), цонх: -10мин..одоо\n";

foreach ($queue as $row) {
  $msg = $row['message'] ?? '';
  $phone = $row['phone'] ?? '';
  $bookingId = $row['booking_id'] ?? null;
  echo "📤 Schedule #{$row['id']} -> {$phone} ({$row['scheduled_at']})\n";
  $res = sendSMS($phone, $msg, $bookingId);
  $status = ($res['ok'] ?? false) ? 'sent' : 'failed';
  $upd = db()->prepare("UPDATE sms_schedule SET status = ?, sent_at = NOW() WHERE id = ?");
  $upd->execute([$status, $row['id']]);
  usleep(800000); // 0.8 second delay
}

echo "[" . date('Y-m-d H:i:s') . "] SMS Cron дуусав\n";
