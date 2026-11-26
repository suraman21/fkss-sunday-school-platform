<?php

$host = "localhost";
$dbname = "felege_kidusan_test";
$username = "CHANGE_ME_USER";
$password = "CHANGE_ME_PASSWORD";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    die(json_encode([
        'success' => false,
        'code'    => 'DB_CONNECTION',
        'message' => 'Cannot connect to the database. Check the host, user, password and database name in backend/config/database.php.'
    ], JSON_UNESCAPED_UNICODE));
}