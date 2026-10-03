<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirectToDashboard();
}

$errors = [];

$role = $_POST['role'] ?? 'merchant';
$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

$businessName = trim($_POST['business_name'] ?? '');
$businessType = trim($_POST['business_type'] ?? '');
$pickupLocation = trim($_POST['pickup_location'] ?? '');
$operatingHours = trim($_POST['operating_hours'] ?? '');

$organizationName = trim($_POST['organization_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if (!in_array($role, ['merchant', 'ngo'], true)) {
        $errors[] = 'Please choose a valid account type.';
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

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'The two passwords do not match.';
    }

    if ($role === 'merchant') {
        if ($businessName === '') {
            $errors[] = 'Business name is required.';
        }

        if ($businessType === '') {
            $errors[] = 'Business type is required.';
        }

        if ($pickupLocation === '') {
            $errors[] = 'Pickup location is required.';
        }
    } else {
        if ($organizationName === '') {
            $errors[] = 'Organization name is required.';
        }

        if ($address === '') {
            $errors[] = 'Address is required.';
        }
    }

    if (!$errors) {
        $emailStatement = $pdo->prepare(
            "SELECT 1 FROM users WHERE email = ? LIMIT 1"
        );

        $emailStatement->execute([$email]);

        if ($emailStatement->fetchColumn()) {
            $errors[] = 'This email is already registered.';
        }
    }

    if (!$errors) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        /*
         * Kalau 2 orang register pada masa yang sama, dua-dua boleh
         * dapat display ID yang sama. UNIQUE index akan tolak insert
         * yang kedua, jadi kita cuba lagi dengan nombor baru.
         */
        $attempt = 0;
        $saved = false;

        while (!$saved && $attempt < 5) {
            $attempt++;

            try {
                $pdo->beginTransaction();

                $displayId = generateDisplayId($pdo, $role);

                $insertStatement = $pdo->prepare(
                    "INSERT INTO users (
                        display_id,
                        role,
                        full_name,
                        email,
                        phone,
                        password,
                        business_name,
                        business_type,
                        pickup_location,
                        operating_hours,
                        organization_name,
                        address
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $insertStatement->execute([
                    $displayId,
                    $role,
                    $fullName,
                    $email,
                    $phone,
                    $hashedPassword,
                    $role === 'merchant' ? $businessName : null,
                    $role === 'merchant' ? $businessType : null,
                    $role === 'merchant' ? $pickupLocation : null,
                    $role === 'merchant' && $operatingHours !== ''
                        ? $operatingHours
                        : null,
                    $role === 'ngo' ? $organizationName : null,
                    $address !== '' ? $address : null
                ]);

                $newUserId = (int) $pdo->lastInsertId();

                // Merchant baru mula dengan tetapan pricing default.
                if ($role === 'merchant') {
                    $settingsStatement = $pdo->prepare(
                        "INSERT INTO merchant_settings (merchant_id)
                         VALUES (?)"
                    );

                    $settingsStatement->execute([$newUserId]);
                }

                $pdo->commit();
                $saved = true;

                setFlash(
                    'register_success',
                    'Account ' . $displayId
                        . ' created. You may now log in.'
                );

                header('Location: login.php');
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // 23000 maksudnya UNIQUE constraint kena langgar
                // (display ID bertembung) — cuba lagi dengan nombor baru.
                if ($exception->getCode() !== '23000') {
                    $errors[] = 'Registration failed. Please try again.';
                    break;
                }
            }
        }

        if (!$saved && !$errors) {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

$businessTypes = [
    'Grocery Store',
    'Bakery',
    'Supermarket',
    'Mini Market',
    'Other'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register &middot; FreshTrack</title>

    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/logo.png">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>">
</head>
<body class="auth-body register-body">

<div class="auth-page register-page">

    <header class="auth-top">
        <div class="auth-identity">
            <img class="brand-logo-large" src="<?= BASE_URL ?>/assets/images/logo.png" alt="FreshTrack">

            <div>
                <span class="auth-wordmark">FreshTrack</span>
                <span class="auth-tagline">Web-Based Perishable Grocery<br>Inventory and Surplus Management System</span>
            </div>
        </div>

        <a class="button button-outline" href="login.php">Log In</a>
    </header>

    <main class="register-main">

        <section class="register-head">
            <h1>Create your account</h1>
            <p class="auth-lead">Register as a merchant or NGO worker. Your FreshTrack ID is generated automatically.</p>
        </section>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="register-form" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="role-choice">
                <label class="role-option">
                    <input type="radio" name="role" value="merchant" <?= $role === 'merchant' ? 'checked' : '' ?>>

                    <span>
                        <strong>Merchant</strong>
                        Manage perishable inventory and list surplus
                    </span>
                </label>

                <label class="role-option">
                    <input type="radio" name="role" value="ngo" <?= $role === 'ngo' ? 'checked' : '' ?>>

                    <span>
                        <strong>NGO Worker</strong>
                        Reserve and collect surplus food free of charge
                    </span>
                </label>
            </div>

            <div class="register-columns">

                <div class="register-block">
                    <h3 class="form-heading">Account Details</h3>

                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" type="text" name="full_name" value="<?= e($fullName) ?>" placeholder="e.g. Megat Amir" required>
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="<?= e($email) ?>" placeholder="you@example.com" required>
                    </div>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" type="text" name="phone" value="<?= e($phone) ?>" placeholder="012-3456789" required>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" type="password" name="password" placeholder="At least 8 characters" required>
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm Password</label>
                            <input id="confirm_password" type="password" name="confirm_password" placeholder="Repeat password" required>
                        </div>
                    </div>
                </div>

                <div class="register-block">
                    <h3 class="form-heading" id="detailsHeading">Business Details</h3>

                    <div class="role-fields" data-role="merchant">
                        <div class="field">
                            <label for="business_name">Business Name</label>
                            <input id="business_name" type="text" name="business_name" value="<?= e($businessName) ?>" placeholder="e.g. FreshMart Grocery">
                        </div>

                        <div class="field">
                            <label for="business_type">Business Type</label>

                            <select id="business_type" name="business_type">
                                <option value="">Select type</option>

                                <?php foreach ($businessTypes as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $businessType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="pickup_location">Pickup Location</label>
                            <input id="pickup_location" type="text" name="pickup_location" value="<?= e($pickupLocation) ?>" placeholder="Where NGOs collect surplus">
                        </div>

                        <div class="field">
                            <label for="operating_hours">Operating Hours (optional)</label>
                            <input id="operating_hours" type="text" name="operating_hours" value="<?= e($operatingHours) ?>" placeholder="e.g. 8:00 AM - 10:00 PM Daily">
                        </div>
                    </div>

                    <div class="role-fields" data-role="ngo">
                        <div class="field">
                            <label for="organization_name">Organization Name</label>
                            <input id="organization_name" type="text" name="organization_name" value="<?= e($organizationName) ?>" placeholder="e.g. Harapan Food Bank">
                        </div>
                    </div>

                    <div class="field">
                        <label for="address">Address</label>
                        <input id="address" type="text" name="address" value="<?= e($address) ?>" placeholder="Street, postcode, state">
                    </div>
                </div>

            </div>

            <div class="register-actions">
                <button class="button" type="submit">Create Account</button>
                <a class="button button-outline" href="login.php">Cancel</a>

                <span class="register-note">Already registered? <a href="login.php">Log in instead</a></span>
            </div>
        </form>

    </main>

    <footer class="auth-bottom">
        <span>&copy; <?= date('Y') ?> FreshTrack. Final Year Project.</span>
    </footer>

</div>

<script>
    // Tunjuk field yang sepadan dengan role yang dipilih sahaja
    // (business fields untuk merchant, organization field untuk NGO).
    const roleInputs = document.querySelectorAll('input[name="role"]');
    const roleFields = document.querySelectorAll('.role-fields');
    const detailsHeading = document.getElementById('detailsHeading');

    function updateRoleFields() {
        const selected = document.querySelector(
            'input[name="role"]:checked'
        ).value;

        roleFields.forEach(function (group) {
            group.style.display =
                group.dataset.role === selected ? 'block' : 'none';
        });

        detailsHeading.textContent = selected === 'merchant'
            ? 'Business Details'
            : 'Organization Details';
    }

    roleInputs.forEach(function (input) {
        input.addEventListener('change', updateRoleFields);
    });

    updateRoleFields();
</script>

</body>
</html>