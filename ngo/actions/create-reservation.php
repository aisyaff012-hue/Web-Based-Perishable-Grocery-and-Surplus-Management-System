<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('ngo');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../surplus.php');
    exit;
}

$ngoId = $_SESSION['user_id'];
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity_requested', FILTER_VALIDATE_INT);
$pickupDate = trim($_POST['pickup_date'] ?? '');
$note = trim($_POST['note'] ?? '');

$errors = [];

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session expired. Please try again.';
}

if (!$itemId) {
    $errors[] = 'Invalid product.';
}

if (!$quantity || $quantity < 1) {
    $errors[] = 'Quantity must be at least 1.';
}

$parsedDate = DateTime::createFromFormat('Y-m-d', $pickupDate);

if (!$parsedDate || $parsedDate->format('Y-m-d') !== $pickupDate) {
    $errors[] = 'Please choose a valid pickup date.';
}

if ($errors) {
    setFlash('form_errors', $errors);
    setFlash('form_old', [
        'quantity_requested' => $quantity,
        'pickup_date' => $pickupDate,
        'note' => $note
    ]);

    header('Location: ../reserve.php?id=' . (int) $itemId);
    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * Baris item dikunci (FOR UPDATE) semasa ketersediaan disemak,
     * supaya dua NGO yang buat reservation pada saat yang sama
     * tak boleh over-subscribe (tempah lebih dari baki yang ada).
     */
    $itemStatement = $pdo->prepare(
        "SELECT
            item_id,
            merchant_id,
            product_name,
            unit,
            quantity,
            expiry_date
         FROM inventory
         WHERE item_id = ?
           AND status = 'surplus'
           AND removed_at IS NULL
           AND expiry_date >= CURDATE()
         LIMIT 1
         FOR UPDATE"
    );

    $itemStatement->execute([$itemId]);
    $item = $itemStatement->fetch();

    if (!$item) {
        $pdo->rollBack();

        setFlash('reservation_error', 'This product is no longer available.');
        header('Location: ../surplus.php');
        exit;
    }

        $takenStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_requested), 0)
         FROM reservations
         WHERE item_id = ?
           AND status IN ('pending', 'approved', 'completed')"
    );

    $takenStatement->execute([$itemId]);
    $taken = (int) $takenStatement->fetchColumn();

    $availableQuantity = (int) $item['quantity'] - $taken;

    if ($quantity > $availableQuantity) {
        $pdo->rollBack();

        setFlash('reservation_error', 'Only ' . $availableQuantity . ' ' . $item['unit'] . ' remain available.');
        header('Location: ../reserve.php?id=' . (int) $itemId);
        exit;
    }

    /*
     * Form ada attribute HTML "min" pada field tarikh, tapi tu
     * cuma kemudahan browser dan boleh dibuang sebelum request
     * dihantar (cth guna devtools). Tarikh disemak SEMULA di sini
     * sebab inilah satu-satunya titik yang sistem betul-betul kawal.
     */
    if ($pickupDate < date('Y-m-d')) {
        $pdo->rollBack();

        setFlash('form_errors', ['Pickup date cannot be in the past.']);

        setFlash('form_old', [
            'quantity_requested' => $quantity,
            'pickup_date' => $pickupDate,
            'note' => $note
        ]);

        header('Location: ../reserve.php?id=' . $itemId);
        exit;
    }

    // Tarikh kutipan tak boleh dijadualkan SELEPAS makanan tu luput.
    if ($pickupDate > $item['expiry_date']) {
        $pdo->rollBack();

        setFlash('reservation_error', 'Pickup date must be on or before the expiry date.');
        header('Location: ../reserve.php?id=' . (int) $itemId);
        exit;
    }

    $insertStatement = $pdo->prepare(
        "INSERT INTO reservations (
            ngo_id,
            merchant_id,
            item_id,
            quantity_requested,
            note,
            request_date,
            pickup_date,
            status
         ) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, 'pending')"
    );

    $insertStatement->execute([
        $ngoId,
        $item['merchant_id'],
        $itemId,
        $quantity,
        $note !== '' ? $note : null,
        $pickupDate
    ]);

    $requestId = (int) $pdo->lastInsertId();

    $pdo->commit();

    $organizationName = $_SESSION['entity_name'] ?? 'An NGO';

    /*
     * Reservation itu sendiri SENTIASA disimpan dan sentiasa muncul
     * pada page Requests merchant. Tetapan ni cuma kawal sama ada
     * notifikasi dalam-app dibangkitkan untuk ia atau tidak.
     */
    $merchantSettings = getMerchantSettings($pdo, (int) $item['merchant_id']);

    if ((int) $merchantSettings['notify_reservation'] === 1) {
        createNotification(
            $pdo,
            (int) $item['merchant_id'],
            'New reservation request',
            $organizationName . ' requested ' . $quantity . ' ' . $item['unit'] . ' of ' . $item['product_name'] . '.',
            'reservation',
            $requestId
        );
    }

    logActivity(
        $pdo,
        (int) $item['merchant_id'],
        'reservation_created',
        $organizationName . ' requested ' . $quantity . ' ' . $item['unit'] . ' of ' . $item['product_name'] . '.'
    );

    setFlash('reservation_success', 'Your reservation was submitted and is awaiting merchant approval.');

    header('Location: ../reservations.php');
    exit;
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    setFlash('reservation_error', 'The reservation could not be created.');
    header('Location: ../surplus.php');
    exit;
}