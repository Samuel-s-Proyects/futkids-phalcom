<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Exceptions\HttpException;
use App\Models\Incident;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchGoal;
use App\Models\TournamentMatchGoalEvent;
use App\Models\TournamentMatchPenalty;
use App\Models\TournamentMatchPeriodScore;
use App\Services\Format\Formats;
use App\Services\SuspensionService;
use App\Support\Clock;
use App\Validation\Validator;

/**
 * The match sheet: starting a match, recording goals, submitting, confirming and correcting the result.
 *
 * Goal events are the only input for the score. Period scores, per-player totals and the final score on
 * the match are projections rebuilt from them, so the score can never disagree with the goals.
 */
final class MatchSheetService
{
    public function __construct(
        private Clock $clock,
        private Formats $formats,
        private MatchAccess $access,
        private MatchAudit $audit,
        private SuspensionService $suspensions,
    ) {}

    public function start(TournamentMatch $match, int $actorId): void {
        $this->access->operator($match, $actorId);
        if ($match->workflow_status !== TournamentMatch::SCHEDULED || !$match->scheduled_at || !$match->referee_user_id) {
            throw new HttpException('Solo se puede iniciar un partido programado con árbitro asignado.', 409);
        }
        $match->workflow_status = TournamentMatch::IN_PROGRESS;
        $match->started_at = $this->clock->now()->format('Y-m-d H:i:s');
        $match->saveOrFail('No se pudo iniciar el partido.');
        $this->audit->record($match, $actorId, 'started', ['workflow_status' => TournamentMatch::IN_PROGRESS]);
    }

    /** A single goal entered live while the match is being played. */
    public function addGoal(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): array {
        $this->assertLive($match, $actorId);
        $event = $this->normalizeEvent($tournament, $match, $input, 'goal');
        $record = $this->insertEvent($match, $event, $actorId);
        $this->audit->record($match, $actorId, 'goal_recorded', $record->toArray());
        return $record->toArray();
    }

    public function updateGoal(Tournament $tournament, TournamentMatch $match, int $actorId, int $eventId, array $input): array {
        $this->assertLive($match, $actorId);
        $record = $this->event($match, $eventId);
        $before = $record->toArray();
        $record->assign($this->normalizeEvent($tournament, $match, $input, 'goal'));
        $record->saveOrFail('No se pudo actualizar el gol.');
        $this->audit->record($match, $actorId, 'goal_updated', ['before' => $before, 'after' => $record->toArray()]);
        return $record->toArray();
    }

    public function deleteGoal(TournamentMatch $match, int $actorId, int $eventId): void {
        $this->assertLive($match, $actorId);
        $record = $this->event($match, $eventId);
        $snapshot = $record->toArray();
        $record->deleteOrFail('No se pudo eliminar el gol.');
        $this->audit->record($match, $actorId, 'goal_deleted', $snapshot);
    }

    /**
     * Closes the match sheet. Input: goal_events (the complete list, replacing what was entered live)
     * and, when a knockout match is still level after extra time, first_kicker_team_id and penalty_kicks.
     */
    public function submitResult(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): void {
        $this->assertLive($match, $actorId);
        $format = $this->formats->for($tournament);
        $events = $this->normalizeEventList($tournament, $match, $input['goal_events'] ?? null);
        $home = (int)$match->home_team_id;
        $away = (int)$match->away_team_id;

        $regulation = Scoreboard::tally(array_filter($events, fn($e) => $e['period_number'] === 0), $home, $away)['regulation'];
        $regulationTied = $regulation['home'] === $regulation['away'];
        $extraPeriods = $format->extraTimePeriods($tournament);
        $playedExtraTime = !$format->allowsDraws() && $regulationTied && $extraPeriods > 0;
        if (!$playedExtraTime && array_filter($events, fn($e) => $e['period_number'] > 0)) {
            throw new HttpException('Solo hay tiempo extra cuando el tiempo reglamentario termina empatado.', 422, ['goal_events' => 'Mueva esos goles al tiempo reglamentario.']);
        }
        $score = Scoreboard::tally($events, $home, $away, $playedExtraTime ? $extraPeriods : 0);

        $winner = $score['home'] === $score['away'] ? null : ($score['home'] > $score['away'] ? $home : $away);
        $decidedBy = $playedExtraTime ? 'extra_time' : 'regulation';
        $shootout = null;
        $kicks = $input['penalty_kicks'] ?? [];
        if ($winner === null && !$format->allowsDraws()) {
            $shootout = PenaltyShootout::evaluate(
                $this->normalizeKicks($kicks),
                $this->penaltyFirstKicker($input),
                $home,
                $away,
                (int)$tournament->penalty_attempts_per_team,
                (bool)(int)$tournament->penalty_sudden_death,
            );
            $winner = $shootout['winner_team_id'];
            $decidedBy = 'penalties';
        } elseif ($kicks) {
            throw new HttpException('Solo hay penales cuando el partido de eliminación termina empatado.', 422, ['penalty_kicks' => 'Quite la tanda de penales.']);
        }

        $this->clearProjections($match, keepEvents: false);
        foreach ($events as $event) $this->insertEvent($match, $event, $actorId);
        foreach ($score['periods'] as $number => $period) {
            $row = new TournamentMatchPeriodScore();
            $row->assign(['tournament_match_id' => (int)$match->id, 'period_number' => $number] + $period);
            $row->saveOrFail('No se pudo guardar el marcador por período.');
        }
        foreach (Scoreboard::scorers($events) as $scorer) {
            $row = new TournamentMatchGoal();
            $row->assign(['tournament_match_id' => (int)$match->id] + $scorer);
            $row->saveOrFail('No se pudo guardar el goleador del partido.');
        }
        foreach ($shootout ? $this->normalizeKicks($kicks) : [] as $index => $kick) {
            $row = new TournamentMatchPenalty();
            $row->assign(['tournament_match_id' => (int)$match->id, 'kick_number' => $index + 1] + $kick);
            $row->saveOrFail('No se pudo guardar la tanda de penales.');
        }
        $match->assign([
            'home_score' => $score['home'],
            'away_score' => $score['away'],
            'decided_by' => $decidedBy,
            'home_penalties' => $shootout['home'] ?? null,
            'away_penalties' => $shootout['away'] ?? null,
            'first_kicker_team_id' => $shootout ? $this->penaltyFirstKicker($input) : null,
            'winner_team_id' => $winner,
            'status' => 'completed',
            'workflow_status' => TournamentMatch::AWAITING_CONFIRMATION,
            'result_revision' => (int)$match->result_revision + 1,
        ]);
        $match->saveOrFail('No se pudo guardar el resultado del partido.');
        $this->audit->record($match, $actorId, 'result_submitted', ['score' => $score, 'decided_by' => $decidedBy, 'winner_team_id' => $winner, 'goal_events' => $events, 'penalty_kicks' => $kicks]);
    }

    /** Edits a result still awaiting confirmation (operator) and resubmits it. */
    public function revise(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): void {
        $this->access->operator($match, $actorId);
        if ($match->workflow_status !== TournamentMatch::AWAITING_CONFIRMATION) throw new HttpException('Solo se puede editar un resultado pendiente de confirmación.', 409);
        $this->reopen($match, $actorId, 'result_revised');
        $this->submitResult($tournament, $match, $actorId, $input);
    }

    /** Exceptional correction of a confirmed result (administrators only), undoing its consequences first. */
    public function correct(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): void {
        $this->access->admin($match, $actorId, 'Solo un administrador puede corregir un resultado confirmado.');
        if ($match->workflow_status !== TournamentMatch::CONFIRMED) throw new HttpException('Solo se puede corregir un resultado confirmado.', 409);
        $format = $this->formats->for($tournament);
        $format->assertCorrectable($tournament, $match);
        $format->onResultReopened($tournament, $match);
        $match->confirmed_at = null;
        $match->confirmed_by_user_id = null;
        $this->reopen($match, $actorId, 'exceptional_correction');
        $this->submitResult($tournament, $match, $actorId, $input);
    }

    public function confirm(Tournament $tournament, TournamentMatch $match, int $actorId): void {
        $this->access->operator($match, $actorId);
        $format = $this->formats->for($tournament);
        if ($match->workflow_status !== TournamentMatch::AWAITING_CONFIRMATION || (!$match->winner_team_id && !$format->allowsDraws())) {
            throw new HttpException('El resultado no está pendiente de confirmación.', 409);
        }
        $match->workflow_status = TournamentMatch::CONFIRMED;
        $match->confirmed_at = $this->clock->now()->format('Y-m-d H:i:s');
        $match->confirmed_by_user_id = $actorId;
        $match->saveOrFail('No se pudo confirmar el resultado.');
        $this->audit->record($match, $actorId, 'confirmed', ['winner_team_id' => $match->winner_team_id, 'home_score' => $match->home_score, 'away_score' => $match->away_score]);
        $format->onResultConfirmed($tournament, $match);
    }

    private function reopen(TournamentMatch $match, int $actorId, string $action): void {
        $snapshot = ['match' => $match->toArray()] + $this->clearProjections($match, keepEvents: true);
        $match->assign([
            'home_score' => null, 'away_score' => null, 'decided_by' => null, 'home_penalties' => null, 'away_penalties' => null,
            'winner_team_id' => null, 'first_kicker_team_id' => null,
            'status' => 'pending', 'workflow_status' => TournamentMatch::IN_PROGRESS,
        ]);
        $match->saveOrFail('No se pudo reabrir el resultado.');
        $this->audit->record($match, $actorId, $action, $snapshot);
    }

    private function assertLive(TournamentMatch $match, int $actorId): void {
        $this->access->operator($match, $actorId);
        if ($match->workflow_status !== TournamentMatch::IN_PROGRESS || $match->status !== 'pending' || !$match->home_team_id || !$match->away_team_id) {
            throw new HttpException('El partido debe estar en juego para registrar goles o el resultado.', 409);
        }
    }

    /** @return array<string, list<array>> what was removed, for the audit trail */
    private function clearProjections(TournamentMatch $match, bool $keepEvents): array {
        $tables = ['period_scores' => TournamentMatchPeriodScore::class, 'player_goals' => TournamentMatchGoal::class, 'penalty_kicks' => TournamentMatchPenalty::class];
        if (!$keepEvents) $tables['goal_events'] = TournamentMatchGoalEvent::class;
        $snapshot = [];
        foreach ($tables as $key => $class) {
            $rows = $class::find(['conditions' => 'tournament_match_id = :match:', 'bind' => ['match' => (int)$match->id]]);
            $snapshot[$key] = $rows->toArray();
            foreach ($rows as $row) $row->deleteOrFail('No se pudo preparar la actualización del resultado.');
        }
        return $snapshot;
    }

    /** @return list<array> normalized events sorted by period and minute */
    private function normalizeEventList(Tournament $tournament, TournamentMatch $match, mixed $events): array {
        if ($events === null) $events = [];
        if (!is_array($events) || !array_is_list($events)) throw new HttpException('Lista de goles inválida.', 422, ['goal_events' => 'Envíe la lista de goles del partido.']);
        $normalized = [];
        foreach ($events as $index => $event) {
            if (!is_array($event)) throw new HttpException('Gol inválido.', 422, ['goal_events' => 'Revise el gol ' . ($index + 1) . '.']);
            $normalized[] = $this->normalizeEvent($tournament, $match, $event, 'goal_events');
        }
        usort($normalized, fn($a, $b) => [$a['period_number'], $a['minute']] <=> [$b['period_number'], $b['minute']]);
        return $normalized;
    }

    /**
     * Validates one goal: the player belongs to the match, is not suspended, had not been sent off
     * before that minute, and the period exists for this tournament. Errors are reported on $errorField
     * (a list) or on the individual input fields.
     * @return array{player_id: int, team_id: int, credited_team_id: int, minute: int, period_number: int, own_goal: int}
     */
    private function normalizeEvent(Tournament $tournament, TournamentMatch $match, array $input, string $errorField): array {
        $listField = $errorField === 'goal' ? null : $errorField;
        $v = new Validator($input);
        $playerId = $v->integer('player_id', 1, PHP_INT_MAX);
        $minute = $v->integer('minute', 0, 150);
        $period = $v->integer('period_number', 0, $this->formats->for($tournament)->extraTimePeriods($tournament), required: false) ?? 0;
        $ownGoal = $v->integer('own_goal', 0, 1, required: false) ?? 0;
        if ($listField && $v->errors()) throw new HttpException('Revise los goles: cada uno necesita jugador y minuto (0 a 150).', 422, [$listField => current($v->errors())]);
        $v->assertValid();

        $player = Player::findFirst($playerId);
        $field = $listField ?? 'player_id';
        if (!$player || !$match->involves((int)$player->team_id)) {
            throw new HttpException('El jugador no pertenece a uno de los equipos del partido.', 422, [$field => 'Seleccione un jugador de los equipos que juegan.']);
        }
        if ($this->suspensions->isSuspended($playerId, $match->matchDate(), (int)$match->id)) {
            throw new HttpException('Un jugador suspendido no puede figurar en los goles del partido.', 409, [$field => "{$player->first_name} {$player->last_name} está suspendido para esta fecha."]);
        }
        $redCard = Incident::findFirst([
            'conditions' => "tournament_match_id = :match: AND player_id = :player: AND card_type = 'red'",
            'bind' => ['match' => (int)$match->id, 'player' => $playerId],
        ]);
        if ($redCard && $minute >= (int)$redCard->minute) {
            throw new HttpException('Un jugador expulsado no puede anotar después de la tarjeta roja.', 409, [$listField ?? 'minute' => "Fue expulsado en el minuto {$redCard->minute}."]);
        }
        $team = (int)$player->team_id;
        return [
            'player_id' => $playerId,
            'team_id' => $team,
            'credited_team_id' => $ownGoal ? $match->opponentOf($team) : $team,
            'minute' => $minute,
            'period_number' => $period,
            'own_goal' => $ownGoal,
        ];
    }

    private function insertEvent(TournamentMatch $match, array $event, int $actorId): TournamentMatchGoalEvent {
        $record = new TournamentMatchGoalEvent();
        $record->assign($event + ['tournament_match_id' => (int)$match->id, 'created_by_user_id' => $actorId]);
        $record->saveOrFail('No se pudo guardar el gol.');
        return $record;
    }

    private function event(TournamentMatch $match, int $eventId): TournamentMatchGoalEvent {
        $record = TournamentMatchGoalEvent::findFirst(['conditions' => 'id = :id: AND tournament_match_id = :match:', 'bind' => ['id' => $eventId, 'match' => (int)$match->id]]);
        if (!$record) throw new HttpException('El gol no existe en este partido.', 404);
        return $record;
    }

    private function penaltyFirstKicker(array $input): int {
        $v = new Validator($input);
        $first = $v->integer('first_kicker_team_id', 1, PHP_INT_MAX);
        $v->assertValid('Indique qué equipo patea primero en la tanda.');
        return $first;
    }

    /** @return list<array{team_id: int, scored: int}> */
    private function normalizeKicks(mixed $kicks): array {
        if (!is_array($kicks) || !array_is_list($kicks)) throw new HttpException('Tanda de penales inválida.', 422, ['penalty_kicks' => 'Registre cada tiro en orden.']);
        return array_map(function (mixed $kick): array {
            $v = new Validator(is_array($kick) ? $kick : []);
            $team = $v->integer('team_id', 1, PHP_INT_MAX);
            $scored = $v->integer('scored', 0, 1);
            if ($v->errors()) throw new HttpException('Tiro de penal inválido.', 422, ['penalty_kicks' => 'Indique si cada tiro fue gol o falló.']);
            return ['team_id' => $team, 'scored' => $scored];
        }, $kicks);
    }
}
