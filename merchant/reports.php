<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

/*
 * Stock figures are filtered by the date an item became surplus,
 * since that is when it entered the redistribution process.
 */
$stockFilter = filterClause('surplus_since', 'category');
$stockValues = filterValues();

$summaryStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(surplus_since IS NOT NULL), 0) AS total_surplus,
        COALESCE(SUM(wasted_quantity), 0) AS total_wasted
     FROM inventory
     WHERE merchant_id = ?
       AND removed_at IS NULL
       AND surplus_since IS NOT NULL
       {$stockFilter}"
);

$summaryStatement->execute(array_merge([$merchantId], $stockValues));
$summary = $summaryStatement->fetch();

/*
 * Collection figures are filtered by the date the pickup was
 * confirmed, so a report for September counts what actually
 * left the premises in September.
 */
$pickupFilter = filterClause('reservations.completed_at');
$pickupValues = filterValues();

/*
 * Value is measured at base price, not the reduced value: the
 * point is the market worth of food that did not go to waste,
 * and an expired item's current value is zero.
 */
$collectedStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS collected_requests,
        COALESCE(SUM(reservations.quantity_requested), 0) AS collected_quantity,
        COALESCE(SUM(reservations.quantity_requested * inventory.base_price), 0) AS value_saved
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.merchant_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}"
);

$collectedStatement->execute(array_merge([$merchantId], $pickupValues));
$collected = $collectedStatement->fetch();

$requestFilter = filterClause('reservations.request_date');
$requestValues = filterValues();

$reservationCountStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.merchant_id = ?
       {$requestFilter}"
);

$reservationCountStatement->execute(array_merge([$merchantId], $requestValues));
$totalReservations = (int) $reservationCountStatement->fetchColumn();

$categoryStatement = $pdo->prepare(
    "SELECT
        category,
        COUNT(*) AS item_count
     FROM inventory
     WHERE merchant_id = ?
       AND removed_at IS NULL
       AND surplus_since IS NOT NULL
       {$stockFilter}
     GROUP BY category
     ORDER BY item_count DESC"
);

$categoryStatement->execute(array_merge([$merchantId], $stockValues));
$byCategory = $categoryStatement->fetchAll();

/*
 * Daily rather than monthly: perishable stock turns over in
 * days, so a month-wide bucket hides the pattern the system
 * actually runs on. Without a date filter the chart falls back
 * to the last fortnight.
 */
$chartWindow = ($filterStart === null && $filterEnd === null)
    ? ' AND reservations.completed_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)'
    : '';

$dailyStatement = $pdo->prepare(
    "SELECT
        DATE(reservations.completed_at) AS day_key,
        DATE_FORMAT(reservations.completed_at, '%d %b') AS day_label,
        SUM(reservations.quantity_requested) AS collected_quantity
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.merchant_id = ?
       AND reservations.status = 'completed'
       AND reservations.completed_at IS NOT NULL
       {$pickupFilter}
       {$chartWindow}
     GROUP BY day_key, day_label
     ORDER BY day_key ASC"
);

$dailyStatement->execute(array_merge([$merchantId], $pickupValues));
$daily = $dailyStatement->fetchAll();

/*
 * Which products actually moved the most food. Ranked by
 * quantity collected, so it reflects redistribution rather
 * than how much was listed.
 */
$topStatement = $pdo->prepare(
    "SELECT
        inventory.product_name,
        inventory.category,
        inventory.unit,
        MAX(inventory.image_file) AS image_file,
        SUM(reservations.quantity_requested) AS collected_quantity,
        COUNT(DISTINCT reservations.ngo_id) AS ngo_count
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.merchant_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}
     GROUP BY inventory.product_name, inventory.category, inventory.unit
     ORDER BY collected_quantity DESC
     LIMIT 3"
);

$topStatement->execute(array_merge([$merchantId], $pickupValues));
$topItems = $topStatement->fetchAll();

$totalSurplusCount = (int) $summary['total_surplus'];

$maxDaily = 0;

foreach ($daily as $day) {
    $maxDaily = max($maxDaily, (int) $day['collected_quantity']);
}

