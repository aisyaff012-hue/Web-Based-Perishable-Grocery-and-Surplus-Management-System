<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

$successMessage = getFlash('request_success', '');
$errorMessage = getFlash('request_error', '');

$statusFilter = $_GET['status'] ?? 'pending';

$validStatuses = ['all', 'pending', 'approved', 'completed', 'rejected', 'cancelled', 'not_collected'];

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'pending';
}
/*
 * Satu query untuk kira semua jumlah tab sekali gus, supaya tab
 * boleh papar berapa banyak yang menunggu tanpa perlu 4 query
 * berasingan.
 */
$countStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS all_count,
        COALESCE(SUM(status = 'pending'), 0) AS pending_count,
        COALESCE(SUM(status = 'approved'), 0) AS approved_count,
        COALESCE(SUM(status = 'completed'), 0) AS completed_count,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected_count,
        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled_count,
        COALESCE(SUM(status = 'not_collected'), 0) AS missed_count
     FROM reservations
     WHERE merchant_id = ?"
);

$countStatement->execute([$merchantId]);
$counts = $countStatement->fetch();
$search = trim($_GET['search'] ?? '');

$conditions = ['reservations.merchant_id = ?'];
$parameters = [$merchantId];

if ($statusFilter !== 'all') {
    $conditions[] = 'reservations.status = ?';
    $parameters[] = $statusFilter;
}

/*
 * Satu kotak carian untuk cari NGO DAN produk sekali, sebab
 * merchant yang cari satu request biasanya ingat salah satu je
 * (nama NGO atau nama produk), bukan field mana ia disimpan.
 */
if ($search !== '') {
    $conditions[] = '(users.organization_name LIKE ? OR inventory.product_name LIKE ?)';
    $parameters[] = '%' . $search . '%';
    $parameters[] = '%' . $search . '%';
}

$requestStatement = $pdo->prepare(
    "SELECT
        reservations.request_id,
        reservations.quantity_requested,
        reservations.note,
        reservations.request_date,
        reservations.pickup_date,
        reservations.status,
        reservations.rejection_reason,
        inventory.product_name,
        inventory.category,
        inventory.unit,
        inventory.image_file,
        users.organization_name,
        users.phone,
        users.display_id
     FROM reservations
     INNER JOIN inventory
        ON reservations.item_id = inventory.item_id
     INNER JOIN users
        ON reservations.ngo_id = users.user_id
     WHERE " . implode(' AND ', $conditions) . "
     ORDER BY
        FIELD(reservations.status, 'pending', 'approved', 'completed', 'rejected'),
        reservations.pickup_date ASC"
);

$requestStatement->execute($parameters);
$requests = $requestStatement->fetchAll();

$pageTitle = 'Requests';
$activePage = 'requests';

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
        <h2>NGO Requests</h2>
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
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search NGO or product...">

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

            <?php if ($search !== '' || $statusFilter !== 'pending'): ?>
                <a class="button button-small button-outline" href="requests.php">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$requests): ?>
        <div class="empty">
            <strong>No requests here</strong>
            NGO reservations appear once your surplus products are requested.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table table-requests">
                <thead>
                    <tr>
                        <th>NGO</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Request Date</th>
                        <th>Pickup Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($requests as $row): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($row['organization_name']) ?></span>
                                <span class="cell-sub"><?= e($row['display_id']) ?> &middot; <?= e($row['phone']) ?></span>
                            </td>

                            <td>
                                <div class="cell-product">
                                    <?= productThumbnail($row['image_file'], $row['category']) ?>

                                    <span>
                                        <span class="cell-title"><?= e($row['product_name']) ?></span>

                                        <?php if ($row['note']): ?>
                                            <span class="cell-sub">Note: <?= e($row['note']) ?></span>
                                        <?php else: ?>
                                            <span class="cell-sub"><?= e($row['category']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td><?= (int) $row['quantity_requested'] ?> <?= e($row['unit']) ?></td>

                            <td><?= date('d/m/Y', strtotime($row['request_date'])) ?></td>

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
                                        <form method="POST" action="actions/update-request.php">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button class="pill pill-approve" type="submit">Approve</button>
                                        </form>

                                        <button class="pill pill-reject" type="button" onclick="openReject(<?= (int) $row['request_id'] ?>)">Reject</button>
                                    <?php elseif ($row['status'] === 'approved'): ?>
                                        <button class="pill pill-collect" type="button" onclick="askCollect(<?= (int) $row['request_id'] ?>)">Mark Collected</button>
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

<div class="modal" id="rejectModal">
    <div class="modal-card">
        <h3>Reject Request</h3>
        <p class="auth-subtitle">Let the NGO know why, so they can plan around it.</p>

        <form method="POST" action="actions/update-request.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="decision" value="reject">
            <input type="hidden" name="request_id" id="rejectRequestId" value="">

            <div class="field">
                <label for="rejection_reason">Reason</label>
                <textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="120" placeholder="e.g. stock already committed" required></textarea>                
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Confirm Rejection</button>
                <button class="button button-outline" type="button" onclick="closeReject()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="collectModal">
    <div class="modal-card">
        <h3>Confirm Collection</h3>
        <p class="auth-subtitle">Mark this reservation as collected. The NGO will be notified and the quantity recorded in your reports.</p>

        <form method="POST" action="actions/update-request.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="decision" value="complete">
            <input type="hidden" name="request_id" id="collectRequestId" value="">

            <div class="form-actions">
                <button class="button" type="submit">Yes, mark collected</button>
                <button class="button button-outline" type="button" onclick="closeCollect()">Not yet</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Isi modal reject dengan id request yang diklik, baru papar modal.
    function openReject(requestId) {
        document.getElementById('rejectRequestId').value = requestId;
        document.getElementById('rejectModal').classList.add('modal-open');
    }

    function closeReject() {
        document.getElementById('rejectModal').classList.remove('modal-open');
    }

    // Isi modal confirm-collect dengan id request yang diklik, baru
    // papar modal (sebelum tandakan reservation sebagai collected).
        function askCollect(requestId) {
        document.getElementById('collectRequestId').value = requestId;
        document.getElementById('collectModal').classList.add('modal-open');
    }

    function closeCollect() {
        document.getElementById('collectModal').classList.remove('modal-open');
    }
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>