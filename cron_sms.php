<?php
/**
 * SMS Cron Job - Сануулга болон After Care SMS илгээх
 * 
 * Энэ файлыг cron-оор 5-10 минут тутамд ажиллуулна:
 * 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/booking/cron_sms.php
 * 
 * Windows Task Scheduler дээр:
 * - Program: php.exe
 * - Arguments: C:\wamp64\www\booking\cron_sms.php
 * - Trigger: Every 5 minutes
 */

require __DIR__ . '/config.php';

$now = date('Y-m-d H:i:s');
echo "🕐 SMS Cron started at: $now\n";

// Get pending SMS that should be sent now
$st = db()->prepare("
    SELECT id, booking_id, phone, message, type 
    FROM sms_schedule 
    WHERE status = 'pending' 
      AND scheduled_at <= NOW()
    ORDER BY scheduled_at ASC
    LIMIT 20
");
$st->execute();
$scheduled = $st->fetchAll(PDO::FETCH_ASSOC);

echo "📋 Found " . count($scheduled) . " SMS to send\n";

foreach ($scheduled as $sms) {
    echo "📤 Sending SMS #{$sms['id']} to {$sms['phone']}...\n";
    
    try {
        $result = sendSMS($sms['phone'], $sms['message'], $sms['booking_id']);
        
        if ($result['ok']) {
            // Mark as sent
            $stUpdate = db()->prepare("UPDATE sms_schedule SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $stUpdate->execute([$sms['id']]);
            echo "   ✅ Sent successfully\n";
        } else {
            // Mark as failed
            $stUpdate = db()->prepare("UPDATE sms_schedule SET status = 'failed' WHERE id = ?");
            $stUpdate->execute([$sms['id']]);
            echo "   ❌ Failed: " . ($result['error'] ?? 'Unknown') . "\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
    }
    
    // Small delay between SMS
    usleep(500000); // 0.5 second
}

echo "✅ SMS Cron completed\n";
