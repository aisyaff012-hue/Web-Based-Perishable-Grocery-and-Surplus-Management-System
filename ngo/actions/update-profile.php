<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('ngo');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../profile.php');
    exit;
}

$ngoId = $_SESSION['user_id'];

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organizationName = trim($_POST['organization_name'] ?? '');
$address = trim($_POST['address'] ?? '');
$latitude = trim($_POST['latitude'] ?? '');
$longitude = trim($_POST['longitude'] ?? '');

$latitude = is_numeric($latitude) ? (float) $latitude : null;
$longitude = is_numeric($longitude) ? (float) $longitude : null;

$errors = [];

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session expired. Please try again.';
}

if ($fullName === '') {
    $errors[] = 'Full name is required.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if ($phone === '') {
    $errors[] = 'Phone number is required.';
}

if ($organizationName === '') {
    $errors[] = 'Organization name is required.';
}

if ($address === '') {
    $errors[] = 'Address is required.';
}

// Email kena kekal unik merentasi SEMUA akaun KECUALI akaun sendiri
// (user_id <> $ngoId) — kalau tidak, NGO tak boleh save profil dia
// walaupun email tu memang email dia sendiri.
if (!$errors) {
    $emailStatement = $pdo->prepare(
        "SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1"
    );

    $emailStatement->execute([$email, $ngoId]);

    if ($emailStatement->fetchColumn()) {
        $errors[] = 'That email is already used by another account.';
    }
}

if ($errors) {
    setFlash('profile_errors', $errors);

    setFlash('profile_old', [
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'organization_name' => $organizationName,
        'address' => $address
    ]);

    header('Location: ../profile.php');
    exit;
}

try {
    $updateStatement = $pdo->prepare(
        "UPDATE users
         SET full_name = ?,
             email = ?,
             phone = ?,
             organization_name = ?,
             address = ?,
             latitude = ?,
             longitude = ?
         WHERE user_id = ?"
    );

    $updateStatement->execute([
        $fullName,
        $email,
        $phone,
        $organizationName,
        $address,
        $latitude,
        $longitude,
        $ngoId
    ]);

    // Kemaskini session sekali, supaya header & ucapan "Welcome
    // back" terus ikut nama/organisasi terkini tanpa perlu log out.
    $_SESSION['full_name'] = $fullName;
    $_SESSION['entity_name'] = $organizationName;

    setFlash('profile_success', 'Your profile was updated.');

    header('Location: ../profile.php');
    exit;
} catch (PDOException $exception) {
    setFlash('profile_errors', ['The profile could not be updated.']);
    header('Location: ../profile.php');
    exit;
}