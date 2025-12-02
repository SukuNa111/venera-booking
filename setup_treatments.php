<?php
require __DIR__ . '/config.php';

$pdo = db();

// 1. Treatments table - Эмчилгээний төрлүүд
$pdo->exec("
CREATE TABLE IF NOT EXISTS treatments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Эмчилгээний нэр',
    sessions INT DEFAULT 1 COMMENT 'Нийт үзлэгийн тоо',
    interval_days INT DEFAULT 30 COMMENT 'Үзлэг хоорондын хоног',
    aftercare_days INT DEFAULT 0 COMMENT 'After care сануулга (хоног дараа)',
    aftercare_message VARCHAR(500) DEFAULT NULL COMMENT 'After care мессеж',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Эмчилгээний төрлүүд'
");
echo "✅ treatments table created\n";

// 2. SMS Schedule table - Төлөвлөсөн SMS
$pdo->exec("
CREATE TABLE IF NOT EXISTS sms_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    scheduled_at DATETIME NOT NULL COMMENT 'Илгээх огноо цаг',
    type ENUM('reminder', 'aftercare', 'followup') DEFAULT 'reminder' COMMENT 'SMS төрөл',
    status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheduled (scheduled_at, status),
    INDEX idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Төлөвлөсөн SMS'
");
echo "✅ sms_schedule table created\n";

// 3. Add treatment_id to bookings table
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN treatment_id INT DEFAULT NULL AFTER service_name");
    echo "✅ treatment_id column added to bookings\n";
} catch (Exception $e) {
    echo "ℹ️ treatment_id column already exists\n";
}

// 4. Add session_number to bookings
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN session_number INT DEFAULT 1 AFTER treatment_id");
    echo "✅ session_number column added to bookings\n";
} catch (Exception $e) {
    echo "ℹ️ session_number column already exists\n";
}

// 5. Insert sample treatments
$treatments = [
    ['Шүдний цэвэрлэгээ', 1, 180, 180, 'Sain baina uu! Shudnii tseverlegee hiilgehed 6 sar bolloo. Dахин tsag avna uu.'],
    ['Суулгац эмчилгээ', 3, 14, 90, 'Sain baina uu! Suulgats emchilgeenii daraa 3 sar bolloo. Shalgalt hiilgene uu.'],
    ['Сувгийн эмчилгээ', 2, 7, 30, 'Sain baina uu! Suvgiin emchilgeenii shalgalt hiilgeh tsag bolloo.'],
    ['Ердийн үзлэг', 1, 0, 365, 'Sain baina uu! Жилийн shudnii uzleg hiilgeh tsag bolloo.'],
    ['Гажиг засал', 12, 30, 0, NULL],
];

$stIns = $pdo->prepare("INSERT IGNORE INTO treatments (name, sessions, interval_days, aftercare_days, aftercare_message) VALUES (?, ?, ?, ?, ?)");
foreach ($treatments as $t) {
    $stIns->execute($t);
}
echo "✅ Sample treatments inserted\n";

echo "\n🎉 Database setup complete!\n";
