<?php
namespace App\Controllers;

/**
 * Operation of a single match: schedule, start, goals, result, confirmation, corrections and incidents.
 * Administrators schedule and correct; the assigned referee (or an administrator) runs the match sheet.
 * Every change answers with the refreshed tournament.
 */
class MatchesController extends ApiController
{
    public function scheduleAction() {
        $admin = $this->requireAccess(true);
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->scheduler->schedule($tournament, $match, $this->input(), $actor), $admin);
    }

    public function startAction() {
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->start($match, $actor));
    }

    public function addGoalAction() {
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->addGoal($tournament, $match, $actor, $this->input()), status: 201);
    }

    public function updateGoalAction() {
        $eventId = $this->routeId('eventId');
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->updateGoal($tournament, $match, $actor, $eventId, $this->input()));
    }

    public function deleteGoalAction() {
        $eventId = $this->routeId('eventId');
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->deleteGoal($match, $actor, $eventId));
    }

    public function resultAction() {
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->submitResult($tournament, $match, $actor, $this->input()));
    }

    public function revisionAction() {
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->revise($tournament, $match, $actor, $this->input()));
    }

    public function correctionAction() {
        $admin = $this->requireAccess(true);
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->correct($tournament, $match, $actor, $this->input()), $admin);
    }

    public function confirmAction() {
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchSheet->confirm($tournament, $match, $actor));
    }

    /** League: a match that cannot be played on its date. */
    public function postponeAction() {
        $admin = $this->requireAccess(true);
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->formats->league()->postpone($tournament, $match, $actor, $this->input()), $admin);
    }

    /** League: move an unplayed match to another matchday. */
    public function moveAction() {
        $admin = $this->requireAccess(true);
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->formats->league()->moveToMatchday($tournament, $match, $actor, $this->input()), $admin);
    }

    public function saveIncidentAction() {
        $incidentId = $this->dispatcher->getParam('incidentId') === null ? 0 : $this->routeId('incidentId');
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchIncidents->save($match, $actor, $this->input(), $incidentId), status: $incidentId ? 200 : 201);
    }

    public function deleteIncidentAction() {
        $incidentId = $this->routeId('incidentId');
        return $this->onMatch(fn($tournament, $match, int $actor) => $this->matchIncidents->delete($match, $actor, $incidentId));
    }

    /**
     * @param callable(\App\Models\Tournament, \App\Models\TournamentMatch, int): mixed $operation
     * @param ?array $actor the user when the caller already checked a stricter access (administrator)
     */
    private function onMatch(callable $operation, ?array $actor = null, int $status = 200) {
        $actor ??= $this->requireOperator();
        $tournamentId = $this->routeId('id');
        $matchId = $this->routeId('matchId');
        $this->transactional(function () use ($operation, $tournamentId, $matchId, $actor) {
            $tournament = $this->tournaments->find($tournamentId);
            $operation($tournament, $this->tournaments->match($tournament, $matchId), (int)$actor['id']);
        });
        return $this->json(['record' => $this->tournaments->detail($tournamentId)], $status);
    }
}
