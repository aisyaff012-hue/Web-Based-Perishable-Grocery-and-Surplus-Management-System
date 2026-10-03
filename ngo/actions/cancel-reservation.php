<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('ngo');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../reservations.php');
    exit;
}

$ngoId = $_SESSION['user_id'];
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('reservation_error', 'Your session expired.');
    header('Location: ../reservations.php');
    exit;
}

if (!$requestId) {
    setFlash('reservation_error', 'Invalid reservation.');
    header('Location: ../reservations.php');
    exit;
}

try {
    $statement = $pdo->prepare(
        "SELECT
            reservations.request_id,
            reservations.merchant_id,
            reservations.status,
            inventory.product_name
         FROM reservations
         INNER JOIN inventory
            ON reservations.item_id = inventory.item_id
         WHERE reservations.request_id = ?
           AND reservations.ngo_id = ?
         LIMIT 1"
    );

    $statement->execute([$requestId, $ngoId]);
    $reservation = $statement->fetch();

    if (!$reservation) {
        setFlash('reservation_error', 'Reservation not found.');
        header('Location: ../reservations.php');
        exit;
    }

    /*
     * Hanya request yang masih 'pending' boleh ditarik balik.
     * Sebaik merchant dah approve atau reject, keputusan tu
     * kekal dalam rekod (tak boleh dibatalkan NGO lagi).
     */
    if ($reservation['status'] !== 'pending') {
        setFlash('reservation_error', 'Only pending reservations can be cancelled.');
        header('Location: ../reservations.php');
        exit;
    }

    $deleteStatement = $pdo->prepare(
        "UPDATE reservations SET status = 'cancelled' 
         WHERE request_id = ?
           AND ngo_id = ?
           AND status = 'pending'"
    );

    $deleteStatement->execute([$requestId, $ngoId]);

    $organizationName = $_SESSION['entity_name'] ?? 'An NGO';

    createNotification(
        $pdo,
        (int) $reservation['merchant_id'],
        'Reservation cancelled',
        $organizationName . ' cancelled the reservation for ' . $reservation['product_name'] . '.',
        'general',
        null
    );

    logActivity(
        $pdo,
        (int) $reservation['merchant_id'],
        'reservation_cancelled',
        $organizationName . ' cancelled the reservation for ' . $reservation['product_name'] . '.'
    );

    setFlash('reservation_success', 'Your reservation was cancelled.');

    header('Location: ../reservations.php');
    exit;
} catch (PDOException $exception) {
    setFlash('reservation_error', 'The reservation could not be cancelled.');
    header('Location: ../reservations.php');
    exit;
}