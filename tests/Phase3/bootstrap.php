<?php

declare(strict_types=1);

// Load Composer autoloader (handles PSR-4 mapping for App\ namespace)
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

// Load project bootstrap (custom autoloader for standalone use)
require_once __DIR__ . '/../../src/bootstrap.php';
