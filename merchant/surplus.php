<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

$tab = $_GET['tab'] ?? 'available';
$search = trim($_GET['search'] ?? '');

if (!in_array($tab, ['available', 'reserved', 'collected', 'expired'], true)) {
    $tab = 'available';
}

$available = availableQuantitySql();

/*
 * One query serves all four tabs. Each surplus item carries its
 * open quantity plus a summary of its reservations, and the tab
 * simply decides which rows to show.
 */
$surplusStatement = $pdo->prepare(
    "SELECT
        inventory.item_id,
        inventory.product_name,
        inventory.category,
        inventory.quantity,
        inventory.unit,
        inventory.base_price,
        inventory.current_value,
        inventory.reduction_percentage,
        inventory.expiry_date,
        inventory.surplus_since,
        inventory.wasted_quantity,
        inventory.expired_at,
        inventory.status,
        inventory.image_file,
        DATEDIFF(inventory.expiry_date, CURDATE()) AS days_remaining,
        {$available} AS available_quantity,
        (
            SELECT COUNT(*)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'pending'
        ) AS pending_count,
        (
            SELECT COUNT(*)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'approved'
        ) AS approved_count,
        (
            SELECT COUNT(*)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'completed'
        ) AS completed_count,
        COALESCE((
            SELECT SUM(reservations.quantity_requested)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'completed'
        ), 0) AS collected_quantity,
        (
            SELECT GROUP_CONCAT(
                DISTINCT users.organization_name
                SEPARATOR ', '
            )
            FROM reservations
            INNER JOIN users
                ON reservations.ngo_id = users.user_id
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status IN
                  ('pending', 'approved', 'completed')
        ) AS ngo_names
     FROM inventory
     WHERE inventory.merchant_id = ?
       AND inventory.removed_at IS NULL
       AND inventory.status IN ('surplus', 'expired')
       AND inventory.surplus_since IS NOT NULL
       AND (? = '' OR inventory.product_name LIKE ?)
     ORDER BY inventory.expiry_date ASC"
);

$surplusStatement->execute([$merchantId, $search, '%' . $search . '%']);
$allItems = $surplusStatement->fetchAll();

$tabbed = [
    'available' => [],
    'reserved' => [],
    'collected' => [],
    'expired' => []
];

foreach ($allItems as $row) {
    if ((int) $row['completed_count'] > 0) {
        $tabbed['collected'][] = $row;
    }

    if (
        (int) $row['pending_count'] > 0
        || (int) $row['approved_count'] > 0
    ) {
        $tabbed['reserved'][] = $row;
    }

    if (
        $row['status'] === 'surplus'
        && (int) $row['available_quantity'] > 0
    ) {
        $tabbed['available'][] = $row;
    }

    /*
     * Only items that actually went to waste belong here. One
     * that expired after every unit was collected was a success,
     * not a loss, and showing it would distort the waste count.
     */
    if (
        $row['status'] === 'expired'
        && (int) $row['wasted_quantity'] > 0
    ) {
        $tabbed['expired'][] = $row;
    }
}

$items = $tabbed[$tab];

/*
 * The Expired tab is the honest counterpart to the other three:
 * it reports what the system did not manage to redistribute.
 */
$totalWasted = 0;

foreach ($tabbed['expired'] as $row) {
    $totalWasted += (int) $row['wasted_quantity'];
}

$pageTitle = 'Donations';
$activePage = 'surplus';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Surplus Products</h2>
    </div>
</section>

<div class="tabs">
    <a class="tab <?= $tab === 'available' ? 'tab-active' : '' ?>" href="?tab=available">Available<span class="tab-count"><?= count($tabbed['available']) ?: '' ?></span></a>
    <a class="tab <?= $tab === 'reserved' ? 'tab-active' : '' ?>" href="?tab=reserved">Reserved<span class="tab-count"><?= count($tabbed['reserved']) ?: '' ?></span></a>
    <a class="tab <?= $tab === 'collected' ? 'tab-active' : '' ?>" href="?tab=collected">Collected<span class="tab-count"><?= count($tabbed['collected']) ?: '' ?></span></a>
    <a class="tab <?= $tab === 'expired' ? 'tab-active' : '' ?>" href="?tab=expired">Expired<span class="tab-count"><?= count($tabbed['expired']) ?: '' ?></span></a>
</div>

