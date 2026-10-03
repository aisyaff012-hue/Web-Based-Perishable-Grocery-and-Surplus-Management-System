<?php

/*
 * Fungsi helper yang dikongsi oleh seluruh FreshTrack.
 */


/* ============================================================
 * Output dan format
 * ============================================================ */

// Escape output untuk elak XSS sebelum dipaparkan dalam HTML.
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Format nombor jadi harga, cth: 12.5 -> "RM 12.50".
function formatCurrency(float $amount): string
{
    return 'RM ' . number_format($amount, 2);
}

// Tukar status database (snake_case) jadi label senang baca,
// cth: near_expiry -> "Near Expiry".
function statusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

// Tukar baki hari jadi teks: "Expired", "Today", atau "X day(s)".
function formatDaysLeft(int $days): string
{
    if ($days < 0) {
        return 'Expired';
    }

    if ($days === 0) {
        return 'Today';
    }

    return $days . ($days === 1 ? ' day' : ' days');
}


/* ============================================================
 * Data rujukan
 * ============================================================ */

// Senarai kategori produk yang dibenarkan (dropdown add/edit item).
function productCategories(): array
{
    return [
        'Bakery',
        'Dairy',
        'Fruits',
        'Vegetables',
        'Beverages',
        'Frozen',
        'Other'
    ];
}

// Senarai unit produk yang dibenarkan (dropdown add/edit item).
function productUnits(): array
{
    return ['units', 'kg', 'g', 'litre', 'packs', 'boxes', 'loaves'];
}


/* ============================================================
 * Display ID dan mesej flash
 * ============================================================ */

/*
 * Jana display ID seterusnya untuk satu role, cth M001 atau N001.
 * Nombor diambil dari display ID tertinggi yang sedia ada supaya
 * urutan kekal senang dibaca. UNIQUE index pada display_id
 * melindungi daripada dua orang register pada masa yang sama;
 * pemanggil (caller) akan cuba lagi bila situasi tu berlaku.
 */
function generateDisplayId(PDO $pdo, string $role): string
{
    $prefix = $role === 'merchant' ? 'M' : 'N';

    $statement = $pdo->prepare(
        "SELECT COALESCE(
                    MAX(CAST(SUBSTRING(display_id, 2) AS UNSIGNED)),
                    0
                ) + 1
         FROM users
         WHERE role = ?"
    );

    $statement->execute([$role]);

    $nextNumber = (int) $statement->fetchColumn();

    return $prefix
        . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
}

// Simpan mesej sekali guna untuk dipaparkan pada page load seterusnya.
function setFlash(string $key, $value): void
{
    $_SESSION['flash'][$key] = $value;
}

// Ambil mesej flash, terus padam supaya ia tak muncul lagi pada
// page load yang berikutnya (sekali paparan sahaja).
function getFlash(string $key, $default = null)
{
    if (!isset($_SESSION['flash'][$key])) {
        return $default;
    }

    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $value;
}


/* ============================================================
 * Dynamic pricing
 * ============================================================
 * Peraturan datang dari merchant_settings supaya peratusan boleh
 * ditukar kemudian tanpa sentuh kod ni:
 *
 *   lebih dari near_expiry_days   -> available,   nilai penuh
 *   near_expiry_days .. 4         -> near_expiry, nilai dikurangkan
 *   donation_threshold_days       -> surplus,     percuma untuk NGO
 *   tarikh luput dah lepas        -> expired,     tiada nilai
 */

// Ambil tetapan pricing & notifikasi untuk satu merchant.
function getMerchantSettings(PDO $pdo, int $merchantId): array
{
    $statement = $pdo->prepare(
        "SELECT
            near_expiry_days,
            reduction_percentage,
            donation_threshold_days,
            notify_reservation,
            notify_surplus
         FROM merchant_settings
         WHERE merchant_id = ?
         LIMIT 1"
    );

    $statement->execute([$merchantId]);
    $settings = $statement->fetch();

    // Guna nilai default kalau baris settings tu tak wujud.
    if (!$settings) {
        return [
            'near_expiry_days' => 5,
            'reduction_percentage' => 10.00,
            'donation_threshold_days' => 3,
            'notify_reservation' => 1,
            'notify_surplus' => 1
        ];
    }

    return $settings;
}

// Kira baki hari sampai tarikh luput (boleh jadi negatif kalau
// tarikh tu dah lepas).
function daysUntil(string $expiryDate): int
{
    $today = new DateTime('today');
    $expiry = new DateTime($expiryDate);

    return (int) $today->diff($expiry)->format('%r%a');
}

/*
 * Kira status dan nilai semasa untuk satu item. Pulangkan
 * days_remaining, status, reduction_percentage, current_value.
 */
function calculatePricing(
    float $basePrice,
    string $expiryDate,
    array $settings
): array {
    $daysRemaining = daysUntil($expiryDate);

    if ($daysRemaining < 0) {
        return [
            'days_remaining' => $daysRemaining,
            'status' => 'expired',
            'reduction_percentage' => 100.00,
            'current_value' => 0.00
        ];
    }

    $reduction = (float) $settings['reduction_percentage'];

    $reducedValue = round($basePrice * (100 - $reduction) / 100, 2);

    if ($daysRemaining <= (int) $settings['donation_threshold_days']) {
        return [
            'days_remaining' => $daysRemaining,
            'status' => 'surplus',
            'reduction_percentage' => $reduction,
            'current_value' => $reducedValue
        ];
    }

    if ($daysRemaining <= (int) $settings['near_expiry_days']) {
        return [
            'days_remaining' => $daysRemaining,
            'status' => 'near_expiry',
            'reduction_percentage' => $reduction,
            'current_value' => $reducedValue
        ];
    }

    return [
        'days_remaining' => $daysRemaining,
        'status' => 'available',
        'reduction_percentage' => 0.00,
        'current_value' => round($basePrice, 2)
    ];
}


/* ============================================================
 * Log aktiviti dan notifikasi
 * ============================================================ */

// Rekod satu entri aktiviti merchant (untuk paparan log/history).
function logActivity(
    PDO $pdo,
    int $merchantId,
    string $type,
    string $message
): void {
    $statement = $pdo->prepare(
        "INSERT INTO activity_log
            (merchant_id, activity_type, message)
         VALUES (?, ?, ?)"
    );

    $statement->execute([$merchantId, $type, $message]);
}

// Cipta satu notifikasi untuk user tertentu (merchant atau NGO).
function createNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $referenceType = 'general',
    ?int $referenceId = null
): void {
    $statement = $pdo->prepare(
        "INSERT INTO notifications
            (user_id, title, message, reference_type, reference_id)
         VALUES (?, ?, ?, ?, ?)"
    );

    $statement->execute([
        $userId,
        $title,
        $message,
        $referenceType,
        $referenceId
    ]);
}

// Maklumkan setiap NGO yang aktif bila ada surplus baru disenaraikan.
function notifyAllNgos(
    PDO $pdo,
    string $title,
    string $message,
    string $referenceType = 'general',
    ?int $referenceId = null
): void {
    $ngoStatement = $pdo->query(
        "SELECT user_id
         FROM users
         WHERE role = 'ngo'
           AND account_status = 'active'"
    );

    foreach ($ngoStatement->fetchAll() as $ngo) {
        createNotification(
            $pdo,
            (int) $ngo['user_id'],
            $title,
            $message,
            $referenceType,
            $referenceId
        );
    }
}

// Ambil senarai notifikasi user, belum dibaca dipaparkan dulu,
// diikuti yang terbaru.
function getNotifications(
    PDO $pdo,
    int $userId,
    int $limit = 30
): array {
    $statement = $pdo->prepare(
        "SELECT
            notification_id,
            title,
            message,
            reference_type,
            reference_id,
            is_read,
            created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY is_read ASC, created_at DESC
         LIMIT " . (int) $limit
    );

    $statement->execute([$userId]);

    return $statement->fetchAll();
}

// Kira jumlah notifikasi user yang belum dibaca (untuk badge).
function getUnreadCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ?
           AND is_read = 0"
    );

    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}

// Tanda semua notifikasi belum dibaca milik user sebagai dah dibaca.
function markNotificationsRead(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare(
        "UPDATE notifications
         SET is_read = 1,
             read_at = NOW()
         WHERE user_id = ?
           AND is_read = 0"
    );

    $statement->execute([$userId]);
}


/* ============================================================
 * Helper tempahan (reservation)
 * ============================================================ */

/*
 * Kuantiti yang masih terbuka untuk ditempah. Tempahan "pending"
 * dan "approved" mengunci stok, dan "completed" pun dah keluar
 * dari premis, jadi ketiga-tiganya ditolak. Hanya tempahan
 * "rejected" lepaskan balik kuantiti dia.
 */
function availableQuantitySql(): string
{
    return "(inventory.quantity - COALESCE((SELECT SUM(r.quantity_requested) FROM reservations r WHERE r.item_id = inventory.item_id AND r.status IN ('pending', 'approved', 'completed')), 0))";
}

/* ============================================================
 * Enjin status automatik
 * ============================================================
 * Scan semula setiap item dan gerakkan dia melalui lifecycle:
 *
 *   available -> near_expiry -> surplus -> expired
 *
 * Jalan pada setiap page load, merchant atau NGO, supaya sistem
 * sentiasa terkini walaupun merchant tu dah berhari-hari tak login.
 * Satu timestamp dalam system_state throttle enjin ni supaya ia
 * jalan paling banyak sekali setiap beberapa minit, bukan setiap
 * request.
 */

// Jarak masa minimum (saat) antara satu refresh status dengan
// yang seterusnya. Boleh ubah nilai ni kalau nak enjin jalan
// lebih kerap/kurang kerap.
const STATUS_REFRESH_INTERVAL_SECONDS = 10;

// Semak sama ada dah cukup masa sejak refresh status terakhir
// dijalankan.
function shouldRunRefresh(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        "SELECT state_value
         FROM system_state
         WHERE state_key = 'last_status_refresh'
         LIMIT 1"
    );

    $statement->execute();
    $lastRun = $statement->fetchColumn();

    if ($lastRun === false) {
        return true;
    }

    return (time() - strtotime($lastRun))
        >= STATUS_REFRESH_INTERVAL_SECONDS;
}

// Catat waktu sekarang sebagai waktu refresh status terakhir.
function markRefreshRan(PDO $pdo): void
{
    $statement = $pdo->prepare(
        "INSERT INTO system_state (state_key, state_value)
         VALUES ('last_status_refresh', NOW())
         ON DUPLICATE KEY UPDATE state_value = NOW()"
    );

    $statement->execute();
}

/*
 * Enjin sebenar.
 *
 * Notifikasi hanya dihantar bila status betul-betul berubah,
 * kalau tidak setiap page load akan ulang mesej yang sama.
 * Bila item luput, kuantiti yang tak sempat dikutip direkod
 * dalam wasted_quantity supaya Reports boleh tunjuk apa yang
 * sistem GAGAL selamatkan, bukan setakat apa yang berjaya.
 */
function refreshInventoryStatus(PDO $pdo, bool $force = false): void{
    if (!$force && !shouldRunRefresh($pdo)) {
        return;
    }

    $selectStatement = $pdo->query(
        "SELECT
            inventory.item_id,
            inventory.merchant_id,
            inventory.product_name,
            inventory.quantity,
            inventory.unit,
            inventory.base_price,
            inventory.expiry_date,
            inventory.status,
            inventory.current_value,
            inventory.reduction_percentage,
            inventory.surplus_since,
            users.business_name,
            COALESCE((SELECT SUM(r.quantity_requested) FROM reservations r WHERE r.item_id = inventory.item_id AND r.status = 'completed'), 0) AS collected_quantity
         FROM inventory
         INNER JOIN users
            ON inventory.merchant_id = users.user_id
         WHERE inventory.removed_at IS NULL
           AND inventory.status <> 'expired'"
    );

    $items = $selectStatement->fetchAll();

    if (!$items) {
        markRefreshRan($pdo);
        return;
    }

    $updateStatement = $pdo->prepare(
        "UPDATE inventory
         SET status = ?,
             current_value = ?,
             reduction_percentage = ?,
             surplus_since = ?,
             wasted_quantity = ?,
             expired_at = ?
         WHERE item_id = ?"
    );

    $settingsCache = [];

    foreach ($items as $row) {
        $merchantId = (int) $row['merchant_id'];

        if (!isset($settingsCache[$merchantId])) {
            $settingsCache[$merchantId] =
                getMerchantSettings($pdo, $merchantId);
        }

        $pricing = calculatePricing(
            (float) $row['base_price'],
            $row['expiry_date'],
            $settingsCache[$merchantId]
        );

        $oldStatus = $row['status'];
        $newStatus = $pricing['status'];

        /*
         * Status sahaja tak cukup untuk tentukan sama ada write
         * perlu dibuat. Merchant yang tukar reduction percentage
         * tak sentuh status langsung, tapi semua harga jadi
         * outdated — jadi nilai yang dikira pun kena dibanding.
         */
        $statusChanged = $oldStatus !== $newStatus;

        $valueChanged =
            (float) $row['current_value'] !== (float) $pricing['current_value']
            || (float) $row['reduction_percentage'] !== (float) $pricing['reduction_percentage'];

        if (!$statusChanged && !$valueChanged) {
            continue;
        }

        $surplusSince = $row['surplus_since'];
        $wastedQuantity = null;
        $expiredAt = null;

        /*
         * Semua di bawah ni adalah kesan daripada perubahan status:
         * stem tarikh surplus, tutup tempahan terbuka, rekod
         * pembaziran, log dan hantar notifikasi. Pengiraan semula
         * harga sahaja (tanpa tukar status) kena langkau semua ni,
         * kalau tidak tukar settings pun akan trigger notifikasi
         * yang sama berulang kali.
         */
        if ($statusChanged) {

        if ($newStatus === 'surplus' && $surplusSince === null) {
            $surplusSince = date('Y-m-d');
        }

                /*
         * Item yang dah luput tak boleh dikutip lagi, jadi mana-mana
         * tempahan yang masih terbuka ditutup di sini — bukan
         * dibiarkan terus mengunci kuantiti selama-lamanya.
         */
        if ($newStatus === 'expired') {
            $openStatement = $pdo->prepare(
                "SELECT reservations.request_id,
                        reservations.ngo_id,
                        reservations.quantity_requested,
                        users.organization_name
                 FROM reservations
                 INNER JOIN users
                    ON reservations.ngo_id = users.user_id
                 WHERE reservations.item_id = ?
                   AND reservations.status IN ('pending', 'approved')"
            );

            $openStatement->execute([$row['item_id']]);
            $openReservations = $openStatement->fetchAll();

            if ($openReservations) {
                $closeStatement = $pdo->prepare(
                    "UPDATE reservations
                     SET status = 'not_collected'
                     WHERE item_id = ?
                       AND status IN ('pending', 'approved')"
                );

                $closeStatement->execute([$row['item_id']]);

                foreach ($openReservations as $open) {
                    createNotification(
                        $pdo,
                        (int) $open['ngo_id'],
                        'Reservation closed',
                        $row['product_name'] . ' from '
                            . $row['business_name']
                            . ' reached its expiry date before collection. '
                            . 'Your reservation has been closed.',
                        'reservation',
                        (int) $open['request_id']
                    );

                    logActivity(
                        $pdo,
                        $merchantId,
                        'reservation_not_collected',
                        $open['organization_name']
                            . ' did not collect '
                            . (int) $open['quantity_requested'] . ' '
                            . $row['unit'] . ' of '
                            . $row['product_name'] . ' before expiry.'
                    );
                }
            }
        }

        if ($newStatus === 'expired') {
            $wastedQuantity = max(
                0,
                (int) $row['quantity']
                    - (int) $row['collected_quantity']
            );

            $expiredAt = date('Y-m-d');
        }
        }

        $updateStatement->execute([
            $newStatus,
            $pricing['current_value'],
            $pricing['reduction_percentage'],
            $surplusSince,
            $wastedQuantity,
            $expiredAt,
            $row['item_id']
        ]);

        if (!$statusChanged) {
            continue;
        }

        if ($newStatus === 'near_expiry') {
            logActivity(
                $pdo,
                $merchantId,
                'value_changed',
                $row['product_name'] . ' dropped to a '
                    . $pricing['reduction_percentage']
                    . '% reduced value.'
            );

            createNotification(
                $pdo,
                $merchantId,
                'Product approaching expiry',
                $row['product_name'] . ' has '
                    . $pricing['days_remaining']
                    . ' day(s) remaining. A '
                    . $pricing['reduction_percentage']
                    . '% value reduction has been applied.',
                'item',
                (int) $row['item_id']
            );
        }

        if ($newStatus === 'surplus') {
            logActivity(
                $pdo,
                $merchantId,
                'became_surplus',
                $row['product_name']
                    . ' automatically moved to Surplus - '
                    . $pricing['days_remaining']
                    . ' days remaining.'
            );

            if ((int) $settingsCache[$merchantId]['notify_surplus'] === 1) {
            createNotification(
                $pdo,
                $merchantId,
                'Product moved to surplus',
                $row['product_name']
                    . ' automatically moved to Surplus with '
                    . $pricing['days_remaining']
                    . ' days remaining. It is now visible to NGOs.',
                'item',
                (int) $row['item_id']
            );
            }

            notifyAllNgos(
                $pdo,
                'New surplus available',
                $row['business_name'] . ' has '
                    . $row['quantity'] . ' ' . $row['unit']
                    . ' of ' . $row['product_name']
                    . ' available as surplus. Expires '
                    . date('d M Y', strtotime($row['expiry_date']))
                    . '.',
                'item',
                (int) $row['item_id']
            );
        }

        if ($newStatus === 'expired') {
            logActivity(
                $pdo,
                $merchantId,
                'item_expired',
                $row['product_name'] . ' expired with '
                    . $wastedQuantity . ' ' . $row['unit']
                    . ' uncollected.'
            );

            createNotification(
                $pdo,
                $merchantId,
                'Product expired',
                $row['product_name']
                    . ' has passed its expiry date and was removed '
                    . 'from the surplus listing.',
                'item',
                (int) $row['item_id']
            );
        }
    }

    markRefreshRan($pdo);
}

