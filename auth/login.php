<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirectToDashboard();
}

$errors = [];
$email = '';

$successMessage = getFlash('register_success', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both email and password.';
    }

    if (!$errors) {
        $statement = $pdo->prepare(
            "SELECT
                user_id,
                display_id,
                role,
                full_name,
                business_name,
                organization_name,
                password,
                account_status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $statement->execute([$email]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            // Same message for both cases so accounts cannot be probed.
            $errors[] = 'Invalid email or password.';
        } elseif ($user['account_status'] !== 'active') {
            $errors[] = 'This account has been suspended.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['display_id'] = $user['display_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];

            $_SESSION['entity_name'] = $user['role'] === 'merchant'
                ? $user['business_name']
                : $user['organization_name'];

            redirectToDashboard();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Log In &middot; FreshTrack</title>

    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/logo.png">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/main.css') ?>">
</head>
<body class="auth-body">

<div class="auth-page">

    <header class="auth-top">
        <div class="auth-identity">
            <img class="brand-logo-large" src="<?= BASE_URL ?>/assets/images/logo.png" alt="FreshTrack">

            <div>
                <span class="auth-wordmark">FreshTrack</span>
                <span class="auth-tagline">Web-Based Perishable Grocery<br>Inventory and Surplus Management System</span>
            </div>
        </div>

        <a class="button button-outline" href="register.php">Sign Up</a>
    </header>

    <main class="auth-main">

        <section class="auth-pitch">
    <h1>Track freshness.<br><span class="accent">Reduce waste.</span></h1>

    <p class="auth-lead">Monitor perishable stock, track value depreciation, and redistribute surplus to registered NGOs &mdash; automatically.</p>
   <div class="pitch-strip">
    <div class="pitch-item">
        <span class="pitch-icon"><img src="<?= BASE_URL ?>/assets/images/track.png" alt=""></span>

        <span>
            <strong>Track</strong>
            Value drops as shelf life runs down.
        </span>
    </div>

    <div class="pitch-item">
        <span class="pitch-icon"><img src="<?= BASE_URL ?>/assets/images/alert.png" alt=""></span>

        <span>
            <strong>Alert</strong>
            NGOs are notified instantly.
        </span>
    </div>

    <div class="pitch-item">
       <span class="pitch-icon"><img src="<?= BASE_URL ?>/assets/images/redistribute.png" alt=""></span>
        <span>
            <strong>Redistribute</strong>
            Collected free, never wasted.
        </span>
    </div>
</div>
</section>

        <section class="auth-visual">
    <img src="<?= BASE_URL ?>/assets/images/main.png" alt="">
</section>

        <section class="auth-panel">
            <h2>Welcome back</h2>
            <p class="auth-subtitle">Log in to access your dashboard.</p>

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

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="field">
                    <label for="email">Email Address</label>
                    <input id="email" type="email" name="email" value="<?= e($email) ?>" placeholder="Enter your email" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" placeholder="Enter your password" required>
                </div>

                <button class="button button-block" type="submit">Log In</button>
            </form>

            <p class="auth-footer">New to FreshTrack?<br><a href="register.php">Register as a Merchant or NGO Worker</a></p>
        </section>

    </main>

    <footer class="auth-bottom">
        <span>&copy; <?= date('Y') ?> FreshTrack. Final Year Project.</span>
    </footer>

</div>

</body>
</html>