<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');

// Ralat & input lama (kalau submit sebelum ni gagal validation)
// dibaca sekali sahaja dari flash, untuk isi balik form.
$errors = getFlash('form_errors', []);
$old = getFlash('form_old', []);

$pageTitle = 'Add New Product';
$activePage = 'listings';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Add New Product</h2>
        <p>Status and current value are calculated automatically from the expiry date.</p>
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
        <input type="hidden" name="mode" value="add">

        <div class="field">
            <label for="product_name">Product Name</label>
            <input id="product_name" type="text" name="product_name" value="<?= e($old['product_name'] ?? '') ?>" placeholder="e.g. Whole Wheat Bread" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="category">Category</label>

                <select id="category" name="category" required>
                    <option value="">Select category</option>

                    <?php foreach (productCategories() as $category): ?>
                        <option value="<?= e($category) ?>" <?= ($old['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="unit">Unit</label>

                <select id="unit" name="unit" required>
                    <?php foreach (productUnits() as $unit): ?>
                        <option value="<?= e($unit) ?>" <?= ($old['unit'] ?? 'units') === $unit ? 'selected' : '' ?>><?= e($unit) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="quantity">Quantity</label>
                <input id="quantity" type="number" name="quantity" min="1" value="<?= e($old['quantity'] ?? '') ?>" required>
            </div>

            <div class="field">
                <label for="base_price">Base Price (RM)</label>
                <input id="base_price" type="number" name="base_price" min="0" step="0.01" value="<?= e($old['base_price'] ?? '') ?>" required>
            </div>
        </div>

        <div class="field">
            <label for="expiry_date">Expiry Date</label>
            <input id="expiry_date" type="date" name="expiry_date" min="<?= date('Y-m-d') ?>" value="<?= e($old['expiry_date'] ?? '') ?>" required>
        </div>

        <div class="field">
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="3" placeholder="Any notes about this product"><?= e($old['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="product_image">Product Photo (optional)</label>

            <div class="upload-row">
                <span class="upload-preview" id="imagePreview">
                    <span class="upload-placeholder">No photo</span>
                </span>

                <div class="upload-control">
                    <input id="product_image" type="file" name="product_image" accept="image/jpeg,image/png,image/webp" onchange="previewImage(this)">
                    <span class="field-hint">JPG, PNG or WEBP. Max 2 MB. A category icon is used when no photo is uploaded.</span>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button class="button" type="submit">Save Product</button>
            <a class="button button-outline" href="listings.php">Cancel</a>
        </div>
    </form>
</div>

<script>
    // Papar preview gambar terus (sebelum submit) guna FileReader,
    // tanpa perlu upload ke server dulu.
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