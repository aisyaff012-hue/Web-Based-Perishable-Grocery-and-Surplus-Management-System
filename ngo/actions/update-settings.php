<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('ngo');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}

$ngoId = $_SESSION['user_id'];

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('settings_errors', ['Your session expired.']);
    header('Location: ../settings.php');
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$errors = [];

try {
    $passwordStatement = $pdo->prepare(
        "SELECT password FROM users WHERE user_id = ? LIMIT 1"
    );

    $passwordStatement->execute([$ngoId]);
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
        $ngoId
    ]);

    setFlash('settings_success', 'Your password was changed.');

    header('Location: ../settings.php');
    exit;
} catch (PDOException $exception) {
    setFlash('settings_errors', ['Your password could not be changed.']);
    header('Location: ../settings.php');
    exit;
}