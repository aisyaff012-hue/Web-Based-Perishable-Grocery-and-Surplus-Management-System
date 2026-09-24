<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('ngo');
refreshInventoryStatus($pdo);

$ngoId = $_SESSION['user_id'];

$meStatement = $pdo->prepare(
    "SELECT organization_name, latitude, longitude
     FROM users WHERE user_id = ? LIMIT 1"
);

$meStatement->execute([$ngoId]);
$me = $meStatement->fetch();

$hasLocation = $me
    && $me['latitude'] !== null
    && $me['longitude'] !== null;

$myLat = $hasLocation ? (float) $me['latitude'] : null;
$myLng = $hasLocation ? (float) $me['longitude'] : null;

$available = availableQuantitySql();

if ($hasLocation) {
    $distanceSelect = ', ' . distanceKmSql() . ' AS distance_km';
    $orderBy = 'ORDER BY distance_km ASC, inventory.expiry_date ASC';
} else {
    $distanceSelect = ', NULL AS distance_km';
    $orderBy = 'ORDER BY inventory.expiry_date ASC';
}

$sql = "SELECT
        inventory.item_id,
        inventory.product_name,
        inventory.unit,
        inventory.current_value,
        DATEDIFF(inventory.expiry_date, CURDATE()) AS days_remaining,
        {$available} AS available_quantity,
        users.user_id AS merchant_id,
        users.business_name,
        users.pickup_location,
        users.operating_hours,
        users.phone,
        users.address,
        users.shop_image,
        users.latitude,
        users.longitude
        {$distanceSelect}
     FROM inventory
     INNER JOIN users
        ON inventory.merchant_id = users.user_id
     WHERE inventory.status = 'surplus'
       AND inventory.removed_at IS NULL
       AND inventory.expiry_date >= CURDATE()
       AND users.latitude IS NOT NULL
       AND users.longitude IS NOT NULL
     HAVING available_quantity > 0
     {$orderBy}";

$statement = $pdo->prepare($sql);
$statement->execute($hasLocation ? [$myLat, $myLng, $myLat] : []);
$rows = $statement->fetchAll();

/*
 * One marker per merchant. The soonest expiry among a shop's
 * items decides the urgency colour of its pin, so a single
 * glance at the map shows where the pressure is.
 */
$merchants = [];

foreach ($rows as $row) {
    $key = (int) $row['merchant_id'];

    if (!isset($merchants[$key])) {
        $merchants[$key] = [
            'id' => $key,
            'name' => $row['business_name'],
            'location' => $row['pickup_location'],
            'hours' => $row['operating_hours'],
            'phone' => $row['phone'],
            'distance' => $row['distance_km'] !== null
                ? formatDistance((float) $row['distance_km'])
                : null,
            'lat' => (float) $row['latitude'],
            'lng' => (float) $row['longitude'],
            'soonest' => (int) $row['days_remaining'],
            'mapUrl' => mapLink(
                $row['address'] ?? null,
                (float) $row['latitude'],
                (float) $row['longitude']
            ),
            'image' => shopImageUrl($row['shop_image']),
            'items' => []
        ];
    }

    $merchants[$key]['soonest'] = min(
        $merchants[$key]['soonest'],
        (int) $row['days_remaining']
    );

    $merchants[$key]['items'][] = [
        'id' => (int) $row['item_id'],
        'name' => $row['product_name'],
        'quantity' => (int) $row['available_quantity'],
        'unit' => $row['unit'],
        'value' => formatCurrency((float) $row['current_value']),
        'days' => formatDaysLeft((int) $row['days_remaining'])
    ];
}

$merchants = array_values($merchants);

$pageTitle = 'Surplus Map';
$activePage = 'map';

require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="section-head">
    <div>
        <h2>Surplus Map</h2>
        <p>
            <?= count($merchants) ?> merchant<?= count($merchants) === 1 ? '' : 's' ?>
            <?= $hasLocation ? 'near you' : 'with surplus' ?>
            <?php if ($hasLocation && $merchants): ?>
                &middot; closest is <?= e($merchants[0]['distance']) ?>
            <?php endif; ?>
        </p>
    </div>
</section>

<?php if (!$hasLocation): ?>
    <div class="alert alert-info">
        Add your organisation's coordinates in
        <a href="profile.php">your profile</a> to see how far each merchant is.
    </div>
<?php endif; ?>

<?php if (!$merchants): ?>
    <div class="panel">
        <div class="empty">
            <strong>No surplus to map right now</strong>
            Merchants appear here once they have surplus and have saved their coordinates.
        </div>
    </div>
<?php else: ?>
    <div class="map-stage">
        <div id="fullMap"></div>

        <?php if ($hasLocation): ?>
            <button class="map-fab" type="button" onclick="goHome()" title="Back to my location">&#9678;</button>
            <button class="map-nearest" type="button" onclick="goNearest()">Nearest merchant</button>
        <?php endif; ?>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
        var merchants = <?= json_encode($merchants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var myPosition = <?= $hasLocation ? json_encode([$myLat, $myLng]) : 'null' ?>;
        var myName = <?= json_encode($me['organization_name'] ?? 'Your organisation') ?>;

        (function () {
            var map = L.map('fullMap', { zoomControl: false });

            L.control.zoom({ position: 'bottomright' }).addTo(map);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var bounds = [];
            var markers = [];

            if (myPosition) {
                L.circleMarker(myPosition, {
                    radius: 9,
                    color: '#1b5e20',
                    fillColor: '#43a047',
                    fillOpacity: 1,
                    weight: 3
                }).addTo(map).bindPopup('<strong>' + myName + '</strong>');

                bounds.push(myPosition);
            }

            function initials(name) {
                return name.trim().charAt(0).toUpperCase();
            }

            function urgencyClass(days) {
                if (days <= 1) {
                    return 'pin-urgent';
                }

                if (days <= 2) {
                    return 'pin-soon';
                }

                return 'pin-ok';
            }

            merchants.forEach(function (shop, index) {
                var label = shop.items.length
                    + (shop.items.length === 1 ? ' item' : ' items');

                var icon = L.divIcon({
                    className: '',
                    html: '<div class="pin ' + urgencyClass(shop.soonest) + '">'
                        + (shop.image
                            ? '<span class="pin-face pin-photo"><img src="' + shop.image + '" alt=""></span>'
                            : '<span class="pin-face">' + initials(shop.name) + '</span>')
                        + '<span class="pin-tag">' + label + '</span>'
                        + '</div>',
                    iconSize: [54, 70],
                    iconAnchor: [27, 54],
                    popupAnchor: [0, -54]
                });

                 var lines = shop.items.slice(0, 3).map(function (item) {
                    return '<li>'
                        + '<span class="pop-item">' + item.name + '</span>'
                        + '<span class="pop-meta">' + item.quantity + ' ' + item.unit
                        + ' &middot; ' + item.value + ' &middot; ' + item.days + ' left</span>'
                        + '</li>';
                }).join('');
                        if (shop.items.length > 3) {
                lines += '<li class="pop-more">+'
                     + (shop.items.length - 3) + ' more</li>';
                }

                var popup = '<div class="pop">'
                    + '<div class="pop-head">'
                    + '<strong class="pop-name">' + shop.name + '</strong>'
                    + (shop.distance ? '<span class="pop-distance">' + shop.distance + '</span>' : '')
                    + '</div>'
                    + '<ul class="pop-facts">'
                    + '<li><span class="pop-icon">&#128205;</span>' + shop.location + '</li>'
                    + (shop.hours ? '<li><span class="pop-icon">&#128336;</span>' + shop.hours + '</li>' : '')
                    + (shop.phone ? '<li><span class="pop-icon">&#128222;</span>' + shop.phone + '</li>' : '')
                    + '</ul>'
                    + '<ul class="pop-list">' + lines + '</ul>'
                    + '<div class="pop-actions">'
                    + '<a class="button button-small" href="surplus.php?merchant=' + shop.id + '">Reserve</a>'
                    + (shop.mapUrl
                        ? '<a class="button button-small button-outline" href="' + shop.mapUrl
                          + '" target="_blank" rel="noopener noreferrer">Directions</a>'
                        : '')
                    + '</div>'
                    + '</div>';

                var marker = L.marker([shop.lat, shop.lng], { icon: icon })
                    .addTo(map)
                    .bindPopup(popup, {
                        maxWidth: 280,
                        autoPanPadding: [30, 90]
                    });

                markers.push(marker);
                bounds.push([shop.lat, shop.lng]);
            });

            if (bounds.length === 1) {
                map.setView(bounds[0], 15);
            } else {
                map.fitBounds(bounds, { padding: [60, 60] });
            }

            setTimeout(function () {
                map.invalidateSize();
            }, 200);

            window.goHome = function () {
                if (myPosition) {
                    map.setView(myPosition, 14);
                }
            };

            /*
             * The list arrives sorted by distance, so the first
             * merchant is already the nearest one.
             *
             * The view is centred below the pin so the popup, which
             * opens upward, lands inside the frame. Leaflet's own
             * auto-pan is switched off for this one call, otherwise
             * it would recentre and undo the offset.
             */
            window.goNearest = function () {
                if (!markers.length) {
                    return;
                }

                var marker = markers[0];
                var popup = marker.getPopup();

                var centre = map.project(
                    [merchants[0].lat, merchants[0].lng],
                    16
                ).add([0, -130]);

                map.setView(map.unproject(centre, 16), 16);

                popup.options.autoPan = false;
                marker.openPopup();

                // Restored so a normal click still pans the popup in.
                setTimeout(function () {
                    popup.options.autoPan = true;
                }, 300);
            };
        })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>