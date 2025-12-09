<?php
// backend/database.php
// Edit DB_NAME to the exact DB you created in phpMyAdmin.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'qr application db'); // <-- CHANGE THIS to your actual DB name
define('DB_USER', 'root');
define('DB_PASS', 'TheGoldenDragons'); // XAMPP default: empty password

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}
?>