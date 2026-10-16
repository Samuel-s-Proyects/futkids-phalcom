<?php
namespace App\Controllers;

use App\Exceptions\HttpException;

/** Tournament configuration and registration. Every change answers with the refreshed tournament. */
class TournamentsController extends ApiController
{
    public function listAction() {
        $this->requireAccess();
        return $this->json(['items' => $this->tournaments->list()]);
    }

    public function detailAction() {
        $this->requireAccess();
        return $this->json($this->tournaments->detail($this->routeId('id')));
    }

    public function saveAction() {
        $this->requireAccess(true);
        $id = $this->dispatcher->getParam('id') === null ? 0 : $this->routeId('id');
        $record = $this->transactional(fn() => $this->tournaments->save($this->input(), $id));
        return $this->json(['record' => $this->tournaments->detail((int)$record['id'])], $id ? 200 : 201);
    }

    public function openRegistrationAction() {
        return $this->change(fn(int $id) => $this->tournaments->openRegistration($id));
    }

    public function enrollAction() {
        $teamId = $this->input()['team_id'] ?? null;
        if (!is_scalar($teamId) || !ctype_digit((string)$teamId)) throw new HttpException('Seleccione un equipo.', 422, ['team_id' => 'Seleccione un equipo de la lista.']);
        return $this->change(fn(int $id) => $this->tournaments->enroll($id, (int)$teamId), 201);
    }

    public function removeParticipantAction() {
        $teamId = $this->routeId('teamId');
        return $this->change(fn(int $id) => $this->tournaments->removeParticipant($id, $teamId));
    }

    public function orderParticipantsAction() {
        $teamIds = $this->input()['team_ids'] ?? null;
        if (!is_array($teamIds) || !array_is_list($teamIds)) throw new HttpException('Envíe la lista ordenada de equipos.', 422, ['team_ids' => 'Lista obligatoria.']);
        return $this->change(fn(int $id) => $this->tournaments->orderParticipants($id, $teamIds));
    }

    public function closeRegistrationAction() {
        $method = $this->input()['draw_method'] ?? null;
        return $this->change(fn(int $id) => $this->tournaments->closeRegistration($id, $method));
    }

    public function generateBracketAction() {
        return $this->change(fn(int $id) => $this->tournaments->generateBracket($id));
    }

    /** Proposed league calendar for the given first date and interval (nothing is saved). */
    public function fixturePreviewAction() {
        $this->requireAccess(true);
        return $this->json(['preview' => $this->tournaments->previewFixture($this->routeId('id'), $this->input())]);
    }

    public function generateFixtureAction() {
        $input = $this->input();
        return $this->change(fn(int $id) => $this->tournaments->generateFixture($id, $input), 201);
    }

    public function standingsAction() {
        $this->requireAccess();
        return $this->json(['rows' => $this->tournaments->standings($this->routeId('id'))]);
    }

    public function addMatchdayAction() {
        $input = $this->input();
        return $this->change(fn(int $id) => $this->tournaments->addExtraordinaryMatchday($id, $input), 201);
    }

    public function updateMatchdayAction() {
        $input = $this->input();
        $matchdayId = $this->routeId('matchdayId');
        return $this->change(fn(int $id) => $this->tournaments->updateMatchday($id, $matchdayId, $input));
    }

    public function deleteMatchdayAction() {
        $matchdayId = $this->routeId('matchdayId');
        return $this->change(fn(int $id) => $this->tournaments->deleteMatchday($id, $matchdayId));
    }

    public function extendEndDateAction() {
        $input = $this->input();
        return $this->change(fn(int $id) => $this->tournaments->extendEndDate($id, $input));
    }

    public function scorersAction() {
        $this->requireAccess();
        $id = $this->routeId('id');
        $this->tournaments->find($id);
        return $this->json(['rows' => $this->tournaments->scorers($id)]);
    }

    /** Administrator change on the tournament in the route, answered with the refreshed tournament. */
    private function change(callable $operation, int $status = 200) {
        $this->requireAccess(true);
        $id = $this->routeId('id');
        $this->transactional(fn() => $operation($id));
        return $this->json(['record' => $this->tournaments->detail($id)], $status);
    }
}
