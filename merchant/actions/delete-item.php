<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('merchant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../listings.php');
    exit;
}

$merchantId = $_SESSION['user_id'];
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('listing_error', 'Your session expired.');
    header('Location: ../listings.php');
    exit;
}

if (!$itemId) {
    setFlash('listing_error', 'Invalid product.');
    header('Location: ../listings.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $itemStatement = $pdo->prepare(
        "SELECT item_id, product_name, image_file
         FROM inventory
         WHERE item_id = ?
           AND merchant_id = ?
           AND removed_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $itemStatement->execute([$itemId, $merchantId]);
    $item = $itemStatement->fetch();

    if (!$item) {
        $pdo->rollBack();

        setFlash('listing_error', 'Product not found.');
        header('Location: ../listings.php');
        exit;
    }

    /*
     * An NGO may already be depending on this item, so active
     * reservations must be settled before it disappears.
     */
    $activeStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM reservations
         WHERE item_id = ?
           AND status IN ('pending', 'approved')"
    );

    $activeStatement->execute([$itemId]);

    if ((int) $activeStatement->fetchColumn() > 0) {
        $pdo->rollBack();

        setFlash(
            'listing_error',
            'This item has active reservations. '
                . 'Please reject or complete them first.'
        );

        header('Location: ../listings.php');
        exit;
    }

    /*
     * Soft delete. The row stays so past reservation history and
     * report figures remain intact.
     */
    $deleteStatement = $pdo->prepare(
        "UPDATE inventory
         SET removed_at = NOW()
         WHERE item_id = ?
           AND merchant_id = ?"
    );

    $deleteStatement->execute([$itemId, $merchantId]);

    $pdo->commit();
        deleteProductImage($item['image_file']);

    logActivity(
        $pdo,
        $merchantId,
        'item_deleted',
        $item['product_name'] . ' was removed from inventory.'
    );

    setFlash(
        'listing_success',
        $item['product_name'] . ' was removed.'
    );

    header('Location: ../listings.php');
    exit;
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    setFlash('listing_error', 'The item could not be removed.');
    header('Location: ../listings.php');
    exit;
}