<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];

$available = availableQuantitySql();

// Jumlah keseluruhan item surplus yang masih ada baki kuantiti
// (belum ditempah habis) dan belum luput — untuk kad stat "Available Surplus".
$surplusStatement = $pdo->query(
    "SELECT COUNT(*)
     FROM inventory
     WHERE status = 'surplus'
       AND removed_at IS NULL
       AND expiry_date >= CURDATE()
       AND {$available} > 0"
);

$availableSurplus = (int) $surplusStatement->fetchColumn();

$meStatement = $pdo->prepare(
    "SELECT latitude, longitude FROM users WHERE user_id = ? LIMIT 1"
);

$meStatement->execute([$ngoId]);
$me = $meStatement->fetch();

$hasLocation = $me
    && $me['latitude'] !== null
    && $me['longitude'] !== null;

$myLat = $hasLocation ? (float) $me['latitude'] : null;
$myLng = $hasLocation ? (float) $me['longitude'] : null;

/*
 * Beberapa merchant terdekat yang ada surplus. Ni cuma pintasan
 * (shortcut) ke senarai penuh, bukan ganti untuk page Surplus,
 * jadi ia dikekalkan ringkas — cuma bawa info yang bantu NGO
 * putuskan mana nak pergi dulu.
 */
$nearbyShops = [];

if ($hasLocation) {
    $distanceSql = distanceKmSql();

    // Parameter dibind ikut turutan lat, lng, lat (formula
    // Haversine guna lat NGO dua kali — dalam COS dan dalam SIN).
    $nearbyStatement = $pdo->prepare(
        "SELECT
            users.user_id,
            users.business_name,
            users.pickup_location,
            users.shop_image,
            COUNT(*) AS item_count,
            MIN(DATEDIFF(inventory.expiry_date, CURDATE())) AS soonest,
            {$distanceSql} AS distance_km
         FROM users
         INNER JOIN inventory
            ON inventory.merchant_id = users.user_id
         WHERE users.role = 'merchant'
           AND users.latitude IS NOT NULL
           AND users.longitude IS NOT NULL
           AND inventory.status = 'surplus'
           AND inventory.removed_at IS NULL
           AND inventory.expiry_date >= CURDATE()
           AND {$available} > 0
         GROUP BY users.user_id
         ORDER BY distance_km ASC
         LIMIT 3"
    );

    $nearbyStatement->execute([$myLat, $myLng, $myLat]);
    $nearbyShops = $nearbyStatement->fetchAll();
}

$reservationStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'approved'), 0) AS upcoming,
        COALESCE(SUM(status = 'completed'), 0) AS collected
     FROM reservations
     WHERE ngo_id = ?"
);

$reservationStatement->execute([$ngoId]);
$counts = $reservationStatement->fetch();

// Pratonton 7 item surplus terkini (merentasi SEMUA merchant) untuk
// jadual "New Available Surplus".
$newSurplusStatement = $pdo->query(
    "SELECT
        inventory.item_id,
        inventory.product_name,
        inventory.category,
        inventory.quantity,
        inventory.unit,
        inventory.expiry_date,
        inventory.image_file,
        users.business_name,
        DATEDIFF(inventory.expiry_date, CURDATE()) AS days_remaining
     FROM inventory
     INNER JOIN users
        ON inventory.merchant_id = users.user_id
     WHERE inventory.status = 'surplus'
       AND inventory.removed_at IS NULL
       AND inventory.expiry_date >= CURDATE()
       AND {$available} > 0
     ORDER BY inventory.expiry_date ASC
     LIMIT 7"
);

$newSurplus = $newSurplusStatement->fetchAll();

$noticeStatement = $pdo->prepare(
    "SELECT title, message, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 3"
);

$noticeStatement->execute([$ngoId]);
$notices = $noticeStatement->fetchAll();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

require __DIR__ . '/../includes/layouts/header.php';
?>

<div class="welcome-banner">
    <h2>Welcome back, <?= e($_SESSION['full_name'] ?? '') ?></h2>

    <p>
        <?php if (!empty($_SESSION['entity_name'])): ?>
            <?= e($_SESSION['entity_name']) ?> &middot;
        <?php endif; ?>
        Here's what's available to collect today.
    </p>
</div>

<section class="stat-grid">
    <article class="stat-card">
       <span class="stat-icon icon-green"><img src="<?= BASE_URL ?>/assets/images/available.png?v=<?= filemtime(dirname(__DIR__) . '/assets/images/available.png') ?>" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= $availableSurplus ?></p>
            <p class="stat-label">Available Surplus</p>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-purple"><img src="<?= BASE_URL ?>/assets/images/reserve.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $counts['pending'] ?></p>
            <p class="stat-label">Awaiting Approval</p>
        </div>
    </article>

    <article class="stat-card">
       <span class="stat-icon icon-orange"><img src="<?= BASE_URL ?>/assets/images/pickup.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $counts['upcoming'] ?></p>
            <p class="stat-label">Upcoming Pickups</p>
        </div>
    </article>

    <article class="stat-card">
       <span class="stat-icon icon-teal"><img src="<?= BASE_URL ?>/assets/images/collected.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-value"><?= (int) $counts['collected'] ?></p>
            <p class="stat-label">Products Collected</p>
        </div>
    </article>
</section>

<section class="panel-grid">
    <div class="panel">
        <div class="panel-head">
            <h3>New Available Surplus</h3>
            <a href="surplus.php">View all</a>
        </div>

        <?php if (!$newSurplus): ?>
            <div class="empty">
                <strong>Nothing available right now</strong>
                Surplus appears here as merchants' stock nears expiry.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Merchant</th>
                            <th>Quantity</th>
                            <th>Expiry Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($newSurplus as $row): ?>
                            <?php $days = (int) $row['days_remaining']; ?>

                            <tr>
                                <td>
                                    <div class="cell-product">
                                        <?= productThumbnail($row['image_file'], $row['category']) ?>

                                        <span>
                                            <span class="cell-title"><?= e($row['product_name']) ?></span>
                                            <span class="cell-sub"><?= e($row['category']) ?></span>
                                        </span>
                                    </div>
                                </td>

                                <td><?= e($row['business_name']) ?></td>

                                <td><?= (int) $row['quantity'] ?> <?= e($row['unit']) ?></td>

                                <td>
                                    <?= date('d M Y', strtotime($row['expiry_date'])) ?>
                                    <span class="days-hint <?= daysUrgencyClass($days) ?>">(<?= formatDaysLeft($days) ?>)</span>
                                </td>

                                <td>
                                    <a class="button button-small" href="reserve.php?id=<?= (int) $row['item_id'] ?>">Reserve</a>
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
            <h3>Notifications</h3>
            <a href="notifications.php">View all</a>
        </div>

        <div class="panel-body">
            <?php if (!$notices): ?>
                <div class="empty">
                    <strong>No notifications</strong>
                    You'll be alerted when new surplus is listed.
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

<?php if ($nearbyShops): ?>
    <div class="panel">
        <div class="panel-head">
            <div>
                <h3>Surplus near you</h3>
                <p class="panel-sub"><?= count($nearbyShops) ?> closest merchant<?= count($nearbyShops) === 1 ? '' : 's' ?> with stock available now</p>
            </div>

            <a class="map-open" href="map.php" title="Open the surplus map">
                <span class="map-open-icon">&#128506;</span>
                <span>Map</span>
            </a>
        </div>

        <ul class="nearby-list">
            <?php foreach ($nearbyShops as $shop): ?>
                <?php $soonest = (int) $shop['soonest']; ?>

                <li>
                    <a class="nearby-row" href="surplus.php?merchant=<?= (int) $shop['user_id'] ?>">
                        <?= shopCover($shop['shop_image'], $shop['business_name'], 'nearby-avatar') ?>

                        <span class="nearby-text">
                            <span class="nearby-name"><?= e($shop['business_name']) ?></span>
                            <span class="nearby-place"><?= e($shop['pickup_location']) ?></span>
                        </span>

                        <span class="nearby-facts">
                            <span class="nearby-distance"><?= e(formatDistance((float) $shop['distance_km'])) ?></span>

                            <span class="nearby-items">
                                <?= (int) $shop['item_count'] ?> product<?= (int) $shop['item_count'] === 1 ? '' : 's' ?>
                                &middot;
                                <span class="days-hint <?= daysUrgencyClass($soonest) ?>"><?= e(formatDaysLeft($soonest)) ?> left</span>
                            </span>
                        </span>

                        <span class="nearby-go">&rsaquo;</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>