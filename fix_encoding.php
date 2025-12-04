<?php
header('Content-Type: text/html; charset=utf-8');
// Fix encoding - recreate database with proper UTF8

echo "<h2>PostgreSQL Encoding Fix</h2>\n";

try {
    // Connect to postgres database (not hospital_db)
    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=postgres',
        'postgres',
        '1234',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "<p>Connected to PostgreSQL</p>\n";
    
    // Terminate other connections to hospital_db
    $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'hospital_db' AND pid <> pg_backend_pid()");
    echo "<p>Terminated other connections</p>\n";
    
    // Drop and recreate database
    $pdo->exec("DROP DATABASE IF EXISTS hospital_db");
    echo "<p>Dropped old database</p>\n";
    
    $pdo->exec("CREATE DATABASE hospital_db ENCODING 'UTF8'");
    echo "<p>Created new database with UTF8</p>\n";
    
    // Connect to new database
    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=hospital_db',
        'postgres',
        '1234',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("SET NAMES 'UTF8'");
    
    // Read and execute schema
    $schema = file_get_contents(__DIR__ . '/db/postgresql_schema.sql');
    $pdo->exec($schema);
    echo "<p>Schema imported successfully</p>\n";
    
    // Verify data
    echo "<h3>Doctors:</h3><ul>\n";
    foreach ($pdo->query("SELECT name, clinic FROM doctors") as $row) {
        echo "<li>" . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . " - " . $row['clinic'] . "</li>\n";
    }
    echo "</ul>\n";
    
    echo "<h3>Clinics:</h3><ul>\n";
    foreach ($pdo->query("SELECT code, name FROM clinics") as $row) {
        echo "<li>" . $row['code'] . ": " . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . "</li>\n";
    }
    echo "</ul>\n";
    
    echo "<p style='color:green;font-size:20px;'><b>Done! Mongol encoding fixed!</b></p>";
    echo "<p><a href='public/index.php'>Go to Homepage</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'><b>Error:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
}
