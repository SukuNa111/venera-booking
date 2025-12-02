<?php
require_once 'config.php';

$departments = [
    'general_surgery' => 'Ерөнхий мэс засал',
    'nose_surgery' => 'Хамар эмнэл',
    'oral_surgery' => 'Амны мэс засал',
    'hair_clinic' => 'Үс сэргээлэх',
    'non_surgical' => 'Мэс засалгүй'
];

try {
    foreach ($departments as $code => $name) {
        db()->exec("INSERT INTO departments (code, name) VALUES ('$code', '$name') ON DUPLICATE KEY UPDATE name='$name'");
    }
    echo "✅ Тасагууд амжилттай нэмэгдлээ\n";
} catch (Exception $e) {
    echo "❌ Алдаа: " . $e->getMessage() . "\n";
}
?>
