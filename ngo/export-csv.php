<?php

/*
 * Streams the NGO's collection record as CSV. Built in memory
 * and pushed straight to the browser, so there is no upload
 * folder to secure and no stale exports to clean up.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report-filters.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];
$organisation = $_SESSION['entity_name'] ?? 'ngo';

$requestFilter = filterClause('reservations.request_date');
$requestValues = filterValues();

$statement = $pdo->prepare(
    "SELECT
        reservations.quantity_requested,
        reservations.request_date,
        reservations.pickup_date,
        reservations.completed_at,
        reservations.status,
        reservations.rejection_reason,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.base_price,
        inventory.expiry_date,
        users.business_name,
        users.pickup_location
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     INNER JOIN users
        ON reservations.merchant_id = users.user_id
     WHERE reservations.ngo_id = ?
       {$requestFilter}
     ORDER BY reservations.request_date ASC"
);

$statement->execute(array_merge([$ngoId], $requestValues));
$rows = $statement->fetchAll();

$fileName = 'freshtrack-reservations-'
    . preg_replace('/[^a-z0-9]+/i', '-', $organisation) . '-'
    . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$output = fopen('php://output', 'w');

// Excel reads UTF-8 correctly only when the file starts with a BOM.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Product',
    'Category',
    'Merchant',
    'Pickup Location',
    'Quantity',
    'Unit',
    'Market Value (RM)',
    'Requested On',
    'Pickup Date',
    'Collected On',
    'Expiry Date',
    'Status',
    'Rejection Reason'
]);

$totalCollected = 0;
$totalValue = 0.0;
$missed = 0;

foreach ($rows as $row) {
    $quantity = (int) $row['quantity_requested'];
    $value = $quantity * (float) $row['base_price'];

    if ($row['status'] === 'completed') {
        $totalCollected += $quantity;
        $totalValue += $value;
    }

    if ($row['status'] === 'not_collected') {
        $missed++;
    }

    fputcsv($output, [
        $row['product_name'],
        $row['category'],
        $row['business_name'],
        $row['pickup_location'],
        $quantity,
        $row['unit'],
        number_format($value, 2, '.', ''),
        date('d/m/Y', strtotime($row['request_date'])),
        date('d/m/Y', strtotime($row['pickup_date'])),
        $row['completed_at']
            ? date('d/m/Y', strtotime($row['completed_at']))
            : '',
        date('d/m/Y', strtotime($row['expiry_date'])),
        statusLabel($row['status']),
        $row['rejection_reason'] ?? ''
    ]);
}

/*
 * The collection rate counts only reservations that reached an
 * outcome the NGO controlled: collected, or left until the item
 * expired. Rejections and cancellations are excluded.
 */
$concluded = $totalCollected > 0 || $missed > 0
    ? count(array_filter($rows, function ($row) {
        return in_array(
            $row['status'],
            ['completed', 'not_collected'],
            true
        );
    }))
    : 0;

$completedCount = count(array_filter($rows, function ($row) {
    return $row['status'] === 'completed';
}));

$rate = $concluded > 0
    ? round($completedCount / $concluded * 100) . '%'
    : 'n/a';

fputcsv($output, []);
fputcsv($output, ['TOTALS']);
fputcsv($output, ['Units collected', $totalCollected]);
fputcsv($output, ['Market value saved (RM)', number_format($totalValue, 2, '.', '')]);
fputcsv($output, ['Collection rate', $rate]);
fputcsv($output, ['Filter', $filterLabel]);
fputcsv($output, ['Generated', date('d/m/Y H:i')]);

fclose($output);
exit;
