<?php
namespace App\Controllers;

use App\Exceptions\HttpException;
use App\Models\User;

abstract class ApiController extends \Phalcon\Mvc\Controller
{
    protected function token(): string {
        if (!$this->session->has('csrf')) $this->session->set('csrf', $this->security->getToken());
        return $this->session->get('csrf');
    }

    protected function verifyCsrf(): void {
        $provided = $this->request->getHeader('X-CSRF-Token');
        if (!$provided || !hash_equals($this->token(), $provided)) throw new HttpException('La sesión venció. Recargue la página.', 419);
    }

    /**
     * The signed-in user, re-read from the database on every request so that deactivating an account,
     * changing its role or resetting its password takes effect immediately. Null when not signed in.
     */
    protected function currentUser(): ?array {
        $session = $this->session->get('user');
        if (!$session) return null;
        $user = User::findFirst((int)$session['id']);
        if (!$user || !$user->isActive()) {
            $this->endSession();
            return null;
        }
        $fresh = $this->sessionData($user);
        if ($fresh !== $session) $this->session->set('user', $fresh);
        return $fresh;
    }

    protected function sessionData(User $user): array {
        return ['id' => (int)$user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'must_change_password' => (int)$user->must_change_password];
    }

    protected function endSession(): void {
        $this->session->remove('user');
        $this->session->remove('csrf');
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    /** Signed-in user allowed to read; with $write, also allowed to write and holding a valid CSRF token. */
    protected function requireAccess(bool $write = false, string $component = 'tournament'): array {
        $user = $this->currentUser();
        if (!$user) throw new HttpException('Inicie sesión para continuar.', 401);
        if (!$this->acl->isAllowed($user['role'], $component, $write ? 'write' : 'read')) throw new HttpException('Su cuenta no tiene permiso para esta acción.', 403);
        if ($write) {
            $this->verifyCsrf();
            $this->requirePasswordChanged($user);
        }
        return $user;
    }

    /** Any signed-in user sending a valid CSRF token; finer rules (e.g. assigned referee) live in the services. */
    protected function requireOperator(): array {
        $user = $this->requireAccess();
        $this->verifyCsrf();
        $this->requirePasswordChanged($user);
        return $user;
    }

    /** A temporary password (set by an administrator) must be replaced before changing anything. */
    protected function requirePasswordChanged(array $user): void {
        if ($user['must_change_password']) throw new HttpException('Cambie su contraseña temporal antes de continuar.', 403);
    }

    /** Runs a change atomically: one request, one transaction. */
    protected function transactional(callable $work): mixed {
        return $this->transaction->run($work);
    }

    protected function routeId(string $name): int {
        $value = $this->dispatcher->getParam($name);
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) throw new HttpException('Identificador inválido.', 404);
        return (int)$value;
    }

    protected function input(): array {
        $data = $this->request->isJson() ? $this->request->getJsonRawBody(true) : $this->request->getPost();
        if (!is_array($data)) throw new HttpException('Formato de datos inválido.', 400);
        return $data;
    }

    protected function json(mixed $data, int $status = 200) {
        $this->view->disable();
        $this->response->setHeader('Cache-Control', 'no-store');
        return $this->response->setStatusCode($status)->setJsonContent($data, JSON_UNESCAPED_UNICODE);
    }
}