<div class="panel">
    <div class="panel-head">
        <form class="filter-bar" method="GET">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product...">

            <button class="button button-small" type="submit">Apply</button>

            <?php if ($search !== ''): ?>
                <a class="button button-small button-outline" href="?tab=<?= e($tab) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($tab === 'expired' && $items): ?>
        <div class="waste-summary">
            <strong><?= $totalWasted ?></strong> unit<?= $totalWasted === 1 ? '' : 's' ?>
            across <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>
            expired before an NGO could collect them.
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="empty">
            <?php if ($tab === 'expired'): ?>
                <strong>Nothing wasted</strong>
                Every surplus item so far was collected before expiry.
            <?php else: ?>
                <strong>Nothing here yet</strong>
                Items appear automatically once they reach the donation threshold.
            <?php endif; ?>
        </div>
    <?php else: ?>
                <div class="surplus-grid page-merchant-surplus">
            <?php foreach ($items as $row): ?>
                <?php
                if ($tab === 'expired') {
                    $label = 'Expired';
                    $badge = 'expired';
                } elseif ((int) $row['pending_count'] > 0) {
                    $label = 'Pending';
                    $badge = 'pending';
                } elseif ((int) $row['approved_count'] > 0) {
                    $label = 'Reserved';
                    $badge = 'approved';
                } elseif ((int) $row['completed_count'] > 0) {
                    $label = 'Collected';
                    $badge = 'completed';
                } elseif ($row['status'] === 'expired') {
                    $label = 'Expired';
                    $badge = 'expired';
                } else {
                    $label = 'Available';
                    $badge = 'available';
                }

                $days = (int) $row['days_remaining'];
                $reduction = (float) $row['reduction_percentage'];
                $isExpired = $row['status'] === 'expired'
                    && $tab === 'expired';
                $isPartial = (int) $row['available_quantity'] < (int) $row['quantity'];
                $wasted = (int) $row['wasted_quantity'];
                $collected = (int) $row['collected_quantity'];
                $wastePercent = (int) $row['quantity'] > 0
                    ? (int) round($wasted / (int) $row['quantity'] * 100)
                    : 0;
                ?>

                <article class="surplus-card <?= $isExpired ? 'surplus-card-wasted' : '' ?>">
                    <div class="surplus-card-media">
                        <?= productThumbnail($row['image_file'], $row['category'], 'surplus-card-photo') ?>

                        <span class="surplus-card-stock">
                            <?php if ($isExpired): ?>
                                <?= $wasted ?> <?= e($row['unit']) ?> wasted
                            <?php elseif ($tab === 'collected'): ?>
                                <?= $collected ?> <?= e($row['unit']) ?> saved
                            <?php elseif ($tab === 'reserved'): ?>
                                <?= (int) $row['quantity'] - (int) $row['available_quantity'] ?> <?= e($row['unit']) ?> reserved
                            <?php else: ?>
                                <?= (int) $row['available_quantity'] ?> <?= e($row['unit']) ?> left
                            <?php endif; ?>
                        </span>

                        <span class="badge badge-<?= $badge ?> surplus-card-days"><?= e($label) ?></span>
                    </div>

                    <div class="surplus-card-body">
                        <span class="surplus-card-category"><?= e($row['category']) ?></span>
                        <h3 class="surplus-card-title"><?= e($row['product_name']) ?></h3>

                        <?php if ($isExpired): ?>
                            <div class="waste-bar">
                                <span style="width: <?= max(0, 100 - $wastePercent) ?>%"></span>
                            </div>

                            <p class="waste-line">
                                <?= $collected ?> of <?= (int) $row['quantity'] ?> <?= e($row['unit']) ?> collected
                                &middot; <strong><?= $wastePercent ?>% wasted</strong>
                            </p>
                        <?php else: ?>
                            <div class="surplus-card-price">
                                <strong><?= formatCurrency((float) $row['current_value']) ?></strong>

                                <?php if ($reduction > 0): ?>
                                    <s><?= formatCurrency((float) $row['base_price']) ?></s>
                                    <span class="surplus-card-off"><?= rtrim(rtrim(number_format($reduction, 2), '0'), '.') ?>% off</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <ul class="surplus-card-meta">
                            <li>
                                <span class="surplus-card-icon">&#128197;</span>
                                <span>
                                    <?php if ($isExpired && $row['expired_at']): ?>
                                        Expired <?= date('d M Y', strtotime($row['expired_at'])) ?>
                                    <?php elseif ($days < 0): ?>
                                        Expired <?= date('d M Y', strtotime($row['expiry_date'])) ?>
                                    <?php else: ?>
                                        Expires <?= date('d M Y', strtotime($row['expiry_date'])) ?>
                                        <span class="days-hint <?= daysUrgencyClass($days) ?>">(<?= e(formatDaysLeft($days)) ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </li>

                            <li>
                                <span class="surplus-card-icon">&#8635;</span>
                                <span>Surplus since <?= date('d/m/Y', strtotime($row['surplus_since'])) ?></span>
                            </li>

                            <li>
                                <span class="surplus-card-icon">&#127970;</span>
                                <span>
                                    <?= $row['ngo_names']
                                        ? e($row['ngo_names'])
                                        : '<span class="value-none">No reservation received</span>' ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>