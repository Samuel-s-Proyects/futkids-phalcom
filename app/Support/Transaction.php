<?php
declare(strict_types=1);
namespace App\Support;

use Phalcon\Db\Adapter\AdapterInterface;

/**
 * Runs a unit of work atomically. On MySQL every write also takes the single-row tournament lock,
 * so concurrent operations (e.g. a red card and a goal for the same player) are serialised.
 */
final class Transaction
{
    public function __construct(private AdapterInterface $db, private string $driver) {}

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed {
        $this->db->begin();
        try {
            if ($this->driver === 'mysql') $this->db->fetchOne('SELECT id FROM tournament_lock WHERE id = 1 FOR UPDATE');
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
}
