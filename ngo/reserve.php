<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId) {
    setFlash('reservation_error', 'Invalid product.');
    header('Location: surplus.php');
    exit;
}

$available = availableQuantitySql();

$itemStatement = $pdo->prepare(
    "SELECT
        inventory.item_id,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.description,
        inventory.expiry_date,
        inventory.base_price,
        inventory.image_file,
        DATEDIFF(inventory.expiry_date, CURDATE()) AS days_remaining,
        {$available} AS available_quantity,
        users.business_name,
        users.pickup_location,
        users.operating_hours,
        users.phone
     FROM inventory
     INNER JOIN users
        ON inventory.merchant_id = users.user_id
     WHERE inventory.item_id = ?
       AND inventory.status = 'surplus'
       AND inventory.removed_at IS NULL
       AND inventory.expiry_date >= CURDATE()
     LIMIT 1"
);

$itemStatement->execute([$itemId]);
$item = $itemStatement->fetch();

// Item tu mungkin dah ditempah habis oleh NGO lain, luput, atau
// dibuang sejak link ni dijana — jadi disahkan semula di sini,
// bukan percaya terus pada apa yang klik dari page sebelum ni.
if (!$item || (int) $item['available_quantity'] < 1) {
    setFlash('reservation_error', 'This item is no longer available.');
    header('Location: surplus.php');
    exit;
}

$errors = getFlash('form_errors', []);
$old = getFlash('form_old', []);

$pageTitle = 'Reserve Product';
$activePage = 'surplus';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Reserve <?= e($item['product_name']) ?></h2>
        <p>Reservations are free. The merchant will approve or reject your request.</p>
    </div>

    <a class="button button-outline" href="surplus.php">Back</a>
</section>

<div class="split-layout">
    <div class="form-panel">
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="actions/create-reservation.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">

            <div class="field">
                <label for="quantity_requested">Quantity (max <?= (int) $item['available_quantity'] ?> <?= e($item['unit']) ?>)</label>
                <input id="quantity_requested" type="number" name="quantity_requested" min="1" max="<?= (int) $item['available_quantity'] ?>" value="<?= e($old['quantity_requested'] ?? '1') ?>" required>
            </div>

            <div class="field">
                <label for="pickup_date">Preferred Pickup Date</label>
                <input id="pickup_date" type="date" name="pickup_date" min="<?= date('Y-m-d') ?>" max="<?= e($item['expiry_date']) ?>" value="<?= e($old['pickup_date'] ?? date('Y-m-d')) ?>" required>
                <span class="field-hint">Must be on or before the expiry date.</span>
            </div>

            <div class="field">
                <label for="note">Note (optional)</label>
                <textarea id="note" name="note" rows="3" placeholder="Anything the merchant should know"><?= e($old['note'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Submit Reservation</button>
                <a class="button button-outline" href="surplus.php">Cancel</a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-head">
    <h3>Product Details</h3>
        </div>

        <div class="item-banner">
            <?= productThumbnail($item['image_file'], $item['category'], 'category-icon-large') ?>

            <span>
                <span class="item-banner-name"><?= e($item['product_name']) ?></span>
                <span class="cell-sub"><?= e($item['category']) ?> &middot; <?= e($item['business_name']) ?></span>
            </span>
        </div>
       <div class="panel-body">
    <div class="detail-highlight">
        <div class="highlight-cell">
            <span class="highlight-value"><?= (int) $item['available_quantity'] ?></span>
            <span class="highlight-label"><?= e($item['unit']) ?> available</span>
        </div>

        <div class="highlight-cell">
            <span class="highlight-value <?= daysUrgencyClass((int) $item['days_remaining']) ?>"><?= (int) $item['days_remaining'] ?></span>
            <span class="highlight-label">days remaining</span>
        </div>
    </div>

    <div class="detail-row">
        <span>Expiry Date</span>
        <strong><?= date('d M Y', strtotime($item['expiry_date'])) ?></strong>
    </div>

    <?php if ($item['description']): ?>
        <div class="detail-row">
            <span>Notes</span>
            <strong><?= e($item['description']) ?></strong>
        </div>
    <?php endif; ?>

    <div class="pickup-block">
        <span class="pickup-heading">Collection Details</span>

        <div class="pickup-line">
            <span class="pickup-icon">&#9873;</span>
            <span><?= e($item['pickup_location']) ?></span>
        </div>

        <div class="pickup-line">
            <span class="pickup-icon">&#9200;</span>
            <span><?= e($item['operating_hours'] ?: 'Hours not stated') ?></span>
        </div>

        <div class="pickup-line">
            <span class="pickup-icon">&#9742;</span>
            <span><?= e($item['phone']) ?></span>
        </div>
    </div>
</div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>