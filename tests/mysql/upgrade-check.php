<?php
// Upgrade check on MySQL (SQLite is covered by the PHPUnit Migration suite):
// a database at migration 011 with global matchdays from the previous version is migrated to the latest
// version and must keep its data. Run against an EMPTY database:
//   DB_DRIVER=mysql DB_NAME=cantera_upgrade … php tests/mysql/upgrade-check.php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';
$settings = require BASE_PATH . '/config/config.php';
if ($settings['database']['driver'] !== 'mysql') {
    fwrite(STDERR, "Esta verificación es para MySQL (DB_DRIVER=mysql).\n");
    exit(1);
}
$di = (require BASE_PATH . '/config/services.php')($settings);
\Phalcon\Di\Di::setDefault($di);
$db = $di->getShared('db');
$migrator = $di->getShared('migrator');

$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALLA: {$name}\n");
        exit(1);
    }
    $checks++;
    echo "PASS: {$name}\n";
};
$all = static fn(string $sql): array => $db->fetchAll($sql, \Phalcon\Db\Enum::FETCH_ASSOC);

$migrator->migrate(until: '011_users_management');
\Tests\Migration\LegacyDataFixture::load($db);
$db->insertAsDict('tournaments', ['id' => 50, 'name' => 'Copa', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'in_progress', 'draw_method' => 'manual']);
$db->insertAsDict('tournament_matches', ['id' => 500, 'tournament_id' => 50, 'round_number' => 1, 'match_number' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'status' => 'pending', 'workflow_status' => 'unprogrammed', 'matchday_id' => 7, 'result_revision' => 0]);

$check($migrator->migrate() !== [], 'Migraciones pendientes aplicadas');
$check($migrator->migrate() === [], 'Volver a migrar no aplica nada');

$historic = $all('SELECT id, type, status FROM tournaments WHERE is_legacy = 1');
$check(count($historic) === 1 && $historic[0]['type'] === 'league' && $historic[0]['status'] === 'finished', 'Torneo histórico creado');
$matchdays = $all('SELECT id, tournament_id FROM matchdays ORDER BY id');
$check(array_map('intval', array_column($matchdays, 'id')) === [7, 8] && array_values(array_unique(array_map('intval', array_column($matchdays, 'tournament_id')))) === [(int)$historic[0]['id']], 'Jornadas conservan su id y pasan al histórico');
$check((int)array_sum(array_column($all('SELECT goals FROM goals'), 'goals')) === 4, 'Goles históricos intactos');
$check($all('SELECT matchday_id FROM tournament_matches WHERE id = 500')[0]['matchday_id'] === null, 'Partido de clasificación desvinculado de la jornada');
$check(isset($db->describeIndexes('matchdays')['uq_matchdays_tournament_number']), 'Número de jornada único por torneo');

$db->insertAsDict('matchdays', ['tournament_id' => 50, 'number' => 1, 'game_date' => '2026-09-05']);
$check(true, 'El mismo número se permite en otro torneo');
try {
    $db->insertAsDict('matchdays', ['tournament_id' => 50, 'number' => 1, 'game_date' => '2026-09-06']);
    $check(false, 'Número duplicado en el mismo torneo rechazado');
} catch (\PDOException) {
    $check(true, 'Número duplicado en el mismo torneo rechazado');
}
echo "\n{$checks} verificaciones de actualización en MySQL correctas.\n";
