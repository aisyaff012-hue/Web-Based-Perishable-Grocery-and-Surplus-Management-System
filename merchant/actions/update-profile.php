<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole('merchant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../profile.php');
    exit;
}

$merchantId = $_SESSION['user_id'];

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$businessName = trim($_POST['business_name'] ?? '');
$businessType = trim($_POST['business_type'] ?? '');
$address = trim($_POST['address'] ?? '');
$pickupLocation = trim($_POST['pickup_location'] ?? '');
$latitude = trim($_POST['latitude'] ?? '');
$longitude = trim($_POST['longitude'] ?? '');

$latitude = is_numeric($latitude) ? (float) $latitude : null;
$longitude = is_numeric($longitude) ? (float) $longitude : null;
$operatingHours = trim($_POST['operating_hours'] ?? '');
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

if ($businessName === '') {
    $errors[] = 'Business name is required.';
}

if ($pickupLocation === '') {
    $errors[] = 'Pickup location is required.';
}

// Email kena kekal unik merentasi SEMUA akaun KECUALI akaun sendiri
// (user_id <> $merchantId) — kalau tidak, merchant tak boleh save
// profil dia walaupun email tu memang email dia sendiri.
if (!$errors) {
    $emailStatement = $pdo->prepare(
        "SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1"
    );

    $emailStatement->execute([$email, $merchantId]);

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
        'business_name' => $businessName,
        'business_type' => $businessType,
        'address' => $address,
        'pickup_location' => $pickupLocation,
        'operating_hours' => $operatingHours
    ]);

    header('Location: ../profile.php');
    exit;
}

try {

    $currentStatement = $pdo->prepare(
        "SELECT shop_image FROM users WHERE user_id = ? LIMIT 1"
    );

    $currentStatement->execute([$merchantId]);
    $currentImage = $currentStatement->fetchColumn() ?: null;

    $shopImage = $currentImage;

    // Checkbox "remove photo" dan upload gambar baru dua-dua boleh
    // berlaku dalam submit yang sama; urutan di bawah pastikan
    // upload baru menang kalau user buat dua-dua sekali.
    if (!empty($_POST['remove_shop_image'])) {
        deleteShopImage($currentImage);
        $shopImage = null;
    }

    [$uploadedImage, $uploadError] = saveShopImage(
        $_FILES['shop_image'] ?? [],
        $merchantId
    );

    if ($uploadError) {
        setFlash('profile_errors', [$uploadError]);
        header('Location: ../profile.php');
        exit;
    }

    if ($uploadedImage) {
        deleteShopImage($currentImage);
        $shopImage = $uploadedImage;
    }

      $updateStatement = $pdo->prepare(
        "UPDATE users
         SET full_name = ?,
             email = ?,
             phone = ?,
             business_name = ?,
             business_type = ?,
             address = ?,
             pickup_location = ?,
             operating_hours = ?,
             latitude = ?,
             longitude = ?,
             shop_image = ?
         WHERE user_id = ?"
    );

    $updateStatement->execute([
        $fullName,
        $email,
        $phone,
        $businessName,
        $businessType,
        $address !== '' ? $address : null,
        $pickupLocation,
        $operatingHours !== '' ? $operatingHours : null,
        $latitude,
        $longitude,
        $shopImage,
        $merchantId
    ]);

    // Kemaskini session sekali, supaya header & ucapan "Welcome
    // back" terus ikut nama/perniagaan terkini tanpa perlu log out.
    $_SESSION['full_name'] = $fullName;
    $_SESSION['entity_name'] = $businessName;

    setFlash('profile_success', 'Your profile was updated.');

    header('Location: ../profile.php');
    exit;
} catch (PDOException $exception) {
    setFlash('profile_errors', ['The profile could not be updated.']);
    header('Location: ../profile.php');
    exit;
}