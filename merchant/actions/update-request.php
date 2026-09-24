<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('merchant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../requests.php');
    exit;
}

$merchantId = $_SESSION['user_id'];
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
$rejectionReason = trim($_POST['rejection_reason'] ?? '');

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('request_error', 'Your session expired.');
    header('Location: ../requests.php');
    exit;
}

if (!$requestId || !in_array($decision, ['approve', 'reject', 'complete'], true)) {
    setFlash('request_error', 'Invalid request.');
    header('Location: ../requests.php');
    exit;
}

if ($decision === 'reject' && $rejectionReason === '') {
    setFlash('request_error', 'Please provide a reason for rejection.');
    header('Location: ../requests.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        "SELECT
            reservations.request_id,
            reservations.ngo_id,
            reservations.quantity_requested,
            reservations.status,
            inventory.product_name,
            inventory.unit
         FROM reservations
         INNER JOIN inventory
            ON reservations.item_id = inventory.item_id
         WHERE reservations.request_id = ?
           AND reservations.merchant_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $statement->execute([$requestId, $merchantId]);
    $reservation = $statement->fetch();

    if (!$reservation) {
        $pdo->rollBack();

        setFlash('request_error', 'Request not found.');
        header('Location: ../requests.php');
        exit;
    }

    /*
     * Each decision is only valid from one starting state, which
     * keeps the workflow moving in one direction:
     * pending -> approved -> completed, or pending -> rejected.
     */
    $allowedFrom = [
        'approve' => 'pending',
        'reject' => 'pending',
        'complete' => 'approved'
    ];

    if ($reservation['status'] !== $allowedFrom[$decision]) {
        $pdo->rollBack();

        setFlash('request_error', 'This request can no longer be changed.');
        header('Location: ../requests.php');
        exit;
    }

    if ($decision === 'approve') {
        $updateStatement = $pdo->prepare(
            "UPDATE reservations SET status = 'approved' WHERE request_id = ?"
        );

        $updateStatement->execute([$requestId]);

        $title = 'Reservation approved';
        $message = 'Your reservation for ' . $reservation['product_name'] . ' was approved. Please proceed with the scheduled pickup.';
        $flash = 'Request approved.';
        $activityType = 'request_approved';
    } elseif ($decision === 'reject') {
        $updateStatement = $pdo->prepare(
            "UPDATE reservations SET status = 'rejected', rejection_reason = ? WHERE request_id = ?"
        );

        $updateStatement->execute([$rejectionReason, $requestId]);

        $title = 'Reservation rejected';
        $message = 'Your reservation for ' . $reservation['product_name'] . ' was rejected. Reason: ' . $rejectionReason;
        $flash = 'Request rejected.';
        $activityType = 'request_rejected';
    } else {
        $updateStatement = $pdo->prepare(
            "UPDATE reservations SET status = 'completed', completed_at = NOW() WHERE request_id = ?"
        );

        $updateStatement->execute([$requestId]);

        $title = 'Collection completed';
        $message = 'Your collection of ' . $reservation['product_name'] . ' has been confirmed. Thank you.';
        $flash = 'Request marked as collected.';
        $activityType = 'item_collected';
    }

    $pdo->commit();

    createNotification(
        $pdo,
        (int) $reservation['ngo_id'],
        $title,
        $message,
        'reservation',
        $requestId
    );

    logActivity(
        $pdo,
        $merchantId,
        $activityType,
        $reservation['product_name'] . ' request #' . $requestId . ' was ' . $decision . 'd.'
    );

    setFlash('request_success', $flash);

    header('Location: ../requests.php');
    exit;
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    setFlash('request_error', 'The request could not be updated.');
    header('Location: ../requests.php');
    exit;
}