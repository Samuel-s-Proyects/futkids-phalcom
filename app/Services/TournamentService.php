<?php
declare(strict_types=1);
namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\Incident;
use App\Models\Matchday;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchGoalEvent;
use App\Models\TournamentMatchPenalty;
use App\Models\TournamentTeam;
use App\Services\Format\Formats;
use App\Support\Clock;
use App\Support\Dates;
use App\Support\TextNormalizer;
use App\Validation\Validator;

/**
 * Tournament configuration, registration of teams and generation of the matches.
 * Callers run each mutating method inside a Transaction (see ApiController::transactional).
 */
final class TournamentService
{
    private const EDITABLE_FIELDS = [
        'name', 'type', 'start_date', 'end_date', 'match_duration_minutes',
        'extra_time_periods', 'extra_time_period_minutes', 'penalty_attempts_per_team', 'penalty_sudden_death',
        'league_rounds', 'points_win', 'points_draw', 'points_loss',
    ];

    public function __construct(private Clock $clock, private Formats $formats, private TournamentSeeding $seeding) {}

    public function find(int $id): Tournament {
        return Tournament::findOrFail($id, 'El torneo no existe.');
    }

    public function match(Tournament $tournament, int $matchId): TournamentMatch {
        $match = TournamentMatch::findFirst(['conditions' => 'id = :id: AND tournament_id = :t:', 'bind' => ['id' => $matchId, 't' => (int)$tournament->id]]);
        if (!$match) throw new HttpException('El partido no existe en este torneo.', 404);
        return $match;
    }

    /** @return list<array> */
    public function list(): array {
        $participants = [];
        foreach (TournamentTeam::find() as $row) $participants[(int)$row->tournament_id] = ($participants[(int)$row->tournament_id] ?? 0) + 1;
        return array_map(
            fn(Tournament $t) => $t->toArray() + ['participant_count' => $participants[(int)$t->id] ?? 0, 'lifecycle' => TournamentLifecycle::describe($t, $participants[(int)$t->id] ?? 0)],
            iterator_to_array(Tournament::find(['order' => 'start_date DESC, id DESC'])),
        );
    }

    /** Everything the tournament page needs in one response. */
    public function detail(int $id): array {
        $tournament = $this->find($id);
        $participants = TournamentTeam::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $id], 'order' => 'seed_order ASC'])->toArray();
        $matches = TournamentMatch::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $id], 'order' => 'round_number ASC, match_number ASC'])->toArray();
        $matchIds = array_map('intval', array_column($matches, 'id')) ?: [0];
        $byMatch = ['conditions' => 'tournament_match_id IN ({ids:array})', 'bind' => ['ids' => $matchIds]];
        return $tournament->toArray() + [
            'participants' => $participants,
            'matches' => $matches,
            'goal_events' => TournamentMatchGoalEvent::find($byMatch + ['order' => 'tournament_match_id, period_number, minute, id'])->toArray(),
            'penalty_kicks' => TournamentMatchPenalty::find($byMatch + ['order' => 'tournament_match_id, kick_number'])->toArray(),
            'incidents' => Incident::find($byMatch + ['order' => 'tournament_match_id, minute, id'])->toArray(),
            'scorers' => $this->scorers($id),
            'lifecycle' => TournamentLifecycle::describe($tournament, count($participants)),
        ] + ($tournament->isLeague() ? [
            'matchdays' => Matchday::find(['tournament_id = :t:', 'bind' => ['t' => $id], 'order' => 'number'])->toArray(),
            'standings' => $this->formats->league()->standings($tournament),
            'matchday_count' => League\FixtureGenerator::totalMatchdays(max(2, count($participants)), (int)$tournament->league_rounds),
        ] : []);
    }

    /** Goals per player in this tournament's matches; own goals never count. @return list<array> */
    public function scorers(int $tournamentId): array {
        if ((int)$this->find($tournamentId)->is_legacy) return $this->historicScorers($tournamentId);
        $rows = (new \Phalcon\Mvc\Model\Query\Builder())
            ->columns(['e.player_id', 'e.team_id', 'COUNT(e.id) AS goals'])
            ->from(['e' => TournamentMatchGoalEvent::class])
            ->join(TournamentMatch::class, 'm.id = e.tournament_match_id', 'm')
            ->where('m.tournament_id = :t: AND e.own_goal = 0', ['t' => $tournamentId])
            ->groupBy(['e.player_id', 'e.team_id'])
            ->getQuery()->execute()->toArray();
        $rows = array_map(fn(array $row) => ['player_id' => (int)$row['player_id'], 'team_id' => (int)$row['team_id'], 'goals' => (int)$row['goals']], $rows);
        usort($rows, fn($a, $b) => [$b['goals'], $a['player_id']] <=> [$a['goals'], $b['player_id']]);
        return $rows;
    }

    /** The historic tournament keeps per-matchday totals instead of match sheets. @return list<array> */
    private function historicScorers(int $tournamentId): array {
        $rows = (new \Phalcon\Mvc\Model\Query\Builder())
            ->columns(['g.player_id', 'p.team_id', 'SUM(g.goals) AS goals'])
            ->from(['g' => \App\Models\Goal::class])
            ->join(Matchday::class, 'd.id = g.matchday_id', 'd')
            ->join(\App\Models\Player::class, 'p.id = g.player_id', 'p')
            ->where('d.tournament_id = :t:', ['t' => $tournamentId])
            ->groupBy(['g.player_id', 'p.team_id'])
            ->getQuery()->execute()->toArray();
        $rows = array_map(fn(array $row) => ['player_id' => (int)$row['player_id'], 'team_id' => (int)$row['team_id'], 'goals' => (int)$row['goals']], $rows);
        $rows = array_values(array_filter($rows, fn(array $row) => $row['goals'] > 0));
        usort($rows, fn($a, $b) => [$b['goals'], $a['player_id']] <=> [$a['goals'], $b['player_id']]);
        return $rows;
    }

    public function save(array $input, int $id = 0): array {
        $tournament = $id ? $this->find($id) : new Tournament();
        if ($id && $tournament->status !== 'draft') throw new HttpException('Solo se pueden editar los datos de un torneo en borrador.', 409);
        $data = $this->validated($input, $tournament);
        $tournament->assign($data);
        $tournament->saveOrFail('No se pudo guardar el torneo.');
        return $tournament->toArray();
    }

    public function openRegistration(int $id): array {
        $tournament = $this->find($id);
        if ($tournament->status !== 'draft') throw new HttpException('Solo se puede abrir la inscripción de un torneo en borrador.', 409);
        $tournament->status = 'registration';
        $tournament->saveOrFail('No se pudo abrir la inscripción.');
        return $tournament->toArray();
    }

    public function enroll(int $id, int $teamId): array {
        $tournament = $this->openForRegistration($id, 'Las inscripciones no están abiertas.');
        $team = Team::findOrFail($teamId, 'El equipo no existe.');
        if (!(int)$team->active) throw new HttpException('No se pueden inscribir equipos inactivos.', 409, ['team_id' => 'Active el equipo antes de inscribirlo.']);
        if (TournamentTeam::count(['tournament_id = :t: AND team_id = :team:', 'bind' => ['t' => $id, 'team' => $teamId]])) {
            throw new HttpException('El equipo ya está inscrito en este torneo.', 409, ['team_id' => 'Ese equipo ya está inscrito.']);
        }
        $participant = new TournamentTeam();
        $participant->assign(['tournament_id' => (int)$tournament->id, 'team_id' => $teamId, 'seed_order' => count($this->seeding->teamIds($id)) + 1]);
        $participant->saveOrFail('No se pudo inscribir el equipo.');
        return $participant->toArray();
    }

    public function removeParticipant(int $id, int $teamId): void {
        $this->openForRegistration($id, 'No se pueden cambiar los participantes después de cerrar la inscripción.');
        $participant = TournamentTeam::findFirst(['conditions' => 'tournament_id = :t: AND team_id = :team:', 'bind' => ['t' => $id, 'team' => $teamId]]);
        if (!$participant) throw new HttpException('El equipo no está inscrito en este torneo.', 404);
        $participant->deleteOrFail('No se pudo retirar el equipo.');
        $this->seeding->store($id, $this->seeding->teamIds($id));
    }

    /** @param list<mixed> $teamIds */
    public function orderParticipants(int $id, array $teamIds): void {
        $this->openForRegistration($id, 'El orden no se puede cambiar después de cerrar la inscripción.');
        $ordered = [];
        foreach ($teamIds as $teamId) {
            if (!is_int($teamId) && !(is_string($teamId) && ctype_digit($teamId))) throw new HttpException('El orden contiene un equipo inválido.', 422, ['team_ids' => 'Use identificadores numéricos.']);
            $ordered[] = (int)$teamId;
        }
        $enrolled = $this->seeding->teamIds($id);
        $sortedOrder = $ordered;
        sort($sortedOrder);
        sort($enrolled);
        if ($sortedOrder !== $enrolled) throw new HttpException('El orden debe contener exactamente una vez cada equipo inscrito.', 422, ['team_ids' => 'Incluya cada equipo inscrito una sola vez.']);
        $this->seeding->store($id, $ordered);
    }

    public function closeRegistration(int $id, mixed $drawMethod): array {
        $v = new Validator(['draw_method' => $drawMethod]);
        $method = $v->choice('draw_method', ['random', 'manual'], 'Seleccione sorteo aleatorio u orden manual.');
        $v->assertValid();
        $tournament = $this->openForRegistration($id, 'Las inscripciones ya están cerradas o no están abiertas.');
        $minimum = $tournament->isLeague() ? 3 : 2;
        if (count($this->seeding->teamIds($id)) < $minimum) throw new HttpException("Inscriba al menos {$minimum} equipos antes de cerrar las inscripciones.", 409);
        // A league draws its order now, so the calendar preview is exactly what will be generated.
        if ($tournament->isLeague() && $method === 'random') $this->seeding->shuffle($id);
        $tournament->draw_method = $method;
        $tournament->registrations_closed_at = $this->clock->now()->format('Y-m-d H:i:s');
        $tournament->saveOrFail('No se pudieron cerrar las inscripciones.');
        return $tournament->toArray();
    }

    /** Knockout: the bracket. (League fixtures have their own preview step.) */
    public function generateBracket(int $id): void {
        $tournament = $this->find($id);
        if (!$tournament->isKnockout()) throw new HttpException('Este torneo es por puntos: genere su calendario de jornadas.', 409);
        $this->formats->knockout()->generate($tournament);
    }

    public function previewFixture(int $id, array $input): array {
        return $this->formats->league()->preview($this->find($id), $input);
    }

    public function generateFixture(int $id, array $input): void {
        $this->formats->league()->generate($this->find($id), $input);
    }

    public function standings(int $id): array {
        $tournament = $this->find($id);
        if (!$tournament->isLeague()) throw new HttpException('Los torneos de clasificación no tienen tabla de posiciones.', 409);
        return $this->formats->league()->standings($tournament);
    }

    public function matchday(Tournament $tournament, int $matchdayId): Matchday {
        $matchday = Matchday::findFirst(['id = :id: AND tournament_id = :t:', 'bind' => ['id' => $matchdayId, 't' => (int)$tournament->id]]);
        if (!$matchday) throw new HttpException('La jornada no existe en este torneo.', 404);
        return $matchday;
    }

    public function addExtraordinaryMatchday(int $id, array $input): void {
        $this->formats->league()->addExtraordinaryMatchday($this->find($id), $input);
    }

    public function updateMatchday(int $id, int $matchdayId, array $input): void {
        $tournament = $this->find($id);
        $this->formats->league()->updateMatchday($tournament, $this->matchday($tournament, $matchdayId), $input);
    }

    public function deleteMatchday(int $id, int $matchdayId): void {
        $tournament = $this->find($id);
        $this->formats->league()->deleteMatchday($tournament, $this->matchday($tournament, $matchdayId));
    }

    /**
     * While the tournament runs its end may only move later (to fit postponed or extraordinary matches);
     * every other setting stays as it was when it started.
     */
    public function extendEndDate(int $id, array $input): array {
        $tournament = $this->find($id);
        if (!in_array($tournament->status, ['registration', 'in_progress'], true)) throw new HttpException('Solo se puede extender un torneo abierto o en curso.', 409);
        $v = new Validator($input);
        $end = $v->date('end_date');
        if ($end && $end <= $tournament->end_date) $v->fail('end_date', 'Solo se puede extender: elija una fecha posterior al ' . Dates::display($tournament->end_date) . '.');
        if ($end && $end < $this->clock->today()) $v->fail('end_date', 'La nueva fecha final no puede ser anterior a hoy.');
        $v->assertValid();
        $tournament->end_date = $end;
        $tournament->saveOrFail('No se pudo extender el torneo.');
        return $tournament->toArray();
    }

    private function openForRegistration(int $id, string $message): Tournament {
        $tournament = $this->find($id);
        if ($tournament->status !== 'registration' || $tournament->registrations_closed_at !== null) throw new HttpException($message, 409);
        return $tournament;
    }

    /** @return array<string, mixed> */
    private function validated(array $input, Tournament $current): array {
        $v = new Validator(array_intersect_key($input, array_flip(self::EDITABLE_FIELDS)));
        $data = [
            'name' => $v->text('name', 150, 'title'),
            'type' => $v->choice('type', [Tournament::KNOCKOUT, Tournament::LEAGUE], 'Elija clasificación (eliminación directa) o por puntos.', required: false) ?? ($current->type ?? Tournament::KNOCKOUT),
            'start_date' => $v->date('start_date'),
            'end_date' => $v->date('end_date'),
            'match_duration_minutes' => $v->integer('match_duration_minutes', 10, 180, required: false) ?? ($current->match_duration_minutes ?? 60),
        ];
        if ($data['type'] === Tournament::LEAGUE) {
            $rounds = $v->choice('league_rounds', ['1', '2'], 'Elija una vuelta (solo ida) o dos (ida y vuelta).');
            $data += [
                'league_rounds' => $rounds === null ? null : (int)$rounds,
                'points_win' => $v->integer('points_win', 0, 10),
                'points_draw' => $v->integer('points_draw', 0, 10),
                'points_loss' => $v->integer('points_loss', 0, 10),
                'extra_time_periods' => 0,
                'extra_time_period_minutes' => null,
                'penalty_attempts_per_team' => null,
                'penalty_sudden_death' => null,
            ];
            if ($data['points_win'] !== null && $data['points_draw'] !== null && $data['points_win'] <= $data['points_draw']) {
                $v->fail('points_win', 'Ganar debe dar más puntos que empatar.');
            }
            if ($data['points_draw'] !== null && $data['points_loss'] !== null && $data['points_draw'] < $data['points_loss']) {
                $v->fail('points_draw', 'Empatar no puede dar menos puntos que perder.');
            }
        } else {
            $extraPeriods = $v->integer('extra_time_periods', 0, 4);
            $data += [
                'extra_time_periods' => $extraPeriods,
                'extra_time_period_minutes' => $extraPeriods ? $v->integer('extra_time_period_minutes', 1, 30) : null,
                'penalty_attempts_per_team' => $v->integer('penalty_attempts_per_team', 1, 10),
                'penalty_sudden_death' => $v->flag('penalty_sudden_death', 'Indique si la tanda sigue con muerte súbita.'),
                'league_rounds' => null,
            ];
        }

        $today = $this->clock->today();
        $startChanged = !$current->id || $data['start_date'] !== $current->start_date;
        if ($data['start_date'] && $startChanged && $data['start_date'] < $today) {
            $v->fail('start_date', 'El torneo no puede empezar antes de hoy (' . Dates::display($today) . ').');
        }
        if ($data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date']) {
            $v->fail('end_date', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ($data['name'] && $this->nameTaken($data['name'], (int)$current->id)) {
            $v->fail('name', 'Ya existe un torneo con ese nombre.');
        }
        $v->assertValid();
        return $data;
    }

    private function nameTaken(string $name, int $exceptId): bool {
        $key = TextNormalizer::key($name);
        foreach (Tournament::find(['columns' => 'id, name']) as $row) {
            if ((int)$row->id !== $exceptId && TextNormalizer::key((string)$row->name) === $key) return true;
        }
        return false;
    }
}
