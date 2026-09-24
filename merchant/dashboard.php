<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];
$locationStatement = $pdo->prepare(
    "SELECT latitude, longitude FROM users WHERE user_id = ? LIMIT 1"
);

$locationStatement->execute([$merchantId]);
$myLocation = $locationStatement->fetch();

$hasCoordinates = $myLocation
    && $myLocation['latitude'] !== null
    && $myLocation['longitude'] !== null;

$summaryStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(status = 'near_expiry'), 0) AS expiring_soon,
        COALESCE(SUM(status = 'surplus'), 0) AS surplus_items
     FROM inventory
     WHERE merchant_id = ?
       AND removed_at IS NULL"
);

$summaryStatement->execute([$merchantId]);
$summary = $summaryStatement->fetch();

$pendingStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM reservations
     WHERE merchant_id = ?
       AND status = 'pending'"
);

$pendingStatement->execute([$merchantId]);
$pendingRequests = (int) $pendingStatement->fetchColumn();

$recentStatement = $pdo->prepare(
    "SELECT
        product_name,
        category,
        quantity,
        unit,
        current_value,
        image_file,
        expiry_date,
        status,
        DATEDIFF(expiry_date, CURDATE()) AS days_remaining,
        " . availableQuantitySql() . " AS available_quantity
     FROM inventory
     WHERE merchant_id = ?
       AND removed_at IS NULL
     ORDER BY created_at DESC
     LIMIT 5"
);

$recentStatement->execute([$merchantId]);
$recentItems = $recentStatement->fetchAll();

/*
 * Two feeds, two sources. activity_log is what the system
 * recorded about this shop; notifications is what it told the
 * merchant. They overlap but are not the same record.
 */
$activityStatement = $pdo->prepare(
    "SELECT message, created_at
     FROM activity_log
     WHERE merchant_id = ?
     ORDER BY created_at DESC
     LIMIT 5"
);

$activityStatement->execute([$merchantId]);
$activities = $activityStatement->fetchAll();

$noticeStatement = $pdo->prepare(
    "SELECT title, message, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 3"
);

$noticeStatement->execute([$merchantId]);
$notices = $noticeStatement->fetchAll();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

require __DIR__ . '/../includes/layouts/header.php';
?>

<div class="welcome-banner">
    <?php if (!$hasCoordinates): ?>
    <div class="alert alert-info">
        Add your shop coordinates in
        <a href="profile.php">your profile</a> so NGOs can find you on the map
        and see how far away you are.
    </div>
<?php endif; ?>
    <h2>Welcome back, <?= e($_SESSION['full_name'] ?? '') ?></h2>

    <p>
        <?php if (!empty($_SESSION['entity_name'])): ?>
            <?= e($_SESSION['entity_name']) ?> &middot;
        <?php endif; ?>
        Here's how your stock is moving today.
    </p>
</div>

<section class="stat-grid">
    <article class="stat-card">
       <span class="stat-icon icon-blue"><img src="<?= BASE_URL ?>/assets/images/inventory.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $summary['total_items'] ?></p>
            <p class="stat-label">Total Inventory</p>
        </div>
    </article>

    <article class="stat-card">
       <span class="stat-icon icon-orange"><img src="<?= BASE_URL ?>/assets/images/expiry.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $summary['expiring_soon'] ?></p>
            <p class="stat-label">Near Expiry</p>
        </div>
    </article>

    <article class="stat-card">
       <span class="stat-icon icon-green"><img src="<?= BASE_URL ?>/assets/images/available.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $summary['surplus_items'] ?></p>
            <p class="stat-label">Available Surplus</p>
        </div>
    </article>

    <article class="stat-card">
       <span class="stat-icon icon-purple"><img src="<?= BASE_URL ?>/assets/images/reserve.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= $pendingRequests ?></p>
            <p class="stat-label">Pending Requests</p>
        </div>
    </article>
</section>

<section class="panel-grid page-merchant-grid">
    <div class="panel">
        <div class="panel-head">
            <h3>Recent Listings</h3>
            <a href="listings.php">View all</a>
        </div>

        <?php if (!$recentItems): ?>
            <div class="empty">
                <strong>No products yet</strong>
                Add your first product to start tracking freshness.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Current Value</th>
                            <th>Days Left</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($recentItems as $item): ?>
                            <?php
                            $days = (int) $item['days_remaining'];

                            $isOut = (int) $item['available_quantity'] <= 0
                                && $item['status'] !== 'expired';
                            ?>

                            <tr>
                                <td>
                                    <div class="cell-product">
                                        <?= productThumbnail($item['image_file'], $item['category']) ?>

                                        <span>
                                            <span class="cell-title"><?= e($item['product_name']) ?></span>
                                            <span class="cell-sub"><?= e($item['category']) ?></span>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <?= (int) $item['quantity'] ?> <?= e($item['unit']) ?>
                                    <span class="cell-sub"><?= (int) $item['available_quantity'] ?> available</span>
                                </td>

                                <td><?= formatCurrency((float) $item['current_value']) ?></td>

                                <td><?= $days < 0 ? 'Expired' : $days . ' days' ?></td>

                                <td>
                                    <?php if ($isOut): ?>
                                        <span class="badge badge-approved">Out of Stock</span>
                                    <?php else: ?>
                                        <span class="badge badge-<?= e($item['status']) ?>"><?= e(statusLabel($item['status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel-stack">
        <div class="panel">
            <div class="panel-head">
                <h3>Recent Activity</h3>
            </div>

            <div class="panel-body">
                <?php if (!$activities): ?>
                    <div class="empty">
                        <strong>Nothing yet</strong>
                        Activity appears as you manage inventory.
                    </div>
                <?php else: ?>
                    <div class="feed">
                        <?php foreach ($activities as $activity): ?>
                            <div class="feed-item">
                                <span class="feed-dot"></span>

                                <span class="feed-text">
                                    <?= e($activity['message']) ?>

                                    <span class="feed-time"><?= date('d M Y, g:i A', strtotime($activity['created_at'])) ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3>Notifications</h3>
                <a href="notifications.php">View all</a>
            </div>

            <div class="panel-body">
                <?php if (!$notices): ?>
                    <div class="empty">
                        <strong>No notifications</strong>
                        You'll be alerted when products change status.
                    </div>
                <?php else: ?>
                    <div class="feed">
                        <?php foreach ($notices as $notice): ?>
                            <div class="feed-item">
                                <span class="feed-dot"></span>

                                <span class="feed-text">
                                    <strong><?= e($notice['title']) ?></strong>
                                    <?= e($notice['message']) ?>

                                    <span class="feed-time"><?= date('d M Y, g:i A', strtotime($notice['created_at'])) ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>