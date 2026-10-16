<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Models\User;
use App\Services\UserService;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestDatabase;

final class UserServiceTest extends IntegrationTestCase
{
    private UserService $users;

    protected function setUp(): void {
        parent::setUp();
        $this->users = $this->di->getShared('users');
    }

    private function newReferee(string $email = 'nuevo.arbitro@futkids.local'): array {
        return $this->users->create(['name' => 'Ana Méndez', 'email' => $email, 'role' => 'referee', 'password' => 'Temporal2026']);
    }

    public function testAdministratorCreatesAccountsThatMustChangeTheirPassword(): void {
        $user = $this->newReferee('  Ana.Mendez@FutKids.Local ');
        $this->assertSame(['ana.mendez@futkids.local', 'referee', 1, 1], [$user['email'], $user['role'], $user['active'], $user['must_change_password']]);
        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertTrue($this->di->getShared('security')->checkHash('Temporal2026', (string)User::findFirst($user['id'])->password_hash));
    }

    public function testAccountDataIsValidated(): void {
        $error = $this->assertRejected(fn() => $this->users->create(['name' => 'Ana <b>', 'email' => 'no-es-correo', 'role' => 'superuser', 'password' => 'corta']), 422);
        $this->assertEqualsCanonicalizing(['name', 'email', 'role', 'password'], array_keys($error->fields));
        $this->assertRejected(fn() => $this->users->create(['name' => 'Ana', 'email' => 'ADMIN@futkids.local', 'role' => 'admin', 'password' => 'Segura2026xx']), 422, 'email');
        $this->assertRejected(fn() => $this->users->create(['name' => 'Ana', 'email' => 'ana@futkids.local', 'role' => 'admin', 'password' => 'soloLetrasLargas']), 422, 'password');
        $this->assertRejected(fn() => $this->users->create(['name' => 'Ana', 'email' => 'ana12345678@x.co', 'role' => 'admin', 'password' => 'ana12345678@x.co']), 422, 'password');
    }

    public function testNobodyDeactivatesOrDemotesThemself(): void {
        $this->assertRejected(fn() => $this->users->setActive(self::ADMIN_ID, 0, self::ADMIN_ID), 409, 'active');
        $admin = User::findFirst(self::ADMIN_ID)->publicData();
        $this->assertRejected(fn() => $this->users->update(self::ADMIN_ID, ['role' => 'referee'] + $admin, self::ADMIN_ID), 409, 'role');
    }

    public function testTheLastActiveAdministratorIsKept(): void {
        $second = $this->users->create(['name' => 'Segundo Admin', 'email' => 'admin2@futkids.local', 'role' => 'admin', 'password' => 'Segura2026xx']);
        $this->users->setActive(self::ADMIN_ID, 0, (int)$second['id']);
        $this->assertRejected(fn() => $this->users->setActive((int)$second['id'], 0, 999), 409, 'active');
        $this->users->setActive(self::ADMIN_ID, 1, (int)$second['id']);
        $this->assertSame(0, $this->users->setActive((int)$second['id'], 0, self::ADMIN_ID)['active']);
    }

    public function testRefereeWithPendingMatchesKeepsTheRole(): void {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $this->schedule((int)$detail['id'], (int)$detail['matches'][0]['id'], '2026-10-20T10:00');
        $error = $this->assertRejected(fn() => $this->users->setActive(self::REFEREE_ID, 0, self::ADMIN_ID), 409, 'active');
        $this->assertStringContainsString('20/10/2026 10:00', $error->getMessage());
        $referee = User::findFirst(self::REFEREE_ID)->publicData();
        $this->assertRejected(fn() => $this->users->update(self::REFEREE_ID, ['role' => 'admin'] + $referee, self::ADMIN_ID), 409, 'role');
    }

    public function testInactiveRefereesCannotBeAssigned(): void {
        $referee = $this->newReferee();
        $this->users->setActive((int)$referee['id'], 0, self::ADMIN_ID);
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $this->assertRejected(fn() => $this->schedule((int)$detail['id'], (int)$detail['matches'][0]['id'], '2026-10-20T10:00', 'Cancha 1', (int)$referee['id']), 422, 'referee_user_id');
        $this->assertNotContains((int)$referee['id'], array_map('intval', array_column($this->state->state()['referees'], 'id')));
    }

    public function testPasswordResetAndOwnChange(): void {
        $this->assertRejected(fn() => $this->users->resetPassword(self::ADMIN_ID, ['password' => 'Nueva2026xx'], self::ADMIN_ID), 409);
        $reset = $this->users->resetPassword(self::REFEREE_ID, ['password' => 'Temporal2027'], self::ADMIN_ID);
        $this->assertSame(1, $reset['must_change_password']);

        $this->assertRejected(fn() => $this->users->changeOwnPassword(self::REFEREE_ID, ['current_password' => TestDatabase::REFEREE_PASSWORD, 'new_password' => 'Otra2027xxxx']), 422, 'current_password');
        $this->assertRejected(fn() => $this->users->changeOwnPassword(self::REFEREE_ID, ['current_password' => 'Temporal2027', 'new_password' => 'Temporal2027']), 422, 'new_password');
        $changed = $this->users->changeOwnPassword(self::REFEREE_ID, ['current_password' => 'Temporal2027', 'new_password' => 'Definitiva2027']);
        $this->assertSame(0, $changed['must_change_password']);
        $this->assertTrue($this->di->getShared('security')->checkHash('Definitiva2027', (string)User::findFirst(self::REFEREE_ID)->password_hash));
    }

    public function testListShowsPendingMatchesWithoutSecrets(): void {
        $list = $this->users->list();
        $this->assertCount(2, $list);
        foreach ($list as $user) {
            $this->assertArrayNotHasKey('password_hash', $user);
            $this->assertArrayHasKey('pending_matches', $user);
        }
    }

    public function testAclKeepsAccountManagementForAdministrators(): void {
        $acl = $this->di->getShared('acl');
        $this->assertTrue($acl->isAllowed('admin', 'users', 'write'));
        $this->assertFalse($acl->isAllowed('referee', 'users', 'read'));
    }
}
