<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('merchant');
refreshInventoryStatus($pdo);

$merchantId = $_SESSION['user_id'];

$notifications = getNotifications($pdo, $merchantId);

/*
 * Tanda dah dibaca SELEPAS diambil (bukan sebelum), supaya
 * notifikasi baru masih highlight pada kunjungan ni, dan cuma
 * nampak "dah dibaca" pada kunjungan seterusnya.
 */
markNotificationsRead($pdo, $merchantId);

$pageTitle = 'Notifications';
$activePage = 'notifications';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Notifications</h2>
    </div>
</section>

<div class="panel">
    <div class="panel-body">
        <?php if (!$notifications): ?>
            <div class="empty">
                <strong>No notifications</strong>
                Alerts appear as your inventory changes status.
            </div>
        <?php else: ?>
            <div class="note-list">
                <?php foreach ($notifications as $note): ?>
                    <div class="note <?= (int) $note['is_read'] === 0 ? 'note-unread' : '' ?>">
                        <span class="note-dot"></span>

                        <div class="note-body">
                            <strong><?= e($note['title']) ?></strong>
                            <p><?= e($note['message']) ?></p>
                            <span class="feed-time"><?= date('d M Y, g:i A', strtotime($note['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>