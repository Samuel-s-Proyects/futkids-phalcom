<?php
namespace App\Controllers;

use App\Services\PhotoStorage;

class RecordsController extends ApiController
{
    public function stateAction() {
        $this->requireAccess();
        return $this->json($this->state->state());
    }

    public function listAction() {
        $this->requireAccess();
        $entity = $this->dispatcher->getParam('entity');
        $rows = $this->state->state()[$entity];
        $search = mb_strtolower(trim((string)$this->request->getQuery('q')));
        $team = (int)$this->request->getQuery('team_id', 'int');
        $rows = array_values(array_filter($rows, function ($row) use ($search, $team) {
            if ($team && isset($row['team_id']) && (int)$row['team_id'] !== $team) return false;
            return !$search || str_contains(mb_strtolower(implode(' ', $row)), $search);
        }));
        $paginator = new \Phalcon\Paginator\Adapter\NativeArray(['data' => $rows, 'limit' => 10, 'page' => max(1, (int)$this->request->getQuery('page', 'int', 1))]);
        $page = $paginator->paginate();
        return $this->json(['items' => $page->getItems(), 'total' => $page->getTotalItems(), 'pages' => $page->getLast(), 'page' => $page->getCurrent()]);
    }

    public function saveAction() {
        $this->requireAccess(true);
        $entity = $this->dispatcher->getParam('entity');
        $id = $this->dispatcher->getParam('id') === null ? 0 : $this->routeId('id');
        $data = $this->input();
        $photo = $entity === 'players' ? PhotoStorage::store($this->request) : null;
        $removePhoto = $entity === 'players' && $id > 0 && !$photo && (string)($data['remove_photo'] ?? '') === '1';
        try {
            $row = $this->transactional(fn() => $this->catalog->save($entity, $data, $id, $photo, $removePhoto));
        } catch (\Throwable $e) {
            if ($photo) PhotoStorage::remove($photo);
            throw $e;
        }
        return $this->json(['record' => $row, 'message' => 'Registro guardado.'], $id ? 200 : 201);
    }

    public function deleteAction() {
        $this->requireAccess(true);
        $entity = $this->dispatcher->getParam('entity');
        $id = $this->routeId('id');
        $this->transactional(fn() => $this->catalog->delete($entity, $id));
        return $this->json(['message' => 'Registro eliminado.']);
    }
}