$unitsSaved = (int) $collected['collected_quantity'];
$unitsWasted = (int) $summary['total_wasted'];
$totalHandled = $unitsSaved + $unitsWasted;

$redistributionRate = $totalHandled > 0
    ? round($unitsSaved / $totalHandled * 100)
    : null;

$pageTitle = 'Reports';
$activePage = 'reports';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Reports</h2>
        <p><?= e($filterLabel) ?></p>
    </div>

    <div class="export-actions">
        <a class="button button-outline button-small" href="export-csv.php<?= $filterQuery ? '?' . e($filterQuery) : '' ?>">Export CSV</a>
        <a class="button button-small" href="print.php<?= $filterQuery ? '?' . e($filterQuery) : '' ?>">Print / PDF</a>
    </div>
</section>

<div class="panel report-filter-panel">
    <form class="report-filter" method="GET">
        <div class="field">
            <label for="start">Start Date</label>
            <input id="start" type="date" name="start" value="<?= e($filterStart ?? '') ?>">
        </div>

        <div class="field">
            <label for="end">End Date</label>
            <input id="end" type="date" name="end" value="<?= e($filterEnd ?? '') ?>">
        </div>

        <div class="field">
            <label for="category">Category</label>

            <select id="category" name="category">
                <option value="all">All categories</option>

                <?php foreach (productCategories() as $category): ?>
                    <option value="<?= e($category) ?>" <?= $filterCategory === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="report-filter-actions">
            <button class="button button-small" type="submit">Apply</button>

            <?php if ($filterActive): ?>
                <a class="button button-small button-outline" href="reports.php">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<section class="stat-grid page-reports">
    <article class="stat-card">
        <span class="stat-icon icon-green"><img src="<?= BASE_URL ?>/assets/images/available.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Total Surplus Products</p>
            <p class="stat-value"><?= $totalSurplusCount ?></p>
            <span class="stat-unit">products</span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-blue"><img src="<?= BASE_URL ?>/assets/images/inventory.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Products Collected</p>
            <p class="stat-value"><?= $unitsSaved ?></p>
            <span class="stat-unit">units</span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-gold"><img src="<?= BASE_URL ?>/assets/images/value.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Value Saved</p>
            <p class="stat-value"><?= formatCurrency((float) $collected['value_saved']) ?></p>
            <span class="stat-unit">market value redistributed</span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-purple"><img src="<?= BASE_URL ?>/assets/images/reserve.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">NGO Reservations</p>
            <p class="stat-value"><?= $totalReservations ?></p>
            <span class="stat-unit">requests</span>
        </div>
    </article>
</section>

<section class="panel-grid">
    <div class="panel">
        <div class="panel-head">
            <h3>Product Collected<?= ($filterStart || $filterEnd) ? '' : ' (Last 14 Days)' ?></h3>
        </div>

        <div class="panel-body">
            <?php if (!$daily): ?>
                <div class="empty">
                    <strong>No collections yet</strong>
                    Data appears once NGOs complete their first pickup.
                </div>
            <?php else: ?>
                <div class="chart-frame">
                    <div class="chart-axis">
                        <span><?= $maxDaily ?></span>
                        <span><?= round($maxDaily * 0.75) ?></span>
                        <span><?= round($maxDaily * 0.5) ?></span>
                        <span><?= round($maxDaily * 0.25) ?></span>
                        <span>0</span>
                    </div>

                    <div class="chart-plot">
                        <div class="chart-grid">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>

                        <div class="bar-chart">
                            <?php foreach ($daily as $day): ?>
                                <?php
                                $quantity = (int) $day['collected_quantity'];

                                $height = $maxDaily > 0
                                    ? max(6, round($quantity / $maxDaily * 100))
                                    : 6;
                                ?>

                                <div class="bar-column">
                                    <span class="bar-value"><?= $quantity ?></span>
                                    <span class="bar" style="height: <?= $height ?>%"></span>
                                    <span class="bar-label"><?= e($day['day_label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="chart-legend">
                    <span class="legend-dot"></span>
                    <span>Product collected (units)</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3>Surplus by Category</h3>
        </div>

        <div class="panel-body">
            <?php if (!$byCategory): ?>
                <div class="empty">
                    <strong>No surplus yet</strong>
                    Categories appear as products reach surplus status.
                </div>
            <?php else: ?>
                <?php
                $radius = 60;
                $circumference = 2 * M_PI * $radius;
                $offset = 0;
                ?>

                <div class="donut-wrap">
                    <div class="donut-chart">
                        <svg viewBox="0 0 160 160">
                            <circle cx="80" cy="80" r="<?= $radius ?>" fill="none" stroke="#f0f0f0" stroke-width="22"></circle>

                            <?php foreach ($byCategory as $category): ?>
                                <?php
                                $count = (int) $category['item_count'];

                                $share = $totalSurplusCount > 0
                                    ? $count / $totalSurplusCount
                                    : 0;

                                $length = $circumference * $share;
                                $colour = categoryColour($category['category']);
                                ?>

                                <circle cx="80" cy="80" r="<?= $radius ?>" fill="none" stroke="<?= $colour ?>" stroke-width="22" stroke-dasharray="<?= round($length, 2) ?> <?= round($circumference - $length, 2) ?>" stroke-dashoffset="<?= round(-$offset, 2) ?>" transform="rotate(-90 80 80)"></circle>

                                <?php $offset += $length; ?>
                            <?php endforeach; ?>
                        </svg>

                        <div class="donut-centre">
                            <span class="donut-total"><?= $totalSurplusCount ?></span>
                            <span class="donut-caption">products</span>
                        </div>
                    </div>

                    <div class="donut-legend">
                        <?php foreach ($byCategory as $category): ?>
                            <?php
                            $count = (int) $category['item_count'];

                            $percent = $totalSurplusCount > 0
                                ? round($count / $totalSurplusCount * 100)
                                : 0;

                            $colour = categoryColour($category['category']);
                            ?>

                            <div class="legend-row">
                                <span class="legend-dot" style="background: <?= $colour ?>"></span>
                                <span class="legend-name"><?= e($category['category']) ?></span>
                                <span class="legend-value"><?= $count ?> <span class="legend-percent">(<?= $percent ?>%)</span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="panel-grid">
    <div class="panel">
        <div class="panel-head">
            <h3>Top Contributing Products</h3>
        </div>

        <div class="panel-body">
            <?php if (!$topItems): ?>
                <div class="empty">
                    <strong>No collections yet</strong>
                    Your most redistributed products will be ranked here.
                </div>
            <?php else: ?>
                <div class="rank-list">
                    <?php foreach ($topItems as $index => $top): ?>
                        <div class="rank-row">
                            <span class="rank-number"><?= $index + 1 ?></span>

                            <?= productThumbnail($top['image_file'], $top['category']) ?>

                            <span class="rank-body">
                                <span class="rank-name"><?= e($top['product_name']) ?></span>
                                <span class="rank-meta"><?= e($top['category']) ?> &middot; <?= (int) $top['ngo_count'] ?> NGO<?= (int) $top['ngo_count'] === 1 ? '' : 's' ?></span>
                            </span>

                            <span class="rank-value"><?= (int) $top['collected_quantity'] ?> <?= e($top['unit']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3>Waste Check</h3>
        </div>

        <div class="panel-body">
            <div class="rate-headline">
                <span class="rate-value"><?= $redistributionRate === null ? '&mdash;' : $redistributionRate . '%' ?></span>
                <span class="rate-label">of surplus successfully redistributed</span>
            </div>

            <div class="waste-split">
                <div class="waste-cell waste-saved">
                    <span class="waste-value"><?= $unitsSaved ?></span>
                    <span class="waste-label">Units saved</span>
                </div>

                <div class="waste-cell waste-lost">
                    <span class="waste-value"><?= $unitsWasted ?></span>
                    <span class="waste-label">Units wasted</span>
                </div>
            </div>

            <p class="stat-note">Uncollected quantity is recorded when an product passes its expiry date, so the system reports what it failed to save as well as what it saved.</p>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
