<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('merchant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}

$merchantId = $_SESSION['user_id'];
$section = $_POST['section'] ?? '';

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('settings_errors', ['Your session expired.']);
    header('Location: ../settings.php');
    exit;
}

$errors = [];

try {
    if ($section === 'pricing') {
        $nearExpiryDays = filter_input(INPUT_POST, 'near_expiry_days', FILTER_VALIDATE_INT);
        $reduction = filter_input(INPUT_POST, 'reduction_percentage', FILTER_VALIDATE_FLOAT);
        $threshold = filter_input(INPUT_POST, 'donation_threshold_days', FILTER_VALIDATE_INT);

        if (!$nearExpiryDays || $nearExpiryDays < 1 || $nearExpiryDays > 30) {
            $errors[] = 'Near expiry must be between 1 and 30 days.';
        }

        if ($reduction === false || $reduction === null || $reduction < 0 || $reduction > 90) {
            $errors[] = 'Value reduction must be between 0 and 90 percent.';
        }

        if (!$threshold || $threshold < 1 || $threshold > 14) {
            $errors[] = 'Surplus trigger must be between 1 and 14 days.';
        }

        /*
         * Surplus must come after near expiry in the lifecycle,
         * otherwise items would skip the reduced-value stage.
         */
        if (!$errors && $threshold >= $nearExpiryDays) {
            $errors[] = 'Surplus trigger must be fewer days than near expiry.';
        }

        if ($errors) {
            setFlash('settings_errors', $errors);
            header('Location: ../settings.php');
            exit;
        }

        $statement = $pdo->prepare(
            "UPDATE merchant_settings
             SET near_expiry_days = ?,
                 reduction_percentage = ?,
                 donation_threshold_days = ?
             WHERE merchant_id = ?"
        );

        $statement->execute([
            $nearExpiryDays,
            $reduction,
            $threshold,
            $merchantId
        ]);

        // Apply the new rules to existing stock straight away.
        refreshInventoryStatus($pdo, true);

        setFlash('settings_success', 'Pricing rules updated and applied to your inventory.');
    } elseif ($section === 'notifications') {
        $notifyReservation = isset($_POST['notify_reservation']) ? 1 : 0;
        $notifySurplus = isset($_POST['notify_surplus']) ? 1 : 0;

        $statement = $pdo->prepare(
            "UPDATE merchant_settings
             SET notify_reservation = ?,
                 notify_surplus = ?
             WHERE merchant_id = ?"
        );

        $statement->execute([
            $notifyReservation,
            $notifySurplus,
            $merchantId
        ]);

        setFlash('settings_success', 'Notification preferences saved.');
    } elseif ($section === 'password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $passwordStatement = $pdo->prepare(
            "SELECT password FROM users WHERE user_id = ? LIMIT 1"
        );

        $passwordStatement->execute([$merchantId]);
        $storedHash = $passwordStatement->fetchColumn();

        if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
            $errors[] = 'Your current password is incorrect.';
        }

        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'The two new passwords do not match.';
        }

        if ($errors) {
            setFlash('settings_errors', $errors);
            header('Location: ../settings.php');
            exit;
        }

        $updateStatement = $pdo->prepare(
            "UPDATE users SET password = ? WHERE user_id = ?"
        );

        $updateStatement->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $merchantId
        ]);

        setFlash('settings_success', 'Your password was changed.');
    } else {
        setFlash('settings_errors', ['Unknown settings section.']);
    }

    header('Location: ../settings.php');
    exit;
} catch (PDOException $exception) {
    setFlash('settings_errors', ['Settings could not be saved.']);
    header('Location: ../settings.php');
    exit;
}