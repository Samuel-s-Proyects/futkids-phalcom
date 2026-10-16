<?php
declare(strict_types=1);
namespace Tests\Migration;

use App\Database\Migrator;
use App\Support\FrozenClock;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;
use Tests\Support\TestDatabase;

final class MigratorTest extends TestCase
{
    private Sqlite $db;
    private string $directory;

    protected function setUp(): void {
        $this->db = new Sqlite(['dbname' => TestDatabase::emptyFile()]);
        $this->db->execute('PRAGMA foreign_keys = ON');
        $this->directory = sys_get_temp_dir() . '/futkids-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void {
        $this->db->close();
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    private function migrator(string $directory): Migrator {
        return new Migrator($this->db, 'sqlite', $directory, FrozenClock::at(TestApplication::FIXED_NOW, TestApplication::TIMEZONE));
    }

    public function testProjectMigrationsApplyInOrderAndOnlyOnce(): void {
        $migrator = $this->migrator(BASE_PATH . '/database/migrations');
        $applied = $migrator->migrate();
        $this->assertSame('001_initial', $applied[0]);
        $sorted = $applied;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $applied);
        $this->assertSame([], $migrator->migrate());
        $this->assertSame([], $this->db->fetchAll('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) AS total FROM tournament_lock')['total']);
    }

    public function testSqlRunsBeforeThePhpStepOfTheSameVersion(): void {
        file_put_contents($this->directory . '/001_demo-sqlite.sql', 'CREATE TABLE demo (value INTEGER);');
        file_put_contents($this->directory . '/001_demo-mysql.sql', 'CREATE TABLE demo (value INTEGER);');
        file_put_contents($this->directory . '/001_demo.php', '<?php return static function ($db, string $driver, $clock): void { $db->insertAsDict("demo", ["value" => (int)$clock->now()->format("Y")]); };');
        $this->assertSame(['001_demo'], $this->migrator($this->directory)->migrate());
        $this->assertSame(2026, (int)$this->db->fetchOne('SELECT value FROM demo')['value']);
    }

    public function testSqlMigrationMustExistForEveryDriver(): void {
        file_put_contents($this->directory . '/001_demo-sqlite.sql', 'SELECT 1;');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mysql');
        $this->migrator($this->directory)->migrate();
    }
}
