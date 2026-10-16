<?php
namespace App\Controllers;

use App\Exceptions\HttpException;

/** The signed-in person's own account. */
class AccountController extends ApiController
{
    public function passwordAction() {
        $user = $this->currentUser();
        if (!$user) throw new HttpException('Inicie sesión para continuar.', 401);
        $this->verifyCsrf();
        $record = $this->transactional(fn() => $this->users->changeOwnPassword($user['id'], $this->input()));
        session_regenerate_id(true);
        $this->session->set('user', $this->sessionData($this->users->find($user['id'])));
        return $this->json(['user' => $this->session->get('user'), 'record' => $record]);
    }
}
