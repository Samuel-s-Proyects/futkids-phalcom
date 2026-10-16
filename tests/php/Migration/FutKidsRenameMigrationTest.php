<?php
declare(strict_types=1);
namespace Tests\Migration;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;
use Tests\Support\TestDatabase;

/** Accounts created under the Cantera name move to the @futkids.local domain. */
final class FutKidsRenameMigrationTest extends TestCase
{
    private AdapterInterface $db;

    protected function setUp(): void {
        $di = TestApplication::boot(TestDatabase::emptyFile());
        $this->db = $di->getShared('db');
        $di->getShared('migrator')->migrate(until: '012_tournament_matchdays');
        foreach ([[1, 'admin@cantera.local', 'admin'], [2, 'arbitro@cantera.local', 'referee']] as [$id, $email, $role]) {
            $this->db->insertAsDict('users', ['id' => $id, 'name' => 'Cuenta', 'email' => $email, 'role' => $role, 'password_hash' => 'x']);
        }
        $this->db->insertAsDict('users', ['id' => 3, 'name' => 'Ana', 'email' => 'ana@cantera.local', 'role' => 'referee', 'password_hash' => 'x']);
        $this->db->insertAsDict('users', ['id' => 4, 'name' => 'Ana', 'email' => 'ana@futkids.local', 'role' => 'referee', 'password_hash' => 'x']);
        $this->db->insertAsDict('users', ['id' => 5, 'name' => 'Luis', 'email' => 'luis@correo.com', 'role' => 'referee', 'password_hash' => 'x']);
        $di->getShared('migrator')->migrate();
    }

    protected function tearDown(): void {
        $this->db->close();
    }

    public function testOldDomainMovesUnlessTheNewAddressIsTaken(): void {
        $emails = array_column($this->db->fetchAll('SELECT email FROM users ORDER BY id', Enum::FETCH_ASSOC), 'email');
        $this->assertSame(['admin@futkids.local', 'arbitro@futkids.local', 'ana@cantera.local', 'ana@futkids.local', 'luis@correo.com'], $emails);
    }
}
