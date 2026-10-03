<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Dijalankan secara berjadual (cron/Task Scheduler) supaya status
// produk (available/near_expiry/surplus/expired) sentiasa terkini
// walaupun tiada sesiapa buka website pada masa tu.
refreshInventoryStatus($pdo, true);

echo 'Status refresh completed at '
    . date('Y-m-d H:i:s') . PHP_EOL;