<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

refreshInventoryStatus($pdo, true);

echo 'Status refresh completed at '
    . date('Y-m-d H:i:s') . PHP_EOL;