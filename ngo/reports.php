<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];

/*
 * Request ditapis ikut bila ia DIBUAT; kutipan pula ditapis ikut
 * bila pickup DISAHKAN. Jadi report untuk bulan September kira
 * apa yang betul-betul keluar dari merchant dalam bulan September.
 */
$requestFilter = filterClause('reservations.request_date');
$requestValues = filterValues();

$pickupFilter = filterClause('reservations.completed_at');
$pickupValues = filterValues();

$summaryStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(reservations.status = 'completed'), 0) AS completed_requests,
        COALESCE(SUM(reservations.status = 'pending'), 0) AS pending_requests,
        COALESCE(SUM(reservations.status = 'approved'), 0) AS upcoming_requests
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.ngo_id = ?
       {$requestFilter}"
);

$summaryStatement->execute(array_merge([$ngoId], $requestValues));
$summary = $summaryStatement->fetch();

/*
 * Nilai diukur pada base_price, bukan nilai yang dah dikurangkan:
 * tujuannya nak tunjuk nilai pasaran makanan yang berjaya
 * diselamatkan daripada terbazir — dan nilai semasa item yang
 * dah expired pun sifar (0), jadi base_price lebih bermakna di sini.
 */
$collectedStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(reservations.quantity_requested), 0) AS collected_quantity,
        COALESCE(SUM(reservations.quantity_requested * inventory.base_price), 0) AS value_received
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.ngo_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}"
);

$collectedStatement->execute(array_merge([$ngoId], $pickupValues));
$collected = $collectedStatement->fetch();

/*
 * Sejauh mana NGO ni boleh dipercayai "ikut janji". Reservation
 * yang dia buat tapi berakhir not_collected dikira SALAH dia;
 * yang ditolak merchant (rejected) TIDAK dikira, sebab keputusan
 * tu bukan dalam kawalan NGO; cancellation pun tak dikira, sebab
 * batal awal lepaskan stok untuk orang lain guna.
 */
$followThroughStatement = $pdo->prepare(
    "SELECT
        COALESCE(SUM(reservations.status = 'completed'), 0) AS collected,
        COALESCE(SUM(reservations.status = 'not_collected'), 0) AS missed
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.ngo_id = ?
       AND reservations.status IN ('completed', 'not_collected')
       {$requestFilter}"
);

$followThroughStatement->execute(array_merge([$ngoId], $requestValues));
$followThrough = $followThroughStatement->fetch();

$concluded = (int) $followThrough['collected']
    + (int) $followThrough['missed'];

$collectionRate = $concluded > 0
    ? round((int) $followThrough['collected'] / $concluded * 100)
    : null;

$categoryStatement = $pdo->prepare(
    "SELECT
        inventory.category,
        SUM(reservations.quantity_requested) AS collected_quantity
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.ngo_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}
     GROUP BY inventory.category
     ORDER BY collected_quantity DESC"
);

$categoryStatement->execute(array_merge([$ngoId], $pickupValues));
$byCategory = $categoryStatement->fetchAll();

/*
 * Harian, bukan bulanan: stok mudah rosak berubah dalam hitungan
 * hari, dan satu item cuma kekal surplus selama 3 hari, jadi
 * kumpulan yang lebih luas akan sembunyikan corak sebenar sistem
 * ni berfungsi.
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
     WHERE reservations.ngo_id = ?
       AND reservations.status = 'completed'
       AND reservations.completed_at IS NOT NULL
       {$pickupFilter}
       {$chartWindow}
     GROUP BY day_key, day_label
     ORDER BY day_key ASC"
);

$dailyStatement->execute(array_merge([$ngoId], $pickupValues));
$daily = $dailyStatement->fetchAll();

$maxDaily = 0;

foreach ($daily as $day) {
    $maxDaily = max($maxDaily, (int) $day['collected_quantity']);
}

$totalCollected = (int) $collected['collected_quantity'];

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
            <p class="stat-label">Items Collected</p>
            <p class="stat-value"><?= $totalCollected ?></p>
            <span class="stat-unit">units</span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-gold"><img src="<?= BASE_URL ?>/assets/images/value.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Food Value Saved</p>
            <p class="stat-value"><?= formatCurrency((float) $collected['value_received']) ?></p>
            <span class="stat-unit">market value redistributed</span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-teal"><img src="<?= BASE_URL ?>/assets/images/redistribute.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Collection Rate</p>
            <p class="stat-value"><?= $collectionRate === null ? '&mdash;' : $collectionRate . '%' ?></p>
            <span class="stat-unit">
                <?= $collectionRate === null
                    ? 'no concluded reservations yet'
                    : (int) $followThrough['collected'] . ' of ' . $concluded . ' collected' ?>
            </span>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon icon-purple"><img src="<?= BASE_URL ?>/assets/images/reserve.png" alt=""></span>

        <div class="stat-body">
            <p class="stat-label">Active Requests</p>
            <p class="stat-value"><?= (int) $summary['pending_requests'] + (int) $summary['upcoming_requests'] ?></p>
            <span class="stat-unit">pending or approved</span>
        </div>
    </article>
</section>

<section class="panel-grid">
    <div class="panel">
        <div class="panel-head">
            <h3>Food Collected<?= ($filterStart || $filterEnd) ? '' : ' (Last 14 Days)' ?></h3>
        </div>

        <div class="panel-body">
            <?php if (!$daily): ?>
                <div class="empty">
                    <strong>No collections yet</strong>
                    Data appears after your first completed pickup.
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
                    <span>Food collected (units)</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3>Collected by Category</h3>
        </div>

        <div class="panel-body">
            <?php if (!$byCategory): ?>
                <div class="empty">
                    <strong>Nothing collected yet</strong>
                    Categories appear once pickups are completed.
                </div>
            <?php else: ?>
                <?php
                /*
                 * Cincin (ring) dilukis dengan satu bulatan SVG bagi
                 * setiap slice. Setiap satu guna stroke-dasharray
                 * untuk papar nisbah bahagian dia je sepanjang lilitan
                 * bulatan, dan stroke-dashoffset untuk putar lepas
                 * slice-slice yang dah dilukis sebelum ni.
                 */
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
                                $count = (int) $category['collected_quantity'];

                                $share = $totalCollected > 0
                                    ? $count / $totalCollected
                                    : 0;

                                $length = $circumference * $share;
                                $colour = categoryColour($category['category']);
                                ?>

                                <circle cx="80" cy="80" r="<?= $radius ?>" fill="none" stroke="<?= $colour ?>" stroke-width="22" stroke-dasharray="<?= round($length, 2) ?> <?= round($circumference - $length, 2) ?>" stroke-dashoffset="<?= round(-$offset, 2) ?>" transform="rotate(-90 80 80)"></circle>

                                <?php $offset += $length; ?>
                            <?php endforeach; ?>
                        </svg>

                        <div class="donut-centre">
                            <span class="donut-total"><?= $totalCollected ?></span>
                            <span class="donut-caption">units</span>
                        </div>
                    </div>

                    <div class="donut-legend">
                        <?php foreach ($byCategory as $category): ?>
                            <?php
                            $count = (int) $category['collected_quantity'];

                            $percent = $totalCollected > 0
                                ? round($count / $totalCollected * 100)
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

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>