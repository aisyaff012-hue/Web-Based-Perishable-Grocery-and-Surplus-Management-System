<?php

/*
 * Streams the merchant's surplus record as CSV. Nothing is
 * written to disk: the file is built in memory and pushed
 * straight to the browser, so there is no upload folder to
 * secure and no stale exports to clean up.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];
$businessName = $_SESSION['entity_name'] ?? 'merchant';

$available = availableQuantitySql();

$stockFilter = filterClause('inventory.surplus_since');
$stockValues = filterValues();

$statement = $pdo->prepare(
    "SELECT
        inventory.product_name,
        inventory.category,
        inventory.quantity,
        inventory.unit,
        inventory.base_price,
        inventory.current_value,
        inventory.reduction_percentage,
        inventory.expiry_date,
        inventory.surplus_since,
        inventory.expired_at,
        inventory.wasted_quantity,
        inventory.status,
        {$available} AS available_quantity,
        COALESCE((
            SELECT SUM(reservations.quantity_requested)
            FROM reservations
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'completed'
        ), 0) AS collected_quantity,
        (
            SELECT GROUP_CONCAT(
                DISTINCT users.organization_name
                SEPARATOR '; '
            )
            FROM reservations
            INNER JOIN users
                ON reservations.ngo_id = users.user_id
            WHERE reservations.item_id = inventory.item_id
              AND reservations.status = 'completed'
        ) AS collected_by
     FROM inventory
     WHERE inventory.merchant_id = ?
       AND inventory.removed_at IS NULL
       AND inventory.surplus_since IS NOT NULL
       {$stockFilter}
     ORDER BY inventory.surplus_since ASC, inventory.product_name ASC"
);

$statement->execute(array_merge([$merchantId], $stockValues));
$rows = $statement->fetchAll();

$fileName = 'freshtrack-surplus-'
    . preg_replace('/[^a-z0-9]+/i', '-', $businessName) . '-'
    . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$output = fopen('php://output', 'w');

// Excel reads UTF-8 correctly only when the file starts with a BOM.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Product',
    'Category',
    'Total Quantity',
    'Unit',
    'Base Price (RM)',
    'Current Value (RM)',
    'Reduction (%)',
    'Expiry Date',
    'Surplus Since',
    'Expired On',
    'Collected',
    'Wasted',
    'Still Available',
    'Status',
    'Collected By'
]);

$totalCollected = 0;
$totalWasted = 0;

foreach ($rows as $row) {
    $collected = (int) $row['collected_quantity'];
    $wasted = (int) $row['wasted_quantity'];

    $totalCollected += $collected;
    $totalWasted += $wasted;

    fputcsv($output, [
        $row['product_name'],
        $row['category'],
        (int) $row['quantity'],
        $row['unit'],
        number_format((float) $row['base_price'], 2, '.', ''),
        number_format((float) $row['current_value'], 2, '.', ''),
        number_format((float) $row['reduction_percentage'], 2, '.', ''),
        date('d/m/Y', strtotime($row['expiry_date'])),
        $row['surplus_since']
            ? date('d/m/Y', strtotime($row['surplus_since']))
            : '',
        $row['expired_at']
            ? date('d/m/Y', strtotime($row['expired_at']))
            : '',
        $collected,
        $wasted,
        max(0, (int) $row['available_quantity']),
        statusLabel($row['status']),
        $row['collected_by'] ?? ''
    ]);
}

/*
 * A totals row at the foot so the sheet answers the headline
 * question without the reader writing a formula.
 */
$handled = $totalCollected + $totalWasted;

$rate = $handled > 0
    ? round($totalCollected / $handled * 100) . '%'
    : 'n/a';

fputcsv($output, []);
fputcsv($output, ['TOTALS']);
fputcsv($output, ['Units collected', $totalCollected]);
fputcsv($output, ['Units wasted', $totalWasted]);
fputcsv($output, ['Redistribution rate', $rate]);
fputcsv($output, ['Filter', $filterLabel]);
fputcsv($output, ['Generated', date('d/m/Y H:i')]);

fclose($output);
exit;
