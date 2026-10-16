<?php
namespace App\Controllers;

use App\Exceptions\HttpException;
use App\Models\User;

class AuthController extends ApiController
{
    private const MAX_FAILURES = 10;
    private const LOCK_SECONDS = 600;

    public function sessionAction() {
        return $this->json(['user' => $this->currentUser(), 'csrf' => $this->token(), 'settings' => [
            'age_min' => $this->settings['age_min'],
            'age_max' => $this->settings['age_max'],
            'age_reference_date' => $this->settings['age_reference_date'],
            'demo_accounts' => $this->settings['environment'] !== 'production',
            'server_today' => $this->clock->today(),
            'server_now' => $this->clock->now()->format('Y-m-d\TH:i'),
            'timezone' => $this->settings['timezone'],
            'validation' => \App\Validation\Rules::all(),
            'positions' => \App\Services\CatalogService::POSITIONS,
            'password_min_length' => \App\Services\UserService::MIN_PASSWORD_LENGTH,
        ]]);
    }

    public function loginAction() {
        $this->verifyCsrf();
        $data = $this->input();
        $key = hash('sha256', $this->request->getClientAddress() ?: 'local');
        $attempt = $this->db->fetchOne('SELECT * FROM login_attempts WHERE fingerprint = ?', \Phalcon\Db\Enum::FETCH_ASSOC, [$key]);
        $recent = $attempt && (int)$attempt['last_failure'] > time() - self::LOCK_SECONDS;
        if ($recent && (int)$attempt['failures'] >= self::MAX_FAILURES) throw new HttpException('Demasiados intentos. Espere 10 minutos.', 429);
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if (strlen($email) > 200 || strlen($password) > 200) throw new HttpException('Credenciales inválidas.', 401);
        $user = User::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
        if ($user) {
            $valid = $this->security->checkHash($password, $user->password_hash) && $user->isActive();
        } else {
            // Same work whether or not the account exists, so response times do not reveal it.
            $this->security->hash($password);
            $valid = false;
        }
        if (!$valid) {
            $this->db->execute('DELETE FROM login_attempts WHERE fingerprint = ?', [$key]);
            $this->db->insertAsDict('login_attempts', ['fingerprint' => $key, 'failures' => $recent ? (int)$attempt['failures'] + 1 : 1, 'last_failure' => time()]);
            // An inactive account gets the same answer as a wrong password.
            throw new HttpException('Correo o contraseña incorrectos.', 401);
        }
        $this->db->execute('DELETE FROM login_attempts WHERE fingerprint = ?', [$key]);
        $user->last_login_at = $this->clock->now()->format('Y-m-d H:i:s');
        $user->saveOrFail('No se pudo registrar el ingreso.');
        session_regenerate_id(true);
        $this->session->set('user', $this->sessionData($user));
        $this->session->remove('csrf');
        return $this->json(['user' => $this->sessionData($user), 'csrf' => $this->token()]);
    }

    public function logoutAction() {
        $this->verifyCsrf();
        $this->endSession();
        return $this->json(['user' => null, 'csrf' => $this->token()]);
    }
}
