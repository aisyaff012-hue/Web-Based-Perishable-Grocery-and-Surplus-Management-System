<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('merchant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    refreshInventoryStatus($pdo);
    header('Location: ../listings.php');
    exit;
}

$merchantId = $_SESSION['user_id'];
$mode = $_POST['mode'] ?? 'add';
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);

$productName = trim($_POST['product_name'] ?? '');
$category = trim($_POST['category'] ?? '');
$unit = trim($_POST['unit'] ?? 'units');
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
$basePrice = filter_input(
    INPUT_POST,
    'base_price',
    FILTER_VALIDATE_FLOAT
);
$expiryDate = trim($_POST['expiry_date'] ?? '');
$description = trim($_POST['description'] ?? '');
$removeImage = isset($_POST['remove_image']);

$errors = [];

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session expired. Please try again.';
}

if ($productName === '') {
    $errors[] = 'Product name is required.';
}

if (!in_array($category, productCategories(), true)) {
    $errors[] = 'Please choose a valid category.';
}

if (!in_array($unit, productUnits(), true)) {
    $errors[] = 'Please choose a valid unit.';
}

if ($quantity === false || $quantity === null || $quantity < 1) {
    $errors[] = 'Quantity must be at least 1.';
}

if ($basePrice === false || $basePrice === null || $basePrice < 0) {
    $errors[] = 'Base price must be a positive number.';
}

// Confirms the date is real, not just correctly formatted.
$parsedDate = DateTime::createFromFormat('Y-m-d', $expiryDate);

if (
    !$parsedDate
    || $parsedDate->format('Y-m-d') !== $expiryDate
) {
    $errors[] = 'Please enter a valid expiry date.';
} elseif ($mode === 'add' && $expiryDate < date('Y-m-d')) {
    /*
     * Only new items are blocked. An existing item may legitimately
     * hold a past date once it has expired, and editing it for some
     * other reason should not be refused.
     */
    $errors[] = 'Expiry date cannot be in the past.';
}

if ($mode === 'edit' && !$itemId) {
    $errors[] = 'Invalid product.';
}

$returnUrl = $mode === 'edit'
    ? '../edit-item.php?id=' . (int) $itemId
    : '../add-item.php';

if ($errors) {
    setFlash('form_errors', $errors);

    setFlash('form_old', [
        'product_name' => $productName,
        'category' => $category,
        'unit' => $unit,
        'quantity' => $quantity,
        'base_price' => $basePrice,
        'expiry_date' => $expiryDate,
        'description' => $description
    ]);

    header('Location: ' . $returnUrl);
    exit;
}

$settings = getMerchantSettings($pdo, $merchantId);

$pricing = calculatePricing(
    (float) $basePrice,
    $expiryDate,
    $settings
);

/*
 * surplus_since is stamped the moment an item enters surplus so
 * the Donations page can show how long it has been listed.
 */
$surplusSince = $pricing['status'] === 'surplus'
    ? date('Y-m-d')
    : null;

