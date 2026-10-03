<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');

$merchantId = $_SESSION['user_id'];

$successMessage = getFlash('settings_success', '');
$errors = getFlash('settings_errors', []);

$settings = getMerchantSettings($pdo, $merchantId);

$pageTitle = 'Settings';
$activePage = 'settings';

require __DIR__ . '/../includes/layouts/header.php';
?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= e($successMessage) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="section-head">
    <div>
        <h2>Settings</h2>
    </div>
</section>

<div class="split-layout">
    <div class="form-panel">
        <!-- Peraturan dynamic pricing — nilai ni terus kawal
             calculatePricing() & refreshInventoryStatus() dalam
             functions.php. Setiap form di page ni hantar ke
             action yang sama (update-settings.php), dibezakan
             oleh "section" supaya satu form boleh disimpan tanpa
             sentuh dua form lain. -->
        <h3 class="form-section-title">Dynamic Pricing Rules</h3>

        <p class="stat-note rule-intro">These rules drive the automatic status engine. Every product is re-checked whenever the system runs.</p>

        <form method="POST" action="actions/update-settings.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="pricing">

            <div class="field-row">
                <div class="field">
                    <label for="near_expiry_days">Near Expiry Starts At</label>
                    <input id="near_expiry_days" type="number" name="near_expiry_days" min="1" max="30" value="<?= (int) $settings['near_expiry_days'] ?>" required>
                    <span class="field-hint">Days before expiry</span>
                </div>

                <div class="field">
                    <label for="reduction_percentage">Value Reduction</label>
                    <input id="reduction_percentage" type="number" name="reduction_percentage" min="0" max="90" step="0.01" value="<?= (float) $settings['reduction_percentage'] ?>" required>
                    <span class="field-hint">Percent off base price</span>
                </div>
            </div>

            <div class="field">
                <label for="donation_threshold_days">Surplus Trigger</label>
                <input id="donation_threshold_days" type="number" name="donation_threshold_days" min="1" max="14" value="<?= (int) $settings['donation_threshold_days'] ?>" required>
                <span class="field-hint">Days before expiry when products are automatically offered to NGOs. Default is 3.</span>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Save Rules</button>
            </div>
        </form>

        <h3 class="form-section-title spaced">Notifications</h3>

        <form method="POST" action="actions/update-settings.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="notifications">

            <label class="toggle-row">
                <input type="checkbox" name="notify_reservation" value="1" <?= (int) $settings['notify_reservation'] === 1 ? 'checked' : '' ?>>
                <span>
                    <strong>NGO Reservation Notifications</strong>
                    Alert me when an NGO requests one of my products.
                </span>
            </label>

            <label class="toggle-row">
                <input type="checkbox" name="notify_surplus" value="1" <?= (int) $settings['notify_surplus'] === 1 ? 'checked' : '' ?>>
                <span>
                    <strong>Surplus Notifications</strong>
                    Alert me when an product automatically moves to surplus.
                </span>
            </label>

            <div class="form-actions">
                <button class="button" type="submit">Save Preferences</button>
            </div>
        </form>

        <h3 class="form-section-title spaced">Change Password</h3>

        <form method="POST" action="actions/update-settings.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="section" value="password">

            <div class="field">
                <label for="current_password">Current Password</label>
                <input id="current_password" type="password" name="current_password" required>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="new_password">New Password</label>
                    <input id="new_password" type="password" name="new_password" required>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" type="password" name="confirm_password" required>
                </div>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">Update Password</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3>Current Rules</h3>
        </div>

        <!-- Ringkasan peraturan semasa, dikira terus dari $settings
             supaya sentiasa sepadan dengan nilai yang betul-betul
             tersimpan — bukan teks statik yang boleh jadi tak tepat
             lepas merchant ubah tetapan. -->
        <div class="panel-body">
            <div class="rule-line">
                <span class="badge badge-available">Available</span>
                <span>More than <?= (int) $settings['near_expiry_days'] ?> days left &mdash; full value</span>
            </div>

            <div class="rule-line">
                <span class="badge badge-near_expiry">Near Expiry</span>
                <span><?= (int) $settings['near_expiry_days'] ?> to <?= (int) $settings['donation_threshold_days'] + 1 ?> days left &mdash; <?= (float) $settings['reduction_percentage'] ?>% reduction</span>
            </div>

            <div class="rule-line">
                <span class="badge badge-surplus">Surplus</span>
                <span><?= (int) $settings['donation_threshold_days'] ?> days or fewer &mdash; offered to NGOs free</span>
            </div>

            <div class="rule-line">
                <span class="badge badge-expired">Expired</span>
                <span>Past expiry date &mdash; hidden from NGOs</span>
            </div>

            <p class="stat-note id-note">Changing these rules affects future status checks. Items already marked surplus keep that status.</p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>