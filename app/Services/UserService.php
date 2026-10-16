<?php
declare(strict_types=1);
namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Clock;
use App\Support\Dates;
use App\Validation\Validator;
use Phalcon\Encryption\Security;

/**
 * Administrator and referee accounts. Accounts are deactivated, never deleted (matches and audits refer
 * to them). Guards: nobody deactivates or demotes themself, the last active administrator stays, and a
 * referee with matches still to direct keeps the role until they are reassigned.
 */
final class UserService
{
    public const MIN_PASSWORD_LENGTH = 10;
    private const PENDING_STATES = [TournamentMatch::SCHEDULED, TournamentMatch::IN_PROGRESS, TournamentMatch::AWAITING_CONFIRMATION];

    public function __construct(private Security $security, private Clock $clock) {}

    /** @return list<array> */
    public function list(): array {
        return array_map(fn(User $user) => $user->publicData() + ['pending_matches' => $this->pendingMatches((int)$user->id)],
            iterator_to_array(User::find(['order' => 'active DESC, name ASC'])));
    }

    public function find(int $id): User {
        return User::findOrFail($id, 'El usuario no existe.');
    }

    public function create(array $input): array {
        $v = new Validator($input);
        $data = $this->profile($v, 0);
        $password = $this->password($v, 'password', $input['password'] ?? null, $data['email'] ?? '');
        $v->assertValid();
        $user = new User();
        $user->assign($data + [
            'password_hash' => $this->security->hash($password),
            'must_change_password' => 1,
            'password_changed_at' => $this->now(),
            'active' => 1,
        ]);
        $user->saveOrFail('No se pudo crear el usuario.');
        return $user->publicData();
    }

    public function update(int $id, array $input, int $actorId): array {
        $user = $this->find($id);
        $v = new Validator($input);
        $data = $this->profile($v, $id);
        $v->assertValid();
        if ($data['role'] !== $user->role) $this->assertCanLoseRole($user, $actorId, 'role');
        $user->assign($data);
        $user->saveOrFail('No se pudo actualizar el usuario.');
        return $user->publicData();
    }

    public function setActive(int $id, mixed $active, int $actorId): array {
        $v = new Validator(['active' => $active]);
        $flag = $v->flag('active', 'Indique si la cuenta queda activa o inactiva.');
        $v->assertValid();
        $user = $this->find($id);
        if (!$flag && $user->isActive()) $this->assertCanLoseRole($user, $actorId, 'active');
        $user->active = $flag;
        $user->saveOrFail('No se pudo cambiar el estado del usuario.');
        return $user->publicData();
    }

    /** An administrator sets a temporary password; the person must change it when signing in. */
    public function resetPassword(int $id, array $input, int $actorId): array {
        if ($id === $actorId) throw new HttpException('Para cambiar su propia contraseña use «Mi contraseña».', 409);
        $user = $this->find($id);
        $v = new Validator($input);
        $password = $this->password($v, 'password', $input['password'] ?? null, (string)$user->email);
        $v->assertValid();
        $user->assign(['password_hash' => $this->security->hash($password), 'must_change_password' => 1, 'password_changed_at' => $this->now()]);
        $user->saveOrFail('No se pudo restablecer la contraseña.');
        return $user->publicData();
    }

    public function changeOwnPassword(int $userId, array $input): array {
        $user = $this->find($userId);
        $v = new Validator($input);
        $current = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        if ($current === '') $v->fail('current_password', Validator::REQUIRED);
        elseif (!$this->security->checkHash($current, (string)$user->password_hash)) $v->fail('current_password', 'La contraseña actual no es correcta.');
        $password = $this->password($v, 'new_password', $input['new_password'] ?? null, (string)$user->email);
        if ($password !== null && $current !== '' && $password === $current) $v->fail('new_password', 'La nueva contraseña debe ser distinta de la actual.');
        $v->assertValid();
        $user->assign(['password_hash' => $this->security->hash($password), 'must_change_password' => 0, 'password_changed_at' => $this->now()]);
        $user->saveOrFail('No se pudo cambiar la contraseña.');
        return $user->publicData();
    }

    /** @return array{name: ?string, email: ?string, role: ?string} */
    private function profile(Validator $v, int $id): array {
        $data = [
            'name' => $v->text('name', 100, 'person_name'),
            'email' => $v->email('email'),
            'role' => $v->choice('role', User::ROLES, 'Elija administrador o árbitro.'),
        ];
        if ($data['email'] && User::count(['LOWER(email) = :email: AND id != :id:', 'bind' => ['email' => $data['email'], 'id' => $id]])) {
            $v->fail('email', 'Ya existe una cuenta con ese correo.');
        }
        return $data;
    }

    /** Passwords are checked verbatim: no trimming or normalisation. */
    private function password(Validator $v, string $field, mixed $value, string $email): ?string {
        $value = is_string($value) ? $value : '';
        if ($value === '') return $v->fail($field, Validator::REQUIRED);
        if (mb_strlen($value) < self::MIN_PASSWORD_LENGTH || mb_strlen($value) > 200) {
            return $v->fail($field, 'Use entre ' . self::MIN_PASSWORD_LENGTH . ' y 200 caracteres.');
        }
        if (!preg_match('/\p{L}/u', $value) || !preg_match('/[0-9]/', $value)) return $v->fail($field, 'Combine letras y números.');
        if ($email !== '' && mb_strtolower($value) === mb_strtolower($email)) return $v->fail($field, 'La contraseña no puede ser igual al correo.');
        return $value;
    }

    private function assertCanLoseRole(User $user, int $actorId, string $field): void {
        $leaving = $field === 'active' ? 'desactivar' : 'cambiar el rol de';
        if ((int)$user->id === $actorId) throw new HttpException("No puede {$leaving} su propia cuenta.", 409, [$field => 'Pida a otro administrador que lo haga.']);
        if ($user->role === User::ADMIN && $user->isActive() && User::count(["role = 'admin' AND active = 1"]) <= 1) {
            throw new HttpException('Debe quedar al menos un administrador activo.', 409, [$field => 'Cree o active otro administrador primero.']);
        }
        $pending = $this->pendingMatches((int)$user->id);
        if ($user->role === User::REFEREE && $pending) {
            $list = implode('; ', array_map(fn(array $m) => Dates::displayDateTime($m['scheduled_at']), $pending));
            throw new HttpException("El árbitro tiene partidos pendientes ({$list}). Asigne otro árbitro antes.", 409, [$field => 'Reasigne sus partidos pendientes primero.']);
        }
    }

    /** @return list<array{id: int, tournament_id: int, scheduled_at: string}> */
    private function pendingMatches(int $refereeId): array {
        return TournamentMatch::find([
            'conditions' => 'referee_user_id = :id: AND workflow_status IN ({states:array})',
            'bind' => ['id' => $refereeId, 'states' => self::PENDING_STATES],
            'columns' => 'id, tournament_id, scheduled_at',
            'order' => 'scheduled_at',
        ])->toArray();
    }

    private function now(): string {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