try {
    if ($mode === 'edit') {
            $ownerStatement = $pdo->prepare(
            "SELECT status, surplus_since, image_file
             FROM inventory
             WHERE item_id = ?
               AND merchant_id = ?
               AND removed_at IS NULL
             LIMIT 1"
        );
        $ownerStatement->execute([$itemId, $merchantId]);
        $existing = $ownerStatement->fetch();

        if (!$existing) {
            setFlash('listing_error', 'Product not found.');
            header('Location: ../listings.php');
            exit;
        }
        /*
         * A new upload replaces the old file, and the checkbox
         * clears it. Either way the previous file is deleted so
         * the folder does not fill with orphans.
         */
        $imageFile = $existing['image_file'];

        [$uploadedName, $uploadError] = saveProductImage(
            $_FILES['product_image'] ?? [],
            (int) $itemId
        );

        if ($uploadError) {
            setFlash('form_errors', [$uploadError]);
            header('Location: ../edit-item.php?id=' . (int) $itemId);
            exit;
        }

        if ($uploadedName) {
            deleteProductImage($existing['image_file']);
            $imageFile = $uploadedName;
        } elseif ($removeImage) {
            deleteProductImage($existing['image_file']);
            $imageFile = null;
        }

                $takenStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_requested), 0)
             FROM reservations
             WHERE item_id = ?
               AND status IN ('pending', 'approved', 'completed')"
        );

        $takenStatement->execute([$itemId]);
        $taken = (int) $takenStatement->fetchColumn();

        if ($quantity < $taken) {
            setFlash('form_errors', [
                'Quantity cannot be lower than ' . $taken
                    . ' — that amount is already reserved by NGOs.'
            ]);

            header('Location: ../edit-item.php?id=' . (int) $itemId);
            exit;
        }
        // Keep the original surplus date if it was already set.
        if (
            $pricing['status'] === 'surplus'
            && $existing['surplus_since'] !== null
        ) {
            $surplusSince = $existing['surplus_since'];
        }

                $updateStatement = $pdo->prepare(
            "UPDATE inventory
             SET product_name = ?,
                 category = ?,
                 quantity = ?,
                 unit = ?,
                 base_price = ?,
                 current_value = ?,
                 reduction_percentage = ?,
                 expiry_date = ?,
                 status = ?,
                 surplus_since = ?,
                 description = ?,
                 image_file = ?
             WHERE item_id = ?
               AND merchant_id = ?"
        );

        $updateStatement->execute([
            $productName,
            $category,
            $quantity,
            $unit,
            $basePrice,
            $pricing['current_value'],
            $pricing['reduction_percentage'],
            $expiryDate,
            $pricing['status'],
            $surplusSince,
            $description !== '' ? $description : null,
            $imageFile,
            $itemId,
            $merchantId
        ]);

        logActivity(
            $pdo,
            $merchantId,
            'item_updated',
            $productName . ' was updated.'
        );

        setFlash('listing_success', $productName . ' was updated.');
    } else {
        $insertStatement = $pdo->prepare(
            "INSERT INTO inventory (
                merchant_id,
                product_name,
                category,
                description,
                quantity,
                unit,
                base_price,
                current_value,
                reduction_percentage,
                expiry_date,
                status,
                surplus_since
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStatement->execute([
            $merchantId,
            $productName,
            $category,
            $description !== '' ? $description : null,
            $quantity,
            $unit,
            $basePrice,
            $pricing['current_value'],
            $pricing['reduction_percentage'],
            $expiryDate,
            $pricing['status'],
            $surplusSince
        ]);

                $newItemId = (int) $pdo->lastInsertId();

        // Saved after insert so the file name can carry the item id.
        [$uploadedName, $uploadError] = saveProductImage(
            $_FILES['product_image'] ?? [],
            $newItemId
        );

        /*
         * The row has to exist before the file can be named after
         * it, so a rejected upload leaves an unwanted row behind.
         * It is removed here rather than left in the inventory.
         */
        if ($uploadError) {
            $undoStatement = $pdo->prepare(
                "DELETE FROM inventory WHERE item_id = ?"
            );

            $undoStatement->execute([$newItemId]);

            setFlash('form_errors', [$uploadError]);

            setFlash('form_old', [
                'product_name' => $productName,
                'category' => $category,
                'unit' => $unit,
                'quantity' => $quantity,
                'base_price' => $basePrice,
                'expiry_date' => $expiryDate,
                'description' => $description
            ]);

            header('Location: ../add-item.php');
            exit;
        }

        if ($uploadedName) {
            $imageStatement = $pdo->prepare(
                "UPDATE inventory SET image_file = ? WHERE item_id = ?"
            );

            $imageStatement->execute([$uploadedName, $newItemId]);
        }

         /*
         * An item added within the donation window is surplus from
         * birth, so the status engine never sees a change and never
         * fires the alert. It has to be sent here instead.
         */
        if ($pricing['status'] === 'surplus') {
            $businessName = $_SESSION['entity_name'] ?? 'A merchant';

            logActivity(
                $pdo,
                $merchantId,
                'became_surplus',
                $productName . ' was added already within the donation '
                    . 'window and is available to NGOs immediately.'
            );

            notifyAllNgos(
                $pdo,
                'New surplus available',
                $businessName . ' has ' . $quantity . ' ' . $unit
                    . ' of ' . $productName . ' available as surplus. Expires '
                    . date('d M Y', strtotime($expiryDate)) . '.',
                'item',
                $newItemId
            );
        }
        logActivity(
            $pdo,
            $merchantId,
            'item_added',
            $productName . ' was added to inventory.'
        );

        setFlash('listing_success', $productName . ' was added.');
    }

    header('Location: ../listings.php');
    exit;
} catch (PDOException $exception) {
    setFlash('listing_error', 'The item could not be saved.');
    header('Location: ../listings.php');
    exit;
}