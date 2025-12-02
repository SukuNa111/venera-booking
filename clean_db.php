<?php
require 'config.php';

try {
    db()->exec("DELETE FROM users WHERE role = 'doctor'");
    db()->exec("DELETE FROM doctors");
    db()->exec("DELETE FROM working_hours");
    echo "✅ Database сэлгэгдэв: doctors, users (doctor role), working_hours хасагдлаа.\n";
} catch (Exception $e) {
    echo "❌ Алдаа: " . $e->getMessage();
}
?>
