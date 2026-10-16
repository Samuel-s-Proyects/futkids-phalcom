<?php
namespace App\Controllers;
class TournamentsController extends ApiController
{
    private function positiveInteger(mixed $value, string $field): int {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) throw new \App\Exceptions\HttpException('Dato numérico inválido.', 422, [$field => 'Ingrese un entero positivo.']);
        return (int)$value;
    }
    public function listAction() {
        $this->requireAccess();
        return $this->json(['items' => $this->tournament->tournaments()]);
    }
    public function saveAction() {
        $this->requireAccess(true);
        $id = (int)$this->dispatcher->getParam('id', 'int', 0);
        return $this->json(['record' => $this->tournament->saveTournament($this->input(), $id)], $id ? 200 : 201);
    }
    public function detailAction() {
        $this->requireAccess();
        return $this->json($this->tournament->tournament((int)$this->dispatcher->getParam('id', 'int')));
    }
    public function openRegistrationAction() {
        $this->requireAccess(true);
        return $this->json(['record' => $this->tournament->openRegistration((int)$this->dispatcher->getParam('id', 'int'))]);
    }
    public function enrollAction() {
        $this->requireAccess(true);
        $data = $this->input();
        $teamId = $this->positiveInteger($data['team_id'] ?? null, 'team_id');
        return $this->json(['record' => $this->tournament->enrollTeam((int)$this->dispatcher->getParam('id', 'int'), $teamId)], 201);
    }
    public function removeParticipantAction() {
        $this->requireAccess(true);
        $this->tournament->removeParticipant((int)$this->dispatcher->getParam('id', 'int'), (int)$this->dispatcher->getParam('teamId', 'int'));
        return $this->json(['message' => 'Inscripción retirada.']);
    }
    public function orderParticipantsAction() {
        $this->requireAccess(true);
        $data = $this->input();
        if (!isset($data['team_ids']) || !is_array($data['team_ids'])) throw new \App\Exceptions\HttpException('Envíe la lista ordenada de equipos.', 422, ['team_ids' => 'Lista obligatoria.']);
        return $this->json(['record' => $this->tournament->orderParticipants((int)$this->dispatcher->getParam('id', 'int'), $data['team_ids'])]);
    }
    public function closeRegistrationAction() {
        $this->requireAccess(true);
        $data = $this->input();
        if (!isset($data['draw_method']) || !is_string($data['draw_method'])) throw new \App\Exceptions\HttpException('Seleccione cómo ordenar los equipos.', 422, ['draw_method' => 'Seleccione sorteo aleatorio u orden manual.']);
        return $this->json(['record' => $this->tournament->closeRegistration((int)$this->dispatcher->getParam('id', 'int'), $data['draw_method'])]);
    }
    public function generateBracketAction() {
        $this->requireAccess(true);
        return $this->json(['record' => $this->tournament->generateBracket((int)$this->dispatcher->getParam('id', 'int'))]);
    }
    public function scheduleMatchAction() {
        $actor = $this->requireAccess(true);
        $data = $this->input();
        if (!isset($data['scheduled_at']) || !is_string($data['scheduled_at'])) throw new \App\Exceptions\HttpException('Seleccione la fecha y hora del partido.', 422, ['scheduled_at' => 'Campo obligatorio.']);
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        return $this->json(['record' => $this->tournament->scheduleMatch((int)$this->dispatcher->getParam('id', 'int'), $matchId, $data['scheduled_at'], $data, (int)$actor['id'])]);
    }
    public function startMatchAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        return $this->json(['record' => $this->tournament->startMatch((int)$this->dispatcher->getParam('id', 'int'), $matchId, (int)$actor['id'])]);
    }
    public function recordMatchGoalAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        return $this->json(['record' => $this->tournament->recordMatchGoal((int)$this->dispatcher->getParam('id', 'int'), $matchId, (int)$actor['id'], $this->input())], 201);
    }
    public function recordMatchResultAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        $data = $this->input();
        $data['actor_user_id'] = (int)$actor['id'];
        return $this->json(['record' => $this->tournament->recordMatchResult((int)$this->dispatcher->getParam('id', 'int'), $matchId, $data)]);
    }
    public function reviseMatchResultAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        $data = $this->input();
        $data['actor_user_id'] = (int)$actor['id'];
        return $this->json(['record' => $this->tournament->recordMatchResult((int)$this->dispatcher->getParam('id', 'int'), $matchId, $data, false, true)]);
    }
    public function confirmMatchResultAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        return $this->json(['record' => $this->tournament->confirmMatchResult((int)$this->dispatcher->getParam('id', 'int'), $matchId, (int)$actor['id'])]);
    }
    public function correctMatchResultAction() {
        $actor = $this->requireAccess(true);
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        $data = $this->input();
        $data['actor_user_id'] = (int)$actor['id'];
        return $this->json(['record' => $this->tournament->recordMatchResult((int)$this->dispatcher->getParam('id', 'int'), $matchId, $data, true)]);
    }
    public function saveMatchIncidentAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        $incidentId = (int)$this->dispatcher->getParam('incidentId', 'int', 0);
        if ($incidentId) $incidentId = $this->positiveInteger($incidentId, 'incidentId');
        return $this->json(['record' => $this->tournament->saveMatchIncident((int)$this->dispatcher->getParam('id', 'int'), $matchId, (int)$actor['id'], $this->input(), $incidentId)], $incidentId ? 200 : 201);
    }
    public function deleteMatchIncidentAction() {
        $actor = $this->requireAccess();
        $this->verifyCsrf();
        $matchId = $this->positiveInteger($this->dispatcher->getParam('matchId', 'int'), 'matchId');
        $incidentId = $this->positiveInteger($this->dispatcher->getParam('incidentId', 'int'), 'incidentId');
        $this->tournament->deleteMatchIncident((int)$this->dispatcher->getParam('id', 'int'), $matchId, (int)$actor['id'], $incidentId);
        return $this->json(['message' => 'Incidencia eliminada.']);
    }
}
