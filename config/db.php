<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
/*
 * Single database connection used by every page.
 * Exceptions are enabled so query mistakes surface immediately
 * during development instead of failing silently.
 */

$host = 'localhost';
$database = 'freshtrack_db';
$username = 'root';
$password = '';

$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    exit('Database connection failed. Please start XAMPP MySQL.');
}