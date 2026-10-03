<?php

/*
 * Shell page yang dikongsi: buka dokumen, sidebar dan top bar.
 * Setiap dashboard page include fail ni dulu, kemudian tutup
 * dengan footer.php.
 *
 * Dijangka sedia sebelum include fail ni:
 *   $pageTitle   string
 *   $activePage  string
 */

$role = currentRole();
$displayId = $_SESSION['display_id'] ?? '';
$fullName = $_SESSION['full_name'] ?? '';
$entityName = $_SESSION['entity_name'] ?? '';

// Menu sidebar berbeza ikut role: merchant nampak menu
// stok/jualan, NGO nampak menu surplus/tempahan.
$navigation = $role === 'merchant'
    ? [
        'dashboard' => ['Dashboard', 'dashboard.php'],
        'listings'  => ['My Listings', 'listings.php'],
        'surplus'   => ['Donations', 'surplus.php'],
        'requests'  => ['Requests', 'requests.php'],
        'reports'   => ['Reports', 'reports.php']
    ]
    : [
        'dashboard'     => ['Dashboard', 'dashboard.php'],
        'surplus'       => ['Available Surplus', 'surplus.php'],
        'map'           => ['Map', 'map.php'],
        'reservations'  => ['My Reservations', 'reservations.php'],
        'pickups'       => ['Pickup Schedule', 'pickups.php'],
        'reports'       => ['Reports', 'reports.php'],
    ];

// Jumlah notifikasi belum dibaca, untuk papar badge kat loceng
// (topbar) dan sidebar.
$unreadCount = isset($pdo)
    ? getUnreadCount($pdo, (int) $_SESSION['user_id'])
    : 0;

if (isset($pdo)) {
    try {
        $unreadStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM notifications
             WHERE user_id = ?
               AND is_read = 0"
        );

        $unreadStatement->execute([$_SESSION['user_id']]);
        $unreadCount = (int) $unreadStatement->fetchColumn();
    } catch (PDOException $exception) {
        $unreadCount = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

   <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/logo.png">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/main.css?v=<?= filemtime(
            dirname(__DIR__, 2) . '/assets/css/main.css'
        ) ?>"
    >

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/app.css?v=<?= filemtime(
            dirname(__DIR__, 2) . '/assets/css/app.css'
        ) ?>"
    >
</head>
<body>

<div class="shell <?= ($_COOKIE['sidebar'] ?? 'open') === 'closed' ? 'shell-collapsed' : '' ?>">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <a class="brand" href="dashboard.php">
                <img class="brand-logo" src="<?= BASE_URL ?>/assets/images/logo.png" alt="FreshTrack">

                <span class="brand-text">
                    FreshTrack
                    <span class="brand-role"><?= $role === 'merchant' ? 'Merchant' : 'NGO' ?></span>
                </span>
            </a>

            <button class="sidebar-toggle" type="button" onclick="toggleSidebar()" title="Collapse sidebar" aria-label="Collapse sidebar">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($navigation as $key => $navItem): ?>
                <a class="nav-link <?= $activePage === $key ? 'nav-link-active' : '' ?>" href="<?= e($navItem[1]) ?>" title="<?= e($navItem[0]) ?>">
                    <?= navIcon($key) ?>
                    <span class="nav-text"><?= e($navItem[0]) ?></span>

                    <?php if ($key === 'notifications' && $unreadCount > 0): ?>
                        <span class="nav-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="nav-link <?= $activePage === 'help' ? 'nav-link-active' : '' ?>" href="help.php" title="Help">
                <?= navIcon('help') ?>
                <span class="nav-text">Help</span>
            </a>

            <a class="nav-link <?= $activePage === 'profile' ? 'nav-link-active' : '' ?>" href="profile.php" title="Profile">
                <?= navIcon('profile') ?>
                <span class="nav-text">Profile</span>
            </a>

            <a class="nav-link <?= $activePage === 'settings' ? 'nav-link-active' : '' ?>" href="settings.php" title="Settings">
                <?= navIcon('settings') ?>
                <span class="nav-text">Settings</span>
            </a>

            <button class="nav-link nav-link-quiet nav-button" type="button" onclick="askLogout()" title="Logout">
                <?= navIcon('logout') ?>
                <span class="nav-text">Logout</span>
            </button>
        </div>
    </aside>

    <div class="main">

        <header class="topbar">
            <h1 class="topbar-title"><?= e($pageTitle) ?></h1>

            <div class="topbar-side">
    <a class="bell <?= $unreadCount > 0 ? 'bell-active' : '' ?>" href="notifications.php" title="<?= $unreadCount ?> unread">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.7 21a2 2 0 0 1-3.4 0" />
        </svg>

        <?php if ($unreadCount > 0): ?>
            <span class="bell-count"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
        <?php endif; ?>
    </a>

    <div class="user-chip">
        <span class="user-avatar"><?= e(strtoupper(substr($fullName, 0, 1))) ?></span>

        <span class="user-meta">
            <strong><?= e($fullName) ?></strong>
            <span><?= $role === 'merchant' ? 'Merchant' : 'NGO Worker' ?> &middot; <?= e($displayId) ?></span>
        </span>
    </div>
</div>
        </header>

        <main class="content">