<?php
namespace App\Controllers;

/** Account management for administrators. */
class UsersController extends ApiController
{
    public function listAction() {
        $this->requireAccess(false, 'users');
        return $this->json(['items' => $this->users->list()]);
    }

    public function createAction() {
        $this->requireAccess(true, 'users');
        $record = $this->transactional(fn() => $this->users->create($this->input()));
        return $this->json(['record' => $record], 201);
    }

    public function updateAction() {
        $actor = $this->requireAccess(true, 'users');
        $id = $this->routeId('id');
        return $this->json(['record' => $this->transactional(fn() => $this->users->update($id, $this->input(), (int)$actor['id']))]);
    }

    public function statusAction() {
        $actor = $this->requireAccess(true, 'users');
        $id = $this->routeId('id');
        return $this->json(['record' => $this->transactional(fn() => $this->users->setActive($id, $this->input()['active'] ?? null, (int)$actor['id']))]);
    }

    public function passwordAction() {
        $actor = $this->requireAccess(true, 'users');
        $id = $this->routeId('id');
        return $this->json(['record' => $this->transactional(fn() => $this->users->resetPassword($id, $this->input(), (int)$actor['id']))]);
    }
}