/*
 * ============================================================
 * Helper paparan (presentation)
 * ============================================================
 */

/*
 * Ikon kategori jadi ganti gambar produk. Tak membebankan merchant
 * untuk uruskan, dan elak risiko keselamatan yang timbul kalau
 * paksa setiap produk upload gambar.
 */
function categoryIcon(string $category): string
{
    $icons = [
        'Bakery' => '&#127838;',
        'Dairy' => '&#129371;',
        'Fruits' => '&#127822;',
        'Vegetables' => '&#129382;',
        'Beverages' => '&#129380;',
        'Frozen' => '&#129482;',
        'Other' => '&#128230;'
    ];

    return $icons[$category] ?? $icons['Other'];
}

// Kelas warna untuk petunjuk baki hari di bawah tarikh luput
// (cth: merah bila dah urgent, hijau bila masih lama).
function daysUrgencyClass(int $days): string
{
    if ($days < 0) {
        return 'days-expired';
    }

    if ($days === 0) {
        return 'days-today';
    }

    if ($days <= 3) {
        return 'days-urgent';
    }

    if ($days <= 5) {
        return 'days-soon';
    }

    return 'days-fine';
}

/*
 * ============================================================
 * Upload gambar produk
 * ============================================================
 * Gambar bersifat pilihan (optional). Kalau tiada gambar
 * disimpan, ikon kategori dipaparkan sebagai ganti, jadi tiada
 * page langsung papar sel yang kosong.
 */

