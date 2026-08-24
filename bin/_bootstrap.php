<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are missing. Run: composer install\n");
    exit(1);
}
require $autoload;

$config = require BASE_PATH . '/config/app.php';
date_default_timezone_set($config['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', '1');
