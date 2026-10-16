<?php
declare(strict_types=1);
$di = require dirname(__DIR__) . '/config/bootstrap.php';
$applied = $di->getShared('migrator')->migrate(static function (string $message): void { echo $message, "\n"; });
if (!$applied) echo "Esquema actualizado.\n";
