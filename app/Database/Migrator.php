<?php
declare(strict_types=1);
namespace App\Database;

use App\Support\Clock;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * Applies the ordered steps found in database/migrations exactly once.
 *
 * A version (e.g. "008_tournament_format") may consist of:
 *  - "<version>-<driver>.sql": schema changes, one file per supported driver;
 *  - "<version>.php": a data step returning fn(AdapterInterface $db, string $driver, Clock $clock): void.
 * When both exist the SQL runs first. PHP steps must be idempotent because MySQL DDL commits implicitly.
 */
final class Migrator
{
    private const DRIVERS = ['mysql', 'sqlite'];
    private const FILE_PATTERN = '/^(\d{3}_[a-z0-9_]+)(?:-(mysql|sqlite))?\.(sql|php)$/';

    public function __construct(
        private AdapterInterface $db,
        private string $driver,
        private string $directory,
        private Clock $clock,
    ) {
        if (!in_array($driver, self::DRIVERS, true)) throw new \InvalidArgumentException("Motor de base de datos no admitido: {$driver}");
    }

    /**
     * @param callable(string):void|null $log
     * @param ?string $until last version to apply (inclusive); null applies everything
     * @return list<string> versions applied by this run
     */
    public function migrate(?callable $log = null, ?string $until = null): array {
        $this->db->execute('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(30) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $applied = [];
        foreach ($this->pending() as $version => $step) {
            if ($until !== null && strcmp($version, $until) > 0) break;
            if (isset($step['sql'])) $this->runSqlFile($step['sql']);
            if (isset($step['php'])) $this->runPhpFile($step['php']);
            $this->db->insertAsDict('schema_migrations', ['version' => $version]);
            $applied[] = $version;
            if ($log) $log("Migración {$version} aplicada.");
        }
        return $applied;
    }

    /** @return array<string, array{sql?: string, php?: string}> */
    public function pending(): array {
        $done = array_column($this->db->fetchAll('SELECT version FROM schema_migrations', Enum::FETCH_ASSOC), 'version');
        return array_diff_key($this->discover(), array_flip($done));
    }

    /** @return array<string, array{sql?: string, php?: string}> */
    private function discover(): array {
        $steps = [];
        $sqlDrivers = [];
        foreach (scandir($this->directory) ?: [] as $file) {
            if (!preg_match(self::FILE_PATTERN, $file, $match)) continue;
            [, $version, $driver, $extension] = $match;
            $path = $this->directory . DIRECTORY_SEPARATOR . $file;
            if ($extension === 'php') {
                $steps[$version]['php'] = $path;
                continue;
            }
            if ($driver === '') throw new \RuntimeException("La migración {$file} debe indicar el motor (-mysql o -sqlite).");
            $sqlDrivers[$version][] = $driver;
            if ($driver === $this->driver) $steps[$version]['sql'] = $path;
        }
        foreach ($sqlDrivers as $version => $drivers) {
            $missing = array_diff(self::DRIVERS, $drivers);
            if ($missing) throw new \RuntimeException("La migración {$version} no tiene versión para: " . implode(', ', $missing) . '.');
        }
        ksort($steps, SORT_STRING);
        return $steps;
    }

    private function runSqlFile(string $path): void {
        foreach (explode(';', (string)file_get_contents($path)) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || $this->indexAlreadyExists($statement)) continue;
            $this->db->execute($statement);
        }
    }

    /** Re-running a partially applied MySQL migration must not fail on indexes it already created. */
    private function indexAlreadyExists(string $statement): bool {
        if (!preg_match('/^CREATE (?:UNIQUE )?INDEX (\w+) ON (\w+)/i', $statement, $match)) return false;
        return isset($this->db->describeIndexes($match[2])[$match[1]]);
    }

    private function runPhpFile(string $path): void {
        $step = require $path;
        if (!is_callable($step)) throw new \RuntimeException("La migración {$path} debe devolver una función.");
        $step($this->db, $this->driver, $this->clock);
    }
}
