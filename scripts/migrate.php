<?php
declare(strict_types=1);
$di = require dirname(__DIR__) . '/config/bootstrap.php';
$db = $di->getShared('db');
$driver = $di->getShared('settings')['database']['driver'];
$db->execute('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(30) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$applied = static fn(string $version): bool => (bool)$db->fetchOne('SELECT version FROM schema_migrations WHERE version = ?', \Phalcon\Db\Enum::FETCH_ASSOC, [$version]);
$applySql = static function (string $file) use ($db): void {
    foreach (explode(';', file_get_contents($file)) as $sql) {
        $sql = trim($sql);
        if (!$sql) continue;
        if (preg_match('/^CREATE INDEX (\w+) ON (\w+)/', $sql, $match) && isset($db->describeIndexes($match[2])[$match[1]])) continue;
        $db->execute($sql);
    }
};
if (!$applied('001_initial')) {
    $applySql(BASE_PATH . '/database/schema-' . $driver . '.sql');
    $db->execute('CREATE TABLE IF NOT EXISTS tournament_lock (id INTEGER PRIMARY KEY)');
    if (!$db->fetchOne('SELECT id FROM tournament_lock WHERE id = 1')) $db->insertAsDict('tournament_lock', ['id' => 1]);
    $db->insertAsDict('schema_migrations', ['version' => '001_initial']);
    echo "Migración 001 aplicada.\n";
}
if (!$applied('002_tournaments')) {
    $applySql(BASE_PATH . '/database/migrations/002_tournaments-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '002_tournaments']);
    echo "Migración 002 aplicada.\n";
}
if (!$applied('003_bracket')) {
    $applySql(BASE_PATH . '/database/migrations/003_bracket-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '003_bracket']);
    echo "Migración 003 aplicada.\n";
}
if (!$applied('004_tiebreak_rules')) {
    $applySql(BASE_PATH . '/database/migrations/004_tiebreak_rules-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '004_tiebreak_rules']);
    echo "Migración 004 aplicada.\n";
}
if (!$applied('005_match_results')) {
    $applySql(BASE_PATH . '/database/migrations/005_match_results-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '005_match_results']);
    echo "Migración 005 aplicada.\n";
}
if (!$applied('006_match_workflow')) {
    $applySql(BASE_PATH . '/database/migrations/006_match_workflow-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '006_match_workflow']);
    echo "Migración 006 aplicada.\n";
}
if (!$applied('007_match_audit_snapshot')) {
    $applySql(BASE_PATH . '/database/migrations/007_match_audit_snapshot-' . $driver . '.sql');
    $db->insertAsDict('schema_migrations', ['version' => '007_match_audit_snapshot']);
    echo "Migración 007 aplicada.\n";
}
if ($applied('001_initial') && $applied('002_tournaments') && $applied('003_bracket') && $applied('004_tiebreak_rules') && $applied('005_match_results') && $applied('006_match_workflow') && $applied('007_match_audit_snapshot')) echo "Esquema actualizado.\n";
