<?php

/*
 * Endpoint ni dipanggil berkala (polling) dari sidebar/topbar supaya
 * notifikasi baru (chime + toast kecil) boleh muncul tanpa reload page.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Pastikan status terkini walaupun user duduk lama pada satu page je.
refreshInventoryStatus($pdo);

$latestStatement = $pdo->prepare(
    "SELECT title, message
     FROM notifications
     WHERE user_id = ?
       AND is_read = 0
     ORDER BY created_at DESC
     LIMIT 1"
);

$latestStatement->execute([$userId]);
$latest = $latestStatement->fetch();

echo json_encode([
    'unread' => getUnreadCount($pdo, $userId),
    'title' => $latest ? $latest['title'] : '',
    'message' => $latest ? $latest['message'] : ''
]);