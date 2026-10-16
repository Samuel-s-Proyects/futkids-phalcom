<?php
declare(strict_types=1);
if (!extension_loaded('phalcon')) {
    throw new RuntimeException('Phalcon no está instalado. Utilice Docker según README.md.');
}
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
$settings = require BASE_PATH . '/config/config.php';
date_default_timezone_set($settings['timezone']);
require_once BASE_PATH . '/config/loader.php';
return (require BASE_PATH . '/config/services.php')($settings);
