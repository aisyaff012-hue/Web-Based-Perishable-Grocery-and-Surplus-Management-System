<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');

$ngoId = $_SESSION['user_id'];

$successMessage = getFlash('reservation_success', '');
$errorMessage = getFlash('reservation_error', '');

$statusFilter = $_GET['status'] ?? 'all';

$validStatuses = ['all', 'pending', 'approved', 'rejected', 'completed', 'cancelled', 'not_collected'];

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

$search = trim($_GET['search'] ?? '');
$countStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS all_count,
        COALESCE(SUM(status = 'pending'), 0) AS pending_count,
        COALESCE(SUM(status = 'approved'), 0) AS approved_count,
        COALESCE(SUM(status = 'completed'), 0) AS completed_count
     FROM reservations
     WHERE ngo_id = ?"
);

$countStatement->execute([$ngoId]);
$counts = $countStatement->fetch();

$conditions = ['reservations.ngo_id = ?'];
$parameters = [$ngoId];

if ($statusFilter !== 'all') {
    $conditions[] = 'reservations.status = ?';
    $parameters[] = $statusFilter;
}

/*
 * One box searches both the merchant and the product, since an
 * NGO looking for a past request remembers one or the other.
 */
if ($search !== '') {
    $conditions[] = '(users.business_name LIKE ? OR inventory.product_name LIKE ?)';
    $parameters[] = '%' . $search . '%';
    $parameters[] = '%' . $search . '%';
}

$reservationStatement = $pdo->prepare(
    "SELECT
        reservations.request_id,
        reservations.quantity_requested,
        reservations.request_date,
        reservations.pickup_date,
        reservations.status,
        reservations.rejection_reason,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.image_file,
        users.business_name,
        users.pickup_location
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     INNER JOIN users
        ON reservations.merchant_id = users.user_id
     WHERE " . implode(' AND ', $conditions) . "
     ORDER BY
        FIELD(reservations.status, 'approved', 'pending', 'completed', 'rejected'),
        reservations.pickup_date ASC"
);

$reservationStatement->execute($parameters);
$reservations = $reservationStatement->fetchAll();

$pageTitle = 'My Reservations';
$activePage = 'reservations';

require __DIR__ . '/../includes/layouts/header.php';
?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-error"><?= e($errorMessage) ?></div>
<?php endif; ?>

<section class="section-head">
    <div>
        <h2>My Reservations</h2>
    </div>
</section>

<div class="tabs">
    <a class="tab <?= $statusFilter === 'all' ? 'tab-active' : '' ?>" href="?status=all">All<span class="tab-count"><?= (int) $counts['all_count'] ?: '' ?></span></a>
    <a class="tab tab-pending <?= $statusFilter === 'pending' ? 'tab-active' : '' ?>" href="?status=pending">Pending<span class="tab-count"><?= (int) $counts['pending_count'] ?: '' ?></span></a>
    <a class="tab tab-approved <?= $statusFilter === 'approved' ? 'tab-active' : '' ?>" href="?status=approved">Approved<span class="tab-count"><?= (int) $counts['approved_count'] ?: '' ?></span></a>
    <a class="tab tab-collected <?= $statusFilter === 'completed' ? 'tab-active' : '' ?>" href="?status=completed">Completed<span class="tab-count"><?= (int) $counts['completed_count'] ?: '' ?></span></a>
</div>
<div class="panel">
    <div class="panel-head">
        <form class="filter-bar" method="GET">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search merchant or product...">

            <select name="status">
                <option value="all">All statuses</option>

                <?php
                $statusOptions = [
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'completed' => 'Completed',
                    'rejected' => 'Rejected',
                    'cancelled' => 'Cancelled',
                    'not_collected' => 'Not Collected'
                ];
                ?>

                <?php foreach ($statusOptions as $optionValue => $optionLabel): ?>
                    <option value="<?= $optionValue ?>" <?= $statusFilter === $optionValue ? 'selected' : '' ?>><?= $optionLabel ?></option>
                <?php endforeach; ?>
            </select>

            <button class="button button-small" type="submit">Apply</button>

            <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                <a class="button button-small button-outline" href="reservations.php">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$reservations): ?>
        <div class="empty">
            <strong>No reservations yet</strong>
            Browse Available Surplus to make your first request.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Merchant</th>
                        <th>Quantity</th>
                        <th>Pickup Location</th>
                        <th>Pickup Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($reservations as $row): ?>
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

                            <td><?= (int) $row['quantity_requested'] ?> <?= e($row['unit']) ?></td>

                            <td><?= e($row['pickup_location']) ?></td>

                            <td><?= date('d/m/Y', strtotime($row['pickup_date'])) ?></td>

                            <td>
                                <span class="badge badge-<?= e($row['status']) ?>"><?= e(statusLabel($row['status'])) ?></span>

                                <?php if ($row['status'] === 'rejected' && $row['rejection_reason']): ?>
                                    <span class="cell-sub reason-inline" title="<?= e($row['rejection_reason']) ?>"><?= e($row['rejection_reason']) ?></span>
                                <?php endif; ?>
                            </td>

<td>
    <div class="row-actions">
        <?php if ($row['status'] === 'pending'): ?>
            <button class="pill pill-cancel" type="button" onclick="askCancel(<?= (int) $row['request_id'] ?>)">Cancel</button>
        <?php endif; ?>
    </div>
</td>
    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="reasonModal">
    <div class="modal-card">
        <h3>Reservation Rejected</h3>
        <p class="auth-subtitle">The merchant gave the following reason.</p>

        <div class="reason-box" id="reasonText"></div>

        <div class="form-actions">
            <button class="button" type="button" onclick="closeReason()">Close</button>
        </div>
    </div>
</div>

<div class="modal" id="cancelModal">
    <div class="modal-card">
        <h3>Cancel Reservation</h3>
        <p class="auth-subtitle">This request will be withdrawn and the stock returned to the merchant's available surplus.</p>

        <form method="POST" action="actions/cancel-reservation.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="request_id" id="cancelRequestId" value="">

            <div class="form-actions">
                <button class="button button-danger" type="submit">Yes, cancel it</button>
                <button class="button button-outline" type="button" onclick="closeCancel()">Keep reservation</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showReason(requestId) {
        const source = document.getElementById('reason' + requestId);

        document.getElementById('reasonText').textContent =
            source.textContent;

        document.getElementById('reasonModal').classList.add('modal-open');
    }

    function closeReason() {
        document.getElementById('reasonModal').classList.remove('modal-open');
    }

    function askCancel(requestId) {
        document.getElementById('cancelRequestId').value = requestId;
        document.getElementById('cancelModal').classList.add('modal-open');
    }

    function closeCancel() {
        document.getElementById('cancelModal').classList.remove('modal-open');
    }
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>