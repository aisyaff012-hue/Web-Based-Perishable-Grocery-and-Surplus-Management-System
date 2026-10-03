<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];

$successMessage = getFlash('reservation_success', '');
$errorMessage = getFlash('reservation_error', '');

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

$merchantId = filter_input(INPUT_GET, 'merchant', FILTER_VALIDATE_INT) ?: null;
$search = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? 'all';
$radiusFilter = $_GET['radius'] ?? 'all';

if (!in_array($radiusFilter, ['all', '2', '5', '10', '25'], true)) {
    $radiusFilter = 'all';
}

$available = availableQuantitySql();

$conditions = [
    "inventory.status = 'surplus'",
    'inventory.removed_at IS NULL',
    'inventory.expiry_date >= CURDATE()'
];

$parameters = [];

if ($merchantId) {
    $conditions[] = 'inventory.merchant_id = ?';
    $parameters[] = $merchantId;
}

if ($search !== '') {
    $conditions[] = 'inventory.product_name LIKE ?';
    $parameters[] = '%' . $search . '%';
}

if ($categoryFilter !== 'all') {
    $conditions[] = 'inventory.category = ?';
    $parameters[] = $categoryFilter;
}

/*
 * Jarak cuma boleh dikira sekali NGO dah simpan koordinat dia
 * sendiri, jadi expression tu disisip bersyarat (bukan diandaikan
 * sentiasa ada) — page ni tetap berfungsi walaupun tanpa koordinat.
 */
if ($hasLocation) {
    $distanceSelect = ', ' . distanceKmSql() . ' AS distance_km';
} else {
    $distanceSelect = ', NULL AS distance_km';
}

$sql = "SELECT
        inventory.item_id,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.expiry_date,
        inventory.image_file,
        inventory.base_price,
        inventory.current_value,
        inventory.reduction_percentage,
        DATEDIFF(inventory.expiry_date, CURDATE()) AS days_remaining,
        {$available} AS available_quantity,
        users.user_id AS merchant_id,
        users.business_name,
        users.business_type,
        users.pickup_location,
        users.operating_hours,
        users.phone,
        users.email,
        users.address,
        users.latitude AS shop_lat,
        users.longitude AS shop_lng,
        users.shop_image
        {$distanceSelect}
     FROM inventory
     INNER JOIN users
        ON inventory.merchant_id = users.user_id
     WHERE " . implode(' AND ', $conditions) . "
     HAVING available_quantity > 0
     ORDER BY inventory.expiry_date ASC";

$bindings = $hasLocation
    ? array_merge([$myLat, $myLng, $myLat], $parameters)
    : $parameters;

$statement = $pdo->prepare($sql);
$statement->execute($bindings);
$rows = $statement->fetchAll();

/*
 * Senarai ni berfungsi pada dua tahap: muka kedai (shop front)
 * dulu, kemudian item-item kedai tu. Pengumpulan (grouping)
 * berlaku di sini supaya dua-dua tahap baca dari query yang sama.
 */
$merchants = [];

foreach ($rows as $row) {
    $key = (int) $row['merchant_id'];

    if (!isset($merchants[$key])) {
        $merchants[$key] = [
            'id' => $key,
            'name' => $row['business_name'],
            'type' => $row['business_type'],
            'location' => $row['pickup_location'],
            'hours' => $row['operating_hours'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'address' => $row['address'],
            'lat' => $row['shop_lat'] !== null ? (float) $row['shop_lat'] : null,
            'lng' => $row['shop_lng'] !== null ? (float) $row['shop_lng'] : null,
            'distance' => $row['distance_km'] !== null
                ? (float) $row['distance_km']
                : null,
            'soonest' => (int) $row['days_remaining'],
            'image' => $row['shop_image'],
            'items' => []
        ];
    }

    $merchants[$key]['soonest'] = min(
        $merchants[$key]['soonest'],
        (int) $row['days_remaining']
    );

    $merchants[$key]['items'][] = $row;
}

$merchants = array_values($merchants);

// Penapis jarak (radius) dan susunan ikut jarak cuma relevan kalau
// NGO ada koordinat — tanpa koordinat, $merchants kekal tersusun
// ikut tarikh luput (dari query SQL) macam biasa.
if ($hasLocation) {
    if ($radiusFilter !== 'all') {
        $limit = (int) $radiusFilter;

        $merchants = array_values(array_filter(
            $merchants,
            function ($shop) use ($limit) {
                return $shop['distance'] !== null
                    && $shop['distance'] <= $limit;
            }
        ));
    }

    usort($merchants, function ($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
}

$selected = null;

if ($merchantId) {
    foreach ($merchants as $shop) {
        if ($shop['id'] === $merchantId) {
            $selected = $shop;
            break;
        }
    }
}

/*
 * Berapa banyak merchant ni DAH betul-betul agihkan makanan.
 * Dipaparkan pada header kedai sebab ia ukur perkara yang sistem
 * ni memang dicipta untuk buat, bukan sekadar isyarat popularity.
 */
$savedQuantity = 0;

if ($selected) {
    $savedStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(reservations.quantity_requested), 0)
         FROM reservations
         WHERE reservations.merchant_id = ?
           AND reservations.status = 'completed'"
    );

    $savedStatement->execute([$merchantId]);
    $savedQuantity = (int) $savedStatement->fetchColumn();
}

$pageTitle = 'Available Surplus';
$activePage = 'surplus';

require __DIR__ . '/../includes/layouts/header.php';
?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-error"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if ($merchantId && $selected): ?>

    <!-- ============ TAHAP 2: satu merchant ============ -->

    <div class="shop-hero">
        <?= shopCover($selected['image'], $selected['name'], 'shop-hero-image') ?>

        <a class="shop-hero-back" href="surplus.php" aria-label="Back to all merchants">&larr;</a>

        <div class="shop-hero-caption">
            <?= shopCover($selected['image'], $selected['name'], 'shop-hero-logo') ?>

            <div>
                <h2>
                    <?= e($selected['name']) ?>

                    <?php if (!empty($selected['type'])): ?>
                        <span class="shop-hero-type"><?= e($selected['type']) ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ($savedQuantity > 0): ?>
                    <p class="shop-hero-saved">&#9851; <?= $savedQuantity ?> units saved through FreshTrack</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

       <div class="collect-panel">
        <div class="collect-main">
            <div class="collect-block">
                <span class="collect-heading">&#128336; Collection time</span>
                <?php foreach (hoursLinesFull($selected['hours']) as $line): ?>
                    <p class="collect-value"><?= e($line) ?></p>
                <?php endforeach; ?>
            </div>

            <div class="collect-block">
                <span class="collect-heading">&#128205; Collect at</span>
                <p class="collect-value"><?= e($selected['location']) ?></p>

                <?php if (!empty($selected['address'])): ?>
                    <p class="collect-note"><?= e($selected['address']) ?></p>
                <?php endif; ?>
            </div>

            <div class="collect-block">
                <span class="collect-heading">&#128222; Contact</span>

                <?php if ($selected['phone']): ?>
                    <p class="collect-value"><?= e($selected['phone']) ?></p>
                <?php endif; ?>

                <?php if ($selected['email']): ?>
                    <p class="collect-note"><?= e($selected['email']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="collect-action">
            <?php $mapUrl = mapLink($selected['address'], $selected['lat'], $selected['lng']); ?>

            <?php if ($mapUrl): ?>
                <a class="button collect-navigate" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">&#10148; Navigate</a>
            <?php endif; ?>

            <?php if ($selected['distance'] !== null): ?>
                <span class="collect-distance"><?= e(formatDistance($selected['distance'])) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <section class="section-head">
        <div>
            <h3>Available Products</h3>
            <p><?= count($selected['items']) ?> product<?= count($selected['items']) === 1 ? '' : 's' ?></p>
        </div>
    </section>

    <div class="panel">
        <div class="surplus-grid">
            <?php foreach ($selected['items'] as $row): ?>
                <?php
                    $days = (int) $row['days_remaining'];
                    $reduction = (float) $row['reduction_percentage'];
                    $isUrgent = $days <= 1;
                ?>

                <article class="surplus-card">
                    <div class="surplus-card-media">
                        <?= productThumbnail($row['image_file'], $row['category'], 'surplus-card-photo') ?>

                        <span class="surplus-card-stock">
                            <?= (int) $row['available_quantity'] ?> <?= e($row['unit']) ?> left
                        </span>

                        <span class="badge badge-<?= $isUrgent ? 'pending' : 'surplus' ?> surplus-card-days">
                            <?= e(formatDaysLeft($days)) ?> left
                        </span>
                    </div>

                    <div class="surplus-card-body">
                        <span class="surplus-card-category"><?= e($row['category']) ?></span>
                        <h3 class="surplus-card-title"><?= e($row['product_name']) ?></h3>

                        <div class="surplus-card-price">
                            <strong><?= formatCurrency((float) $row['current_value']) ?></strong>

                            <?php if ($reduction > 0): ?>
                                <s><?= formatCurrency((float) $row['base_price']) ?></s>
                                <span class="surplus-card-off"><?= rtrim(rtrim(number_format($reduction, 2), '0'), '.') ?>% off</span>
                            <?php endif; ?>
                        </div>

                        <ul class="surplus-card-meta">
                            <li>
                                <span class="surplus-card-icon">&#128197;</span>
                                <span>Expires <?= date('d/m/Y', strtotime($row['expiry_date'])) ?></span>
                            </li>
                        </ul>

                        <a class="button surplus-card-action" href="reserve.php?id=<?= (int) $row['item_id'] ?>">Reserve Product</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($merchantId): ?>

    <section class="section-head">
        <div>
            <a class="back-link" href="surplus.php">&larr; All merchants</a>
            <h2>Nothing available</h2>
        </div>
    </section>

    <div class="panel">
        <div class="empty">
            <strong>This merchant has no surplus right now</strong>
            Their products may have been reserved or may have expired.
        </div>
    </div>

<?php else: ?>

    <!-- ============ TAHAP 1: senarai merchant ============ -->

    <section class="section-head">
        <div>
            <h2>Available Surplus</h2>
            <p><?= count($merchants) ?> merchant<?= count($merchants) === 1 ? '' : 's' ?> <?= $hasLocation ? 'near you' : 'with surplus' ?></p>
        </div>
    </section>

    <?php if (!$hasLocation): ?>
        <div class="alert alert-info">
            Add your organisation's coordinates in
            <a href="profile.php">your profile</a> to sort merchants by distance.
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <form class="filter-bar" method="GET">
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product...">

                <select name="category">
                    <option value="all">All categories</option>

                    <?php foreach (productCategories() as $category): ?>
                        <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($hasLocation): ?>
                    <select name="radius">
                        <option value="all">Any distance</option>
                        <option value="2" <?= $radiusFilter === '2' ? 'selected' : '' ?>>Within 2 km</option>
                        <option value="5" <?= $radiusFilter === '5' ? 'selected' : '' ?>>Within 5 km</option>
                        <option value="10" <?= $radiusFilter === '10' ? 'selected' : '' ?>>Within 10 km</option>
                        <option value="25" <?= $radiusFilter === '25' ? 'selected' : '' ?>>Within 25 km</option>
                    </select>
                <?php endif; ?>

                <button class="button button-small" type="submit">Apply</button>

                <?php if ($search !== '' || $categoryFilter !== 'all' || $radiusFilter !== 'all'): ?>
                    <a class="button button-small button-outline" href="surplus.php">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$merchants): ?>
            <div class="empty">
                <strong>No surplus available right now</strong>
                <?= $radiusFilter !== 'all'
                    ? 'Try widening the distance filter.'
                    : "Merchants appear here automatically as their stock nears expiry." ?>
            </div>
        <?php else: ?>
            <div class="surplus-grid">
                <?php foreach ($merchants as $shop): ?>
                    <?php
                        $count = count($shop['items']);
                        $preview = $shop['items'][0];
                        $extra = $count - 1;
                        $reduction = (float) $preview['reduction_percentage'];
                        $isUrgent = $shop['soonest'] <= 1;
                    ?>

                    <a class="shop-card" href="surplus.php?merchant=<?= $shop['id'] ?>">
                        <div class="shop-card-media">
                            <?= shopCover($shop['image'], $shop['name'], 'shop-card-photo') ?>

                            <span class="badge badge-<?= $isUrgent ? 'pending' : 'surplus' ?> shop-card-count">
                                <?= $count ?> product<?= $count === 1 ? '' : 's' ?>
                            </span>
                        </div>

                        <div class="shop-card-body">
                            <h3 class="shop-card-title"><?= e($shop['name']) ?></h3>
                            <p class="shop-card-address"><?= e($shop['location']) ?></p>

                            <ul class="shop-card-stats">
                                <?php if ($shop['hours']): ?>
                                    <li title="<?= e($shop['hours']) ?>">
                                        <span class="surplus-card-icon">&#128336;</span><?= e(shortHours($shop['hours'])) ?>
                                    </li>
                                <?php endif; ?>

                                <li>
                                    <span class="surplus-card-icon">&#128230;</span><?= $count ?>
                                </li>

                                <?php if ($shop['distance'] !== null): ?>
                                    <li>
                                        <span class="surplus-card-icon">&#10148;</span><?= e(formatDistance($shop['distance'])) ?>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <div class="shop-card-preview">
                            <?= productThumbnail($preview['image_file'], $preview['category'], 'preview-thumb') ?>

                            <span class="shop-card-preview-text">
                                <span class="preview-name"><?= e($preview['product_name']) ?></span>

                                <span class="preview-price">
                                    <strong><?= formatCurrency((float) $preview['current_value']) ?></strong>

                                    <?php if ($reduction > 0): ?>
                                        <s><?= formatCurrency((float) $preview['base_price']) ?></s>
                                        <span class="surplus-card-off"><?= rtrim(rtrim(number_format($reduction, 2), '0'), '.') ?>% off</span>
                                    <?php endif; ?>
                                </span>
                            </span>

                            <?php if ($extra > 0): ?>
                                <span class="shop-card-more">+<?= $extra ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>