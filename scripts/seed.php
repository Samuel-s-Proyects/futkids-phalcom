<?php
declare(strict_types=1);
$di = require dirname(__DIR__) . '/config/bootstrap.php';
$seeder = new App\Database\Seeder($di->getShared('db'), $di->getShared('security'), BASE_PATH . '/database/seed.json');
if (!$seeder->isEmpty()) {
    echo "La base ya contiene datos. No se sobrescribió información.\n";
    exit(0);
}
$seeder->run([
    'admin' => getenv('SEED_ADMIN_PASSWORD') ?: 'FutKids2026!',
    'referee' => getenv('SEED_REFEREE_PASSWORD') ?: 'Arbitro2026!',
]);
echo "Datos de demostración y cuentas creados.\n";
