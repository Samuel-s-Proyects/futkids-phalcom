<?php
declare(strict_types=1);

if (!extension_loaded('phalcon') || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Las pruebas necesitan Phalcon y pdo_sqlite. Ejecútelas con: docker compose run --rm --build test\n");
    exit(1);
}
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(Tests\Support\TestApplication::TIMEZONE);
register_shutdown_function([Tests\Support\TestDatabase::class, 'cleanup']);
