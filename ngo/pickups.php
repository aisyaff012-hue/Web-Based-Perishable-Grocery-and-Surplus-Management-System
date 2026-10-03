<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');

$ngoId = $_SESSION['user_id'];

/*
 * Hanya reservation berstatus 'approved' yang perlu dikutip.
 * Yang pending, rejected atau dah completed semua tu letak di
 * page My Reservations, bukan di sini.
 */
$pickupStatement = $pdo->prepare(
    "SELECT
        reservations.request_id,
        reservations.quantity_requested,
        reservations.pickup_date,
        DATEDIFF(reservations.pickup_date, CURDATE()) AS days_until,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.expiry_date,
        inventory.image_file,
        users.business_name,
        users.pickup_location,
        users.address,
        users.latitude,
        users.longitude,
        users.operating_hours,
        users.phone
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     INNER JOIN users
        ON reservations.merchant_id = users.user_id
     WHERE reservations.ngo_id = ?
       AND reservations.status = 'approved'
     ORDER BY reservations.pickup_date ASC"
);

$pickupStatement->execute([$ngoId]);
$pickups = $pickupStatement->fetchAll();

$pageTitle = 'Pickup Schedule';
$activePage = 'pickups';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Pickup Schedule</h2>
        <p><?= count($pickups) ?> approved collection<?= count($pickups) === 1 ? '' : 's' ?></p>
    </div>
</section>

<div class="panel">
    <?php if (!$pickups): ?>
        <div class="empty">
            <strong>No pickups scheduled</strong>
            Approved reservations appear here with collection details.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table table-pickups">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Merchant</th>
                        <th>Quantity</th>
                        <th>Pickup Address</th>
                        <th>Contact</th>
                        <th>Pickup Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($pickups as $row): ?>
                        <?php $daysUntil = (int) $row['days_until']; ?>

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

                            <td>
                                <?= e($row['business_name']) ?>
                                <?php foreach (hoursLines($row['operating_hours']) as $line): ?>
                                    <span class="cell-sub"><?= e($line) ?></span>
                                <?php endforeach; ?>
                            </td>

                            <td><?= (int) $row['quantity_requested'] ?> <?= e($row['unit']) ?></td>

                                                        <td>
                                <?= e($row['pickup_location']) ?>
                                <?php $mapUrl = mapLink(
                                    $row['address'] ?? null,
                                    isset($row['latitude']) ? (float) $row['latitude'] : null,
                                    isset($row['longitude']) ? (float) $row['longitude'] : null
                                ); ?>
                                <?php if ($mapUrl): ?>
                                    <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer" class="map-link">Map</a>
                                <?php endif; ?>
                                
                            </td>

                            <td><?= e($row['phone']) ?></td>

                            <td>
                                <?= date('d/m/Y', strtotime($row['pickup_date'])) ?>
                                <span class="cell-sub">Expires <?= date('d/m/Y', strtotime($row['expiry_date'])) ?></span>
                            </td>

                            <td>
                                <?php /* Overdue = tarikh pickup dah lepas tapi NGO belum
                                         kutip lagi (reservation masih 'approved'). */ ?>
                                <?php if ($daysUntil < 0): ?>
                                    <span class="badge badge-rejected">Overdue</span>
                                <?php elseif ($daysUntil === 0): ?>
                                    <span class="badge badge-pending">Today</span>
                                <?php else: ?>
                                    <span class="badge badge-approved">In <?= $daysUntil ?> day<?= $daysUntil === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>