<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="mn">
<head>
    <meta charset="UTF-8">
    <title>Database Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d4ff; }
        h2 { color: #ffd700; margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; background: #16213e; }
        th, td { border: 1px solid #0f3460; padding: 10px; text-align: left; }
        th { background: #0f3460; color: #00d4ff; }
        tr:hover { background: #1f4068; }
        .count { color: #00ff88; font-weight: bold; }
        a { color: #00d4ff; margin-right: 15px; }
    </style>
</head>
<body>
    <h1>PostgreSQL Database Viewer</h1>
    <p>
        <a href="view_data.php">Refresh</a>
        <a href="public/index.php">Calendar</a>
    </p>

<?php
try {
    // Clinics
    echo "<h2>Clinics (Emneleg)</h2>";
    $rows = db()->query("SELECT * FROM clinics ORDER BY sort_order")->fetchAll();
    echo "<p class='count'>Total: " . count($rows) . "</p>";
    if ($rows) {
        echo "<table><tr><th>ID</th><th>Code</th><th>Name</th><th>Color</th><th>Active</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['code']}</td><td>{$r['name']}</td><td style='background:{$r['theme_color']}'>{$r['theme_color']}</td><td>{$r['active']}</td></tr>";
        }
        echo "</table>";
    }

    // Doctors
    echo "<h2>Doctors (Emch nar)</h2>";
    $rows = db()->query("SELECT * FROM doctors ORDER BY clinic, id")->fetchAll();
    echo "<p class='count'>Total: " . count($rows) . "</p>";
    if ($rows) {
        echo "<table><tr><th>ID</th><th>Name</th><th>Specialty</th><th>Clinic</th><th>Color</th><th>Active</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['specialty']}</td><td>{$r['clinic']}</td><td style='background:{$r['color']}'>{$r['color']}</td><td>{$r['active']}</td></tr>";
        }
        echo "</table>";
    }

    // Users
    echo "<h2>Users (Hereglegchid)</h2>";
    $rows = db()->query("SELECT id, name, phone, role, clinic_id FROM users ORDER BY id")->fetchAll();
    echo "<p class='count'>Total: " . count($rows) . "</p>";
    if ($rows) {
        echo "<table><tr><th>ID</th><th>Name</th><th>Phone</th><th>Role</th><th>Clinic</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['phone']}</td><td>{$r['role']}</td><td>{$r['clinic_id']}</td></tr>";
        }
        echo "</table>";
    }

    // Treatments
    echo "<h2>Treatments (Emchilgee)</h2>";
    $rows = db()->query("SELECT * FROM treatments ORDER BY id")->fetchAll();
    echo "<p class='count'>Total: " . count($rows) . "</p>";
    if ($rows) {
        echo "<table><tr><th>ID</th><th>Name</th><th>Sessions</th><th>Interval Days</th><th>Aftercare Days</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['sessions']}</td><td>{$r['interval_days']}</td><td>{$r['aftercare_days']}</td></tr>";
        }
        echo "</table>";
    }

    // Bookings
    echo "<h2>Bookings (Zahialga)</h2>";
    $rows = db()->query("SELECT b.*, d.name as doctor_name FROM bookings b LEFT JOIN doctors d ON b.doctor_id = d.id ORDER BY b.date DESC, b.start_time LIMIT 50")->fetchAll();
    echo "<p class='count'>Total: " . count($rows) . "</p>";
    if ($rows) {
        echo "<table><tr><th>ID</th><th>Date</th><th>Time</th><th>Patient</th><th>Phone</th><th>Doctor</th><th>Clinic</th><th>Status</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['date']}</td><td>{$r['start_time']}</td><td>{$r['patient_name']}</td><td>{$r['phone']}</td><td>{$r['doctor_name']}</td><td>{$r['clinic']}</td><td>{$r['status']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No bookings yet</p>";
    }

    // Working Hours
    echo "<h2>Working Hours (Ajliin tsag)</h2>";
    $rows = db()->query("SELECT w.*, d.name as doctor_name FROM working_hours w LEFT JOIN doctors d ON w.doctor_id = d.id ORDER BY d.id, w.day_of_week LIMIT 30")->fetchAll();
    echo "<p class='count'>Showing first 30</p>";
    if ($rows) {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        echo "<table><tr><th>Doctor</th><th>Day</th><th>Start</th><th>End</th><th>Available</th></tr>";
        foreach ($rows as $r) {
            $day = $days[$r['day_of_week']] ?? $r['day_of_week'];
            $avail = $r['is_available'] ? 'Yes' : 'No';
            echo "<tr><td>{$r['doctor_name']}</td><td>{$day}</td><td>{$r['start_time']}</td><td>{$r['end_time']}</td><td>{$avail}</td></tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>
