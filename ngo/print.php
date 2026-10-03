<?php

/*
 * Paparan sedia-cetak (print-ready) untuk collection report NGO.
 * Browser sendiri yang uruskan PDF melalui dialog print dia,
 * supaya sistem tak perlu library PDF berasingan dan dependency-nya.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];

$profileStatement = $pdo->prepare(
    "SELECT display_id, organization_name, address, phone, email
     FROM users WHERE user_id = ? LIMIT 1"
);

$profileStatement->execute([$ngoId]);
$profile = $profileStatement->fetch();

$pickupFilter = filterClause('reservations.completed_at');
$pickupValues = filterValues();

$requestFilter = filterClause('reservations.request_date');
$requestValues = filterValues();

// Jumlah unit yang berjaya dikutip, dan anggaran nilai pasaran
// makanan yang diterima (dikira pada base_price merchant).
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

// Hanya kira reservation yang sampai ke keputusan dalam kawalan
// NGO sendiri: berjaya dikutip (completed) atau dibiarkan sampai
// luput (not_collected). Rejection & cancellation awal tak dikira.
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

// Kadar kutipan (%) = berjaya dikutip dibahagi jumlah yang
// "selesai" (collected + missed). null kalau belum ada yang
// selesai lagi, untuk elak bahagi dengan 0.
$collectionRate = $concluded > 0
    ? round((int) $followThrough['collected'] / $concluded * 100)
    : null;

// Taburan kutipan ikut kategori, untuk jadual "Collected by Category".
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

// Senarai merchant yang pernah bekalkan surplus kepada NGO ni,
// susun ikut jumlah unit terbanyak — untuk jadual "Merchant Partners".
$sourceStatement = $pdo->prepare(
    "SELECT
        users.business_name,
        users.display_id,
        COUNT(*) AS collections,
        SUM(reservations.quantity_requested) AS units
     FROM reservations
     INNER JOIN users
        ON reservations.merchant_id = users.user_id
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.ngo_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}
     GROUP BY users.user_id
     ORDER BY units DESC"
);

$sourceStatement->execute(array_merge([$ngoId], $pickupValues));
$sources = $sourceStatement->fetchAll();

// Sejarah PENUH reservation (semua status) dalam tempoh filter,
// untuk jadual terperinci "Reservation History" di hujung report.
$historyStatement = $pdo->prepare(
    "SELECT
        reservations.quantity_requested,
        reservations.request_date,
        reservations.completed_at,
        reservations.status,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        users.business_name
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     INNER JOIN users
        ON reservations.merchant_id = users.user_id
     WHERE reservations.ngo_id = ?
       {$requestFilter}
     ORDER BY reservations.request_date ASC"
);

$historyStatement->execute(array_merge([$ngoId], $requestValues));
$history = $historyStatement->fetchAll();

$totalCollected = (int) $collected['collected_quantity'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FreshTrack Collection Report — <?= e($profile['organization_name']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
</head>
<body class="print-page">

<div class="print-bar no-print">
    <a class="button button-outline" href="reports.php">&larr; Back to Reports</a>
    <button class="button" type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="print-sheet">
    <header class="print-head">
        <div>
            <h1>Collection Report</h1>
            <p class="print-sub">Surplus food received through FreshTrack</p>
            <p class="print-filter"><?= e($filterLabel) ?></p>
        </div>

        <div class="print-meta">
            <strong>FreshTrack</strong>
            <span>Generated <?= date('d M Y, g:i A') ?></span>
        </div>
    </header>

    <section class="print-block">
        <h2>Organisation</h2>

        <dl class="print-facts">
            <div>
                <dt>Name</dt>
                <dd><?= e($profile['organization_name']) ?></dd>
            </div>

            <div>
                <dt>NGO ID</dt>
                <dd><?= e($profile['display_id']) ?></dd>
            </div>

            <?php if ($profile['address']): ?>
                <div>
                    <dt>Address</dt>
                    <dd><?= e($profile['address']) ?></dd>
                </div>
            <?php endif; ?>

            <div>
                <dt>Contact</dt>
                <dd><?= e($profile['phone']) ?></dd>
            </div>
        </dl>
    </section>

    <section class="print-block">
        <h2>Summary</h2>

        <div class="print-figures">
            <div>
                <span class="print-figure"><?= $totalCollected ?></span>
                <span class="print-figure-label">Units collected</span>
            </div>

            <div>
                <span class="print-figure"><?= formatCurrency((float) $collected['value_received']) ?></span>
                <span class="print-figure-label">Market value saved</span>
            </div>

            <div>
                <span class="print-figure"><?= $collectionRate === null ? '&mdash;' : $collectionRate . '%' ?></span>
                <span class="print-figure-label">Collection rate</span>
            </div>

            <div>
                <span class="print-figure"><?= count($sources) ?></span>
                <span class="print-figure-label">Merchant partners</span>
            </div>
        </div>

        <p class="print-note">
            Collection rate counts reservations that reached an outcome
            within this organisation's control:
            <?= (int) $followThrough['collected'] ?> collected of
            <?= $concluded ?> concluded. Rejections and early
            cancellations are excluded.
        </p>
    </section>

    <?php if ($byCategory): ?>
        <section class="print-block">
            <h2>Collected by Category</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="align-right">Units</th>
                        <th class="align-right">Share</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($byCategory as $row): ?>
                        <?php
                        $count = (int) $row['collected_quantity'];

                        $share = $totalCollected > 0
                            ? round($count / $totalCollected * 100)
                            : 0;
                        ?>

                        <tr>
                            <td><?= e($row['category']) ?></td>
                            <td class="align-right"><?= $count ?></td>
                            <td class="align-right"><?= $share ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($sources): ?>
        <section class="print-block">
            <h2>Merchant Partners</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Merchant</th>
                        <th>Merchant ID</th>
                        <th class="align-right">Collections</th>
                        <th class="align-right">Units</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($sources as $row): ?>
                        <tr>
                            <td><?= e($row['business_name']) ?></td>
                            <td><?= e($row['display_id']) ?></td>
                            <td class="align-right"><?= (int) $row['collections'] ?></td>
                            <td class="align-right"><?= (int) $row['units'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($history): ?>
        <section class="print-block">
            <h2>Reservation History</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Merchant</th>
                        <th class="align-right">Qty</th>
                        <th>Requested</th>
                        <th>Collected</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?= e($row['product_name']) ?></td>
                            <td><?= e($row['category']) ?></td>
                            <td><?= e($row['business_name']) ?></td>
                            <td class="align-right"><?= (int) $row['quantity_requested'] ?> <?= e($row['unit']) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['request_date'])) ?></td>
                            <td><?= $row['completed_at'] ? date('d/m/Y', strtotime($row['completed_at'])) : '&mdash;' ?></td>
                            <td><?= e(statusLabel($row['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <footer class="print-foot">
        FreshTrack &mdash; Web-Based Perishable Grocery Inventory and
        Surplus Management System
    </footer>
</div>

</body>
</html>