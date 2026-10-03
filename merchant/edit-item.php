<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId) {
    setFlash('listing_error', 'Invalid product.');
    header('Location: listings.php');
    exit;
}

$itemStatement = $pdo->prepare(
    "SELECT
        item_id,
        product_name,
        category,
        quantity,
        unit,
        base_price,
        expiry_date,
        description, 
        image_file
     FROM inventory
     WHERE item_id = ?
       AND merchant_id = ?
       AND removed_at IS NULL
     LIMIT 1"
);

$itemStatement->execute([$itemId, $_SESSION['user_id']]);
$item = $itemStatement->fetch();

if (!$item) {
    setFlash('listing_error', 'Product not found.');
    header('Location: listings.php');
    exit;
}

$errors = getFlash('form_errors', []);
$old = getFlash('form_old', []);

// Isi field guna input lama ($old) kalau submit sebelum ni gagal
// validation; kalau tidak, jatuh balik ke nilai yang tersimpan
// dalam DB ($item) — jadi form sentiasa ada nilai untuk dipaparkan.
$value = function (string $key) use ($old, $item) {
    return $old[$key] ?? $item[$key] ?? '';
};

$pageTitle = 'Edit Product';
$activePage = 'listings';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Edit Product</h2>
        <p>Changing the expiry date recalculates status and value.</p>
    </div>

    <a class="button button-outline" href="listings.php">Back</a>
</section>

<div class="form-panel">
    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="actions/save-item.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="mode" value="edit">
        <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">  

        <div class="field">
            <label for="product_name">Product Name</label>
            <input id="product_name" type="text" name="product_name" value="<?= e($value('product_name')) ?>" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="category">Category</label>

                <select id="category" name="category" required>
                    <?php foreach (productCategories() as $category): ?>
                        <option value="<?= e($category) ?>" <?= $value('category') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="unit">Unit</label>

                <select id="unit" name="unit" required>
                    <?php foreach (productUnits() as $unit): ?>
                        <option value="<?= e($unit) ?>" <?= $value('unit') === $unit ? 'selected' : '' ?>><?= e($unit) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="quantity">Quantity</label>
                <input id="quantity" type="number" name="quantity" min="1" value="<?= e($value('quantity')) ?>" required>
            </div>

            <div class="field">
                <label for="base_price">Base Price (RM)</label>
                <input id="base_price" type="number" name="base_price" min="0" step="0.01" value="<?= e($value('base_price')) ?>" required>
            </div>
        </div>

        <div class="field">
            <label for="expiry_date">Expiry Date</label>
            <input id="expiry_date" type="date" name="expiry_date" value="<?= e($value('expiry_date')) ?>" required>
        </div>

        <div class="field">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="3"><?= e($value('description')) ?></textarea>
        </div>

        <div class="field">
            <label for="product_image">Product Photo (optional)</label>

            <div class="upload-row">
                <span class="upload-preview" id="imagePreview">
                    <?php $currentImage = productImageUrl($item['image_file']); ?>

                    <?php if ($currentImage): ?>
                        <img src="<?= e($currentImage) ?>" alt="">
                    <?php else: ?>
                        <span class="upload-placeholder">No photo</span>
                    <?php endif; ?>
                </span>

                <div class="upload-control">
                    <input id="product_image" type="file" name="product_image" accept="image/jpeg,image/png,image/webp" onchange="previewImage(this)">
                    <span class="field-hint">Leave empty to keep the current photo. JPG, PNG or WEBP, max 2 MB.</span>

                    <?php if ($currentImage): ?>
                        <label class="remove-photo">
                            <input type="checkbox" name="remove_image" value="1">
                            Remove current photo
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button class="button" type="submit">Save Changes</button>
            <a class="button button-outline" href="listings.php">Cancel</a>
        </div>
    </form>
</div>

<script>
    // Papar preview gambar baru yang dipilih (sebelum submit),
    // gantikan paparan gambar sedia ada buat sementara.
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');

        if (!input.files || !input.files[0]) {
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {
            preview.innerHTML =
                '<img src="' + event.target.result + '" alt="">';
        };

        reader.readAsDataURL(input.files[0]);
    }
</script>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>