<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

$successMessage = getFlash('listing_success', '');
$errorMessage = getFlash('listing_error', '');

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';

$validStatuses = ['all', 'available', 'near_expiry', 'surplus', 'expired'];

// Kalau status dalam URL bukan salah satu yang sah, jatuh balik
// ke 'all' (elak query dengan nilai status yang direka user).
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

/*
 * Syarat-syarat dikumpul dalam array supaya klausa WHERE dibina
 * sekali sahaja, dengan setiap nilai kekal di-bind sebagai
 * parameter (elak SQL injection).
 */
$conditions = ['merchant_id = ?', 'removed_at IS NULL'];
$parameters = [$merchantId];

if ($search !== '') {
    $conditions[] = 'product_name LIKE ?';
    $parameters[] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $conditions[] = 'status = ?';
    $parameters[] = $statusFilter;
}

if ($categoryFilter !== 'all') {
    $conditions[] = 'category = ?';
    $parameters[] = $categoryFilter;
}

$listingStatement = $pdo->prepare(
    "SELECT
        item_id,
        product_name,
        category,
        quantity,
        unit,
        base_price,
        current_value,
        image_file,
        expiry_date,
        status,
        DATEDIFF(expiry_date, CURDATE()) AS days_remaining,
        " . availableQuantitySql() . " AS available_quantity
     FROM inventory
     WHERE " . implode(' AND ', $conditions) . "
     ORDER BY
        FIELD(status, 'surplus', 'near_expiry', 'available', 'expired'),
        expiry_date ASC"
);

$listingStatement->execute($parameters);
$items = $listingStatement->fetchAll();

$pageTitle = 'My Listings';
$activePage = 'listings';

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
        <h2>Inventory</h2>
        <p><?= count($items) ?> product<?= count($items) === 1 ? '' : 's' ?></p>
    </div>

    <a class="button" href="add-item.php">Add New Product</a>
</section>

<div class="panel">
    <div class="panel-head">
        <form class="filter-bar" method="GET">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product...">

            <select name="status">
                <option value="all">All statuses</option>

                <?php
                $statusOptions = [
                    'available' => 'Available',
                    'near_expiry' => 'Near Expiry',
                    'surplus' => 'Surplus',
                    'expired' => 'Expired'
                ];
                ?>

                <?php foreach ($statusOptions as $optionValue => $optionLabel): ?>
                    <option value="<?= $optionValue ?>" <?= $statusFilter === $optionValue ? 'selected' : '' ?>><?= $optionLabel ?></option>
                <?php endforeach; ?>
            </select>

            <select name="category">
                <option value="all">All categories</option>

                <?php foreach (productCategories() as $category): ?>
                    <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="button button-small" type="submit">Apply</button>

            <?php if ($search !== '' || $statusFilter !== 'all' || $categoryFilter !== 'all'): ?>
                <a class="button button-small button-outline" href="listings.php">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$items): ?>
        <div class="empty">
            <strong>No products found</strong>
            <?= $search !== '' || $statusFilter !== 'all' || $categoryFilter !== 'all'
                ? 'Try changing your search or filters.'
                : 'Add your first product to start tracking freshness.' ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Base Price</th>
                        <th>Current Value</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $days = (int) $item['days_remaining'];
                        $hasDiscount = (float) $item['current_value']
                            < (float) $item['base_price'];
                        ?>

                        <tr>
                            <td>
                                <div class="cell-product">
                                    <?= productThumbnail($item['image_file'], $item['category']) ?>

                                    <span>
                                        <span class="cell-title"><?= e($item['product_name']) ?></span>
                                        <span class="cell-sub"><?= e($item['category']) ?></span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <?= (int) $item['quantity'] ?> <?= e($item['unit']) ?>
                                <span class="cell-sub"><?= (int) $item['available_quantity'] ?> available</span>
                            </td>

                            <td class="<?= $hasDiscount ? 'value-was' : '' ?>"><?= formatCurrency((float) $item['base_price']) ?></td>

                            <td>
                                <?php if ($hasDiscount): ?>
                                    <strong class="value-down"><?= formatCurrency((float) $item['current_value']) ?></strong>
                                <?php else: ?>
                                    <span class="value-none">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= date('d M Y', strtotime($item['expiry_date'])) ?>

                                <?php if ($item['status'] !== 'expired'): ?>
                                    <span class="days-hint <?= daysUrgencyClass($days) ?>">(<?= formatDaysLeft($days) ?>)</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                // Kuantiti available dah habis (semua ditempah),
                                // tapi status belum 'expired' — papar badge
                                // "Out of Stock" khas, bukan status biasa.
                                $isOut = (int) $item['available_quantity'] <= 0
                                    && $item['status'] !== 'expired';
                                ?>

                                <?php if ($isOut): ?>
                                    <span class="badge badge-approved">Out of Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-<?= e($item['status']) ?>"><?= e(statusLabel($item['status'])) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                               <div class="row-actions">
    <a class="link-action" href="edit-item.php?id=<?= (int) $item['item_id'] ?>">Edit</a>

    <button class="link-action link-danger" type="button" onclick="askDelete(<?= (int) $item['item_id'] ?>, '<?= e(addslashes($item['product_name'])) ?>')">Delete</button>
</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="deleteModal">
    <div class="modal-card">
        <h3 class="modal-title"><img class="modal-icon" src="<?= BASE_URL ?>/assets/images/delete.png" alt=""> Delete Product</h3>
        <p class="auth-subtitle">This removes <strong id="deleteItemName"></strong> from your inventory. Past reservation history is kept for your reports.</p>

        <form method="POST" action="actions/delete-item.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="item_id" id="deleteItemId" value="">

            <div class="form-actions">
                <button class="button button-danger" type="submit">Yes, delete it</button>
                <button class="button button-outline" type="button" onclick="closeDelete()">Keep product</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Isi modal confirm-delete dengan id & nama produk yang diklik,
    // baru papar modal tu.
    function askDelete(itemId, productName) {
        document.getElementById('deleteItemId').value = itemId;
        document.getElementById('deleteItemName').textContent = productName;
        document.getElementById('deleteModal').classList.add('modal-open');
    }

    function closeDelete() {
        document.getElementById('deleteModal').classList.remove('modal-open');
    }
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>