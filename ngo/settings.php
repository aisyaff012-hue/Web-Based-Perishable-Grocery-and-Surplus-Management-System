<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');

$successMessage = getFlash('settings_success', '');
$errors = getFlash('settings_errors', []);

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

<div class="form-panel">
    <h3 class="form-section-title">Change Password</h3>

    <form method="POST" action="actions/update-settings.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

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

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>