<?php

/*
 * Paparan sedia-cetak (print-ready) untuk impact report merchant.
 * Browser sendiri yang uruskan PDF melalui dialog print dia,
 * supaya sistem tak perlu library PDF berasingan dan dependency-nya.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

$profileStatement = $pdo->prepare(
    "SELECT display_id, business_name, business_type,
            pickup_location, phone, email
     FROM users WHERE user_id = ? LIMIT 1"
);

$profileStatement->execute([$merchantId]);
$profile = $profileStatement->fetch();

// Dua set filter berasingan: satu untuk tarikh surplus (stok),
// satu lagi untuk tarikh tempahan selesai (pickup) — dua lajur
// tarikh yang berbeza, walaupun guna nilai filter yang sama.
$stockFilter = filterClause('surplus_since', 'category');
$stockValues = filterValues();

$pickupFilter = filterClause('reservations.completed_at');
$pickupValues = filterValues();

// Jumlah keseluruhan item yang pernah jadi surplus, dan jumlah
// kuantiti yang terbazir (tak sempat dikutip sebelum luput).
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

// Jumlah tempahan yang berjaya dikutip (completed), kuantiti dan
// anggaran nilai pasaran yang berjaya diselamatkan daripada
// terbazir.
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

// Taburan surplus ikut kategori, untuk jadual "Surplus by Category".
$categoryStatement = $pdo->prepare(
    "SELECT category, COUNT(*) AS item_count
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

// Senarai NGO yang pernah kutip surplus dari merchant ni, susun
// ikut jumlah unit dikutip terbanyak dulu — untuk jadual "NGO Partners".
$partnerStatement = $pdo->prepare(
    "SELECT
        users.organization_name,
        users.display_id,
        COUNT(*) AS collections,
        SUM(reservations.quantity_requested) AS units
     FROM reservations
     INNER JOIN users
        ON reservations.ngo_id = users.user_id
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     WHERE reservations.merchant_id = ?
       AND reservations.status = 'completed'
       {$pickupFilter}
     GROUP BY users.user_id
     ORDER BY units DESC"
);

$partnerStatement->execute(array_merge([$merchantId], $pickupValues));
$partners = $partnerStatement->fetchAll();

$available = availableQuantitySql();

// Senarai penuh item surplus (dalam tempoh filter) untuk jadual
// terperinci "Surplus Items" di hujung report.
$itemStatement = $pdo->prepare(
    "SELECT
        inventory.product_name,
        inventory.category,
        inventory.quantity,
        inventory.unit,
        inventory.base_price,
        inventory.expiry_date,
        inventory.surplus_since,
        inventory.wasted_quantity,
        inventory.status,
        COALESCE((
            SELECT SUM(reservations.quantity_requested)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'completed'
        ), 0) AS collected_quantity
     FROM inventory
     WHERE inventory.merchant_id = ?
       AND inventory.removed_at IS NULL
       AND inventory.surplus_since IS NOT NULL
       {$stockFilter}
     ORDER BY inventory.surplus_since ASC"
);

$itemStatement->execute(array_merge([$merchantId], $stockValues));
$items = $itemStatement->fetchAll();

$unitsSaved = (int) $collected['collected_quantity'];
$unitsWasted = (int) $summary['total_wasted'];
$handled = $unitsSaved + $unitsWasted;

// Kadar pengagihan (%) = unit yang berjaya dikutip dibahagi
// jumlah unit yang "selesai" (dikutip + terbazir). null kalau
// belum ada unit yang selesai lagi, untuk elak bahagi dengan 0.
$rate = $handled > 0
    ? round($unitsSaved / $handled * 100)
    : null;

$totalSurplus = (int) $summary['total_surplus'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FreshTrack Impact Report — <?= e($profile['business_name']) ?></title>
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
            <h1>Impact Report</h1>
            <p class="print-sub">Surplus redistribution record</p>
            <p class="print-filter"><?= e($filterLabel) ?></p>
        </div>

        <div class="print-meta">
            <strong>FreshTrack</strong>
            <span>Generated <?= date('d M Y, g:i A') ?></span>
        </div>
    </header>

    <section class="print-block">
        <h2>Merchant</h2>

        <dl class="print-facts">
            <div>
                <dt>Business</dt>
                <dd><?= e($profile['business_name']) ?></dd>
            </div>

            <div>
                <dt>Merchant ID</dt>
                <dd><?= e($profile['display_id']) ?></dd>
            </div>

            <?php if ($profile['business_type']): ?>
                <div>
                    <dt>Type</dt>
                    <dd><?= e($profile['business_type']) ?></dd>
                </div>
            <?php endif; ?>

            <div>
                <dt>Pickup Location</dt>
                <dd><?= e($profile['pickup_location']) ?></dd>
            </div>

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
                <span class="print-figure"><?= $totalSurplus ?></span>
                <span class="print-figure-label">Items listed as surplus</span>
            </div>

            <div>
                <span class="print-figure"><?= $unitsSaved ?></span>
                <span class="print-figure-label">Units collected by NGOs</span>
            </div>

            <div>
                <span class="print-figure"><?= formatCurrency((float) $collected['value_saved']) ?></span>
                <span class="print-figure-label">Market value redistributed</span>
            </div>

            <div>
                <span class="print-figure"><?= $rate === null ? '&mdash;' : $rate . '%' ?></span>
                <span class="print-figure-label">Redistribution rate</span>
            </div>
        </div>

        <p class="print-note">
            Redistribution rate is the share of surplus units collected
            before expiry. <?= $unitsWasted ?> unit<?= $unitsWasted === 1 ? '' : 's' ?>
            passed the expiry date uncollected.
        </p>
    </section>

    <?php if ($byCategory): ?>
        <section class="print-block">
            <h2>Surplus by Category</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="align-right">Items</th>
                        <th class="align-right">Share</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($byCategory as $row): ?>
                        <?php
                        $count = (int) $row['item_count'];

                        $share = $totalSurplus > 0
                            ? round($count / $totalSurplus * 100)
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

    <?php if ($partners): ?>
        <section class="print-block">
            <h2>NGO Partners</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Organisation</th>
                        <th>NGO ID</th>
                        <th class="align-right">Collections</th>
                        <th class="align-right">Units</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($partners as $row): ?>
                        <tr>
                            <td><?= e($row['organization_name']) ?></td>
                            <td><?= e($row['display_id']) ?></td>
                            <td class="align-right"><?= (int) $row['collections'] ?></td>
                            <td class="align-right"><?= (int) $row['units'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($items): ?>
        <section class="print-block">
            <h2>Surplus Items</h2>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="align-right">Qty</th>
                        <th>Surplus Since</th>
                        <th>Expiry</th>
                        <th class="align-right">Collected</th>
                        <th class="align-right">Wasted</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($items as $row): ?>
                        <tr>
                            <td><?= e($row['product_name']) ?></td>
                            <td><?= e($row['category']) ?></td>
                            <td class="align-right"><?= (int) $row['quantity'] ?> <?= e($row['unit']) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['surplus_since'])) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['expiry_date'])) ?></td>
                            <td class="align-right"><?= (int) $row['collected_quantity'] ?></td>
                            <td class="align-right"><?= (int) $row['wasted_quantity'] ?></td>
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