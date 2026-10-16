<?php
declare(strict_types=1);
namespace App\Database;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;
use Phalcon\Encryption\Security;

/** Loads the demonstration catalogue and the initial accounts into an empty database. */
final class Seeder
{
    /** Insertion order respects foreign keys. */
    private const TABLES = ['teams', 'players', 'tournaments', 'tournament_teams', 'matchdays', 'goals', 'incidents'];

    public function __construct(private AdapterInterface $db, private Security $security, private string $seedFile) {}

    public function isEmpty(): bool {
        return (int)$this->db->fetchOne('SELECT COUNT(*) AS total FROM teams', Enum::FETCH_ASSOC)['total'] === 0;
    }

    /** @param array{admin: string, referee: string} $passwords */
    public function run(array $passwords): void {
        $seed = json_decode((string)file_get_contents($this->seedFile), true, 512, JSON_THROW_ON_ERROR);
        $this->db->begin();
        try {
            foreach (self::TABLES as $table) foreach ($seed[$table] ?? [] as $row) $this->db->insertAsDict($table, $row);
            $accounts = [
                ['Administrador', 'admin@cantera.local', 'admin', $passwords['admin']],
                ['Árbitro', 'arbitro@cantera.local', 'referee', $passwords['referee']],
            ];
            foreach ($accounts as [$name, $email, $role, $password]) {
                $this->db->insertAsDict('users', ['name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => $this->security->hash($password)]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