define('UPLOAD_MAX_BYTES', 2097152);

function productImageDirectory(): string
{
    return dirname(__DIR__) . '/assets/uploads/products/';
}

function productImageUrl(?string $fileName): ?string
{
    if (!$fileName) {
        return null;
    }

    // Jaga-jaga kalau nama fail yang tersimpan cuba rujuk ke luar
    // folder upload (path traversal).
    if (basename($fileName) !== $fileName) {
        return null;
    }

    if (!is_file(productImageDirectory() . $fileName)) {
        return null;
    }

    return BASE_URL . '/assets/uploads/products/' . $fileName;
}

/*
 * Papar thumbnail produk: gambar yang diupload kalau ada,
 * kalau tidak papar ikon kategori sebagai ganti.
 */
function productThumbnail(
    ?string $fileName,
    string $category,
    string $extraClass = ''
): string {
    $url = productImageUrl($fileName);

    if ($url) {
        return '<span class="category-icon has-photo ' . $extraClass
            . '"><img src="' . e($url) . '" alt=""></span>';
    }

    return '<span class="category-icon ' . $extraClass . '">'
        . categoryIcon($category) . '</span>';
}

function deleteProductImage(?string $fileName): void
{
    if (!$fileName || basename($fileName) !== $fileName) {
        return;
    }

    $path = productImageDirectory() . $fileName;

    if (is_file($path)) {
        unlink($path);
    }
}

/*
 * Sahkan dan simpan gambar yang diupload.
 *
 * Jenis fail disahkan guna getimagesize() bukan extension atau
 * MIME type yang dihantar browser, sebab dua-dua tu boleh
 * dipalsukan. Nama fail yang disimpan dijana di sini sahaja,
 * tak pernah diambil terus dari input user.
 *
 * Pulangkan [fileName, error]. Dua-dua null kalau tiada fail
 * dihantar.
 */
function saveProductImage(array $file, int $itemId): array
{
    if (
        !isset($file['error'])
        || $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return [null, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The image could not be uploaded.'];
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return [null, 'The image must be smaller than 2 MB.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid upload.'];
    }

    $imageInfo = getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        return [null, 'That file is not a valid image.'];
    }

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];

    if (!isset($allowed[$imageInfo[2]])) {
        return [null, 'Only JPG, PNG and WEBP images are accepted.'];
    }

    $extension = $allowed[$imageInfo[2]];

    $fileName = 'item_' . $itemId . '_'
        . bin2hex(random_bytes(4)) . '.' . $extension;

    $destination = productImageDirectory() . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return [null, 'The image could not be saved.'];
    }

    return [$fileName, null];
}
/*
 * Warna tetap untuk setiap kategori, supaya satu slice dalam
 * chart kekal warna yang sama setiap kali, bukan bertukar-tukar
 * ikut susunan data.
 */
function categoryColour(string $category): string
{
    $colours = [
        'Bakery' => '#d19a3e',
        'Dairy' => '#4a90d9',
        'Fruits' => '#e05a47',
        'Vegetables' => '#2e7d32',
        'Beverages' => '#0bb8ce',
        'Frozen' => '#7b83f5',
        'Other' => '#9aa5a0'
    ];

    return $colours[$category] ?? $colours['Other'];
}

/*
 * Formula Haversine untuk jarak dalam kilometer, ditulis terus
 * sebagai SQL supaya database boleh sort DAN filter ikut jarak
 * dalam satu pass sahaja. Placeholder diikat ikut urutan ni:
 * lat, lng, lat.
 */
function distanceKmSql(string $latColumn = 'users.latitude', string $lngColumn = 'users.longitude'): string
{
    return "(6371 * ACOS(
        LEAST(1, GREATEST(-1,
            COS(RADIANS(?)) * COS(RADIANS({$latColumn}))
            * COS(RADIANS({$lngColumn}) - RADIANS(?))
            + SIN(RADIANS(?)) * SIN(RADIANS({$latColumn}))
        ))
    ))";
}

/*
 * Tukar 0.4 jadi "400 m" dan 12.37 jadi "12.4 km".
 */
function formatDistance(?float $km): string
{
    if ($km === null) {
        return 'Distance unknown';
    }

    if ($km < 1) {
        return round($km * 1000) . ' m away';
    }

    return number_format($km, 1) . ' km away';
}

/*
 * Pendekkan teks waktu operasi untuk dipaparkan pada kad. Nama
 * hari dan waktu dipendekkan; apa-apa yang masih terlalu panjang
 * dipotong pada sempadan perkataan. Teks penuh kekal dalam tooltip.
 */
function shortHours(?string $hours): string
{
    $hours = trim((string) $hours);

    if ($hours === '') {
        return 'Hours not stated';
    }

    $days = [
        'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
        'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat',
        'Sunday' => 'Sun'
    ];

    $short = strtr($hours, $days);
    $short = preg_replace('/:00\s*(AM|PM)/i', '$1', $short);
    $short = preg_replace('/\s*[–—-]\s*/u', '–', $short);
    $short = preg_replace('/\s+/', ' ', trim($short));

    if (mb_strlen($short) <= 30) {
        return $short;
    }

    $cut = mb_substr($short, 0, 30);
    $lastSpace = mb_strrpos($cut, ' ');

    return ($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut) . '…';
}

/* ============================================================
 * Gambar kedai (shop photos)
 * ============================================================ */

function shopImageDirectory(): string
{
    return dirname(__DIR__) . '/assets/uploads/shops/';
}

function shopImageUrl(?string $fileName): ?string
{
    if (!$fileName || basename($fileName) !== $fileName) {
        return null;
    }

    if (!is_file(shopImageDirectory() . $fileName)) {
        return null;
    }

    return BASE_URL . '/assets/uploads/shops/' . $fileName;
}

function deleteShopImage(?string $fileName): void
{
    if (!$fileName || basename($fileName) !== $fileName) {
        return;
    }

    $path = shopImageDirectory() . $fileName;

    if (is_file($path)) {
        unlink($path);
    }
}

/*
 * Laluan pengesahan sama macam saveProductImage(): jenis fail
 * disahkan guna getimagesize() bukan extension atau MIME type
 * dari browser, dan nama fail yang disimpan dijana di sini
 * sahaja, tak pernah diambil terus dari input user.
 *
 * Pulangkan [fileName, error]. Dua-dua null kalau tiada fail
 * dihantar.
 */
function saveShopImage(array $file, int $merchantId): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The shop photo could not be uploaded.'];
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return [null, 'The shop photo must be smaller than 2 MB.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid upload.'];
    }

    $imageInfo = getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        return [null, 'That file is not a valid image.'];
    }

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];

    if (!isset($allowed[$imageInfo[2]])) {
        return [null, 'Only JPG, PNG and WEBP images are accepted.'];
    }

    $fileName = 'shop_' . $merchantId . '_'
        . bin2hex(random_bytes(4)) . '.' . $allowed[$imageInfo[2]];

    if (!move_uploaded_file($file['tmp_name'], shopImageDirectory() . $fileName)) {
        return [null, 'The shop photo could not be saved.'];
    }

    return [$fileName, null];
}

/*
 * Papar cover kedai: gambar yang diupload kalau ada, kalau tidak
 * bulatan berhuruf dibina daripada nama perniagaan.
 */
function shopCover(?string $fileName, string $businessName, string $extraClass = ''): string
{
    $url = shopImageUrl($fileName);

    if ($url) {
        return '<span class="shop-cover has-photo ' . $extraClass
            . '"><img src="' . e($url) . '" alt=""></span>';
    }

    $letter = mb_strtoupper(mb_substr(trim($businessName), 0, 1));

    return '<span class="shop-cover ' . $extraClass . '">'
        . '<span class="shop-cover-letter">' . e($letter) . '</span></span>';
}

/**
 * Bina link Google Maps untuk satu merchant.
 *
 * Koordinat digunakan dulu kalau ada, sebab ia tunjuk lokasi
 * tepat; alamat jalan jadi fallback. Nota pickup (cth "Main
 * Entrance") cuma arahan bila dah sampai di situ, jadi ia tak
 * sekali-kali digunakan untuk navigasi.
 */
function mapLink(
    ?string $address,
    ?float $latitude = null,
    ?float $longitude = null
): ?string {
    if ($latitude !== null && $longitude !== null) {
        return 'https://www.google.com/maps/search/?api=1&query='
            . $latitude . ',' . $longitude;
    }

    $address = trim((string) $address);

    if ($address === '') {
        return null;
    }

    return 'https://www.google.com/maps/search/?api=1&query='
        . urlencode($address);
}

/*
 * Ikon SVG inline untuk sidebar. Dikekalkan sebagai markup
 * (bukan fail imej) supaya ia ikut warna link dan kekal tajam
 * walaupun sidebar collapse.
 */
function navIcon(string $key): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'listings' => '<path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5" rx="1"/><path d="M10 12h4"/>',
        'surplus' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'requests' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'reports' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'reservations' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>',
        'pickups' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'notifications' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'map' => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
        'profile' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
                'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>'
    ];

    $shape = $paths[$key] ?? $paths['dashboard'];

    return '<svg class="nav-icon" width="19" height="19" viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="1.8"'
        . ' stroke-linecap="round" stroke-linejoin="round">'
        . $shape . '</svg>';
}
/*
 * Pisahkan teks waktu operasi pada setiap awalan hari, supaya
 * kedai yang ada waktu hari biasa dan hujung minggu berasingan
 * dipaparkan sebagai baris berasingan, bukan satu ayat panjang.
 */
function hoursLines(?string $hours): array
{
    $short = shortHours($hours);

    /*
     * Hanya pisah bila nama hari didahului ruang (space) — hari
     * dalam julat macam "Mon–Sat" didahului tanda sempang, jadi
     * julat tu kekal utuh (tak terpisah).
     */
    $parts = preg_split(
        '/(?<=\s)(?=(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s*:)/',
        $short,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    return array_map('trim', $parts);
}
/*
 * Pisahkan waktu operasi jadi baris tanpa dipendekkan, untuk
 * tempat yang ada ruang untuk teks penuh.
 */
function hoursLinesFull(?string $hours): array
{
    $hours = trim((string) $hours);

    if ($hours === '') {
        return ['Hours not stated'];
    }

    $days = 'Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday';

    /*
     * Pisah hanya di tempat blok hari baru bermula. Hari yang
     * didahului tanda sempang atau perkataan "to" adalah hujung
     * satu julat, bukan permulaan baris baru.
     */
    $parts = preg_split(
        '/(?<=\s)(?<![–—-]\s)(?<!to\s)(?=(?:' . $days . ')\s*:)/u',
        $hours,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    return array_map('trim', $parts);
}