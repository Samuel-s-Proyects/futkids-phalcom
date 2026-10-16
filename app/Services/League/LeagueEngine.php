<?php
declare(strict_types=1);
namespace App\Services\League;

use App\Exceptions\HttpException;
use App\Models\Incident;
use App\Models\Matchday;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\Format\TournamentFormat;
use App\Services\Match\MatchAudit;
use App\Services\TournamentSeeding;
use App\Support\Clock;
use App\Support\Dates;
use App\Validation\Validator;

/**
 * Points tournaments ("por puntos"): calendar of matchdays, draws allowed, table of positions.
 *
 * The number of matchdays follows from the teams and rounds (see FixtureGenerator); the organiser chooses
 * their dates in a preview before generating. Later, a match that could not be played can be postponed and
 * moved to another matchday, including an extraordinary one added for that purpose.
 */
final class LeagueEngine implements TournamentFormat
{
    public const MIN_TEAMS = 3;
    private const MAX_INTERVAL_DAYS = 30;

    public function __construct(private Clock $clock, private TournamentSeeding $seeding, private MatchAudit $audit) {}

    public function allowsDraws(): bool {
        return true;
    }

    public function extraTimePeriods(Tournament $tournament): int {
        return 0;
    }

    public function validateSchedule(Tournament $tournament, TournamentMatch $match, \DateTimeImmutable $start, Validator $validator): void {
        $matchday = $match->matchday_id ? Matchday::findFirst((int)$match->matchday_id) : null;
        if (!$matchday) {
            $validator->fail('scheduled_at', 'Asigne primero el partido a una jornada.');
            return;
        }
        $day = $start->format('Y-m-d');
        if ($day < $matchday->game_date || $day > $matchday->lastDay()) {
            $window = $matchday->end_date ? 'del ' . Dates::display($matchday->game_date) . ' al ' . Dates::display($matchday->lastDay()) : 'el ' . Dates::display($matchday->game_date);
            $validator->fail('scheduled_at', "Debe jugarse dentro de la jornada {$matchday->number}: {$window}.");
        }
    }

    public function onResultConfirmed(Tournament $tournament, TournamentMatch $match): void {
        $open = TournamentMatch::count([
            'tournament_id = :t: AND workflow_status NOT IN ({done:array})',
            'bind' => ['t' => (int)$tournament->id, 'done' => [TournamentMatch::CONFIRMED, TournamentMatch::CANCELLED]],
        ]);
        if ($open) return;
        $tournament->status = 'finished';
        $tournament->finished_at = $this->clock->now()->format('Y-m-d H:i:s');
        $tournament->champion_team_id = $this->standings($tournament)[0]['team_id'] ?? null;
        $tournament->saveOrFail('No se pudo cerrar el torneo.');
    }

    public function assertCorrectable(Tournament $tournament, TournamentMatch $match): void {
        // Any confirmed league result can be corrected: the table is always recalculated.
    }

    public function onResultReopened(Tournament $tournament, TournamentMatch $match): void {
        if ($tournament->status !== 'finished') return;
        $tournament->status = 'in_progress';
        $tournament->finished_at = null;
        $tournament->champion_team_id = null;
        $tournament->saveOrFail('No se pudo reabrir el torneo.');
    }

    // ---- Calendar -----------------------------------------------------------------------------------

    /**
     * Proposed calendar: one matchday every `interval_days` from `first_date`, with its pairings.
     * @return array{rounds: int, matchday_count: int, matchdays: list<array{number: int, leg: int, game_date: string, pairs: list<array{home: int, away: int}>, resting: ?int}>}
     */
    public function preview(Tournament $tournament, array $input): array {
        $this->assertCanGenerate($tournament);
        $v = new Validator($input);
        $first = $v->date('first_date');
        $interval = $v->integer('interval_days', 1, self::MAX_INTERVAL_DAYS, required: false) ?? 7;
        $v->assertValid();
        $calendar = FixtureGenerator::generate($this->seeding->teamIds((int)$tournament->id), (int)$tournament->league_rounds);
        $start = new \DateTimeImmutable($first);
        foreach ($calendar as $index => &$matchday) {
            $matchday['game_date'] = $start->modify('+' . ($index * $interval) . ' days')->format('Y-m-d');
            $matchday['number'] = $matchday['matchday'];
            unset($matchday['matchday']);
        }
        unset($matchday);
        $this->validateCalendarDates($tournament, array_column($calendar, 'game_date'), 'first_date');
        return ['rounds' => (int)$tournament->league_rounds, 'matchday_count' => count($calendar), 'matchdays' => $calendar];
    }

    /** Creates the matchdays (with the dates the organiser confirmed) and every match. Idempotent. */
    public function generate(Tournament $tournament, array $input): void {
        if ($tournament->fixture_generated_at !== null) return;
        $this->assertCanGenerate($tournament);
        $calendar = FixtureGenerator::generate($this->seeding->teamIds((int)$tournament->id), (int)$tournament->league_rounds);
        $dates = $input['game_dates'] ?? null;
        if (!is_array($dates) || !array_is_list($dates) || count($dates) !== count($calendar)) {
            throw new HttpException('Indique una fecha para cada una de las ' . count($calendar) . ' jornadas.', 422, ['game_dates' => 'Faltan fechas de jornadas.']);
        }
        $dates = array_map(fn($date) => is_string($date) ? trim($date) : '', $dates);
        $this->validateCalendarDates($tournament, $dates, 'game_dates');

        foreach ($calendar as $index => $day) {
            $matchday = new Matchday();
            $matchday->assign(['tournament_id' => (int)$tournament->id, 'number' => $day['matchday'], 'game_date' => $dates[$index], 'kind' => Matchday::REGULAR]);
            $matchday->saveOrFail('No se pudo crear la jornada.');
            foreach ($day['pairs'] as $number => $pair) {
                $match = new TournamentMatch();
                $match->assign([
                    'tournament_id' => (int)$tournament->id,
                    'round_number' => $day['matchday'],
                    'match_number' => $number + 1,
                    'leg' => $day['leg'],
                    'matchday_id' => (int)$matchday->id,
                    'home_team_id' => $pair['home'],
                    'away_team_id' => $pair['away'],
                    'status' => 'pending',
                    'workflow_status' => TournamentMatch::UNPROGRAMMED,
                    'result_revision' => 0,
                ]);
                $match->saveOrFail('No se pudo crear un partido del calendario.');
            }
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $tournament->assign(['fixture_generated_at' => $now, 'started_at' => $now, 'status' => 'in_progress']);
        $tournament->saveOrFail('No se pudo marcar el calendario como generado.');
    }

    /** Extra matchday after the last one, to replay postponed matches. */
    public function addExtraordinaryMatchday(Tournament $tournament, array $input): Matchday {
        $this->assertCalendarEditable($tournament);
        $v = new Validator($input);
        $date = $v->date('game_date');
        $end = $v->date('end_date', required: false);
        $label = $v->text('label', 100, 'title', required: false);
        if ($date) $this->validateMatchdayWindow($tournament, $date, $end, $v);
        $v->assertValid();
        $matchday = new Matchday();
        $matchday->assign([
            'tournament_id' => (int)$tournament->id,
            'number' => (int)Matchday::maximum(['column' => 'number', 'conditions' => 'tournament_id = :t:', 'bind' => ['t' => (int)$tournament->id]]) + 1,
            'game_date' => $date,
            'end_date' => $end,
            'kind' => Matchday::EXTRAORDINARY,
            'label' => $label ?? 'Jornada extraordinaria',
        ]);
        $matchday->saveOrFail('No se pudo crear la jornada extraordinaria.');
        return $matchday;
    }

    /** Changes the dates (or label) of a matchday, keeping matchdays in order and matches inside them. */
    public function updateMatchday(Tournament $tournament, Matchday $matchday, array $input): Matchday {
        $this->assertCalendarEditable($tournament);
        $v = new Validator($input);
        $date = $v->date('game_date');
        $end = $v->date('end_date', required: false);
        $label = $v->text('label', 100, 'title', required: false);
        if ($date && ($date !== $matchday->game_date || $end !== $matchday->end_date)) {
            $this->validateMatchdayWindow($tournament, $date, $end, $v, $date === $matchday->game_date);
            if ($matchday->kind === Matchday::REGULAR) $this->validateRegularOrder($matchday, $date, $end ?? $date, $v);
            $outside = TournamentMatch::count([
                'matchday_id = :m: AND scheduled_at IS NOT NULL AND workflow_status IN ({states:array}) AND (scheduled_at < :from: OR scheduled_at > :to:)',
                'bind' => ['m' => (int)$matchday->id, 'states' => [TournamentMatch::SCHEDULED, TournamentMatch::IN_PROGRESS, TournamentMatch::AWAITING_CONFIRMATION, TournamentMatch::CONFIRMED], 'from' => "{$date} 00:00:00", 'to' => ($end ?? $date) . ' 23:59:59'],
            ]);
            if ($outside) $v->fail('game_date', "Hay {$outside} partido(s) programados fuera de esas fechas; reprográmelos primero.");
        }
        $v->assertValid();
        $matchday->assign(['game_date' => $date, 'end_date' => $end, 'label' => $label ?? $matchday->label]);
        $matchday->saveOrFail('No se pudo actualizar la jornada.');
        return $matchday;
    }

    public function deleteMatchday(Tournament $tournament, Matchday $matchday): void {
        $this->assertCalendarEditable($tournament);
        if ($matchday->kind !== Matchday::EXTRAORDINARY) throw new HttpException('Solo se pueden eliminar jornadas extraordinarias.', 409);
        if (TournamentMatch::count(['matchday_id = :m:', 'bind' => ['m' => (int)$matchday->id]])) {
            throw new HttpException('La jornada tiene partidos; muévalos a otra jornada antes de eliminarla.', 409);
        }
        $matchday->deleteOrFail('No se pudo eliminar la jornada.');
    }

    /** A match that cannot be played on its date: it is unscheduled and waits to be moved or rescheduled. */
    public function postpone(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): void {
        $this->assertCalendarEditable($tournament);
        if (!in_array($match->workflow_status, [TournamentMatch::UNPROGRAMMED, TournamentMatch::SCHEDULED], true)) {
            throw new HttpException('Solo se puede aplazar un partido que aún no ha comenzado.', 409);
        }
        $v = new Validator($input);
        $reason = $v->text('reason', 255, 'description');
        $v->assertValid();
        $before = $match->toArray();
        $match->assign([
            'workflow_status' => TournamentMatch::SUSPENDED,
            'suspension_reason' => $reason,
            'original_matchday_id' => $match->original_matchday_id ?? $match->matchday_id,
            'scheduled_at' => null,
            'venue' => null,
            'venue_key' => null,
            'referee_user_id' => null,
        ]);
        $match->saveOrFail('No se pudo aplazar el partido.');
        $this->audit->record($match, $actorId, 'postponed', ['before' => $before, 'reason' => $reason]);
    }

    /** Moves an unplayed match to another matchday of the tournament (neither team may already play there). */
    public function moveToMatchday(Tournament $tournament, TournamentMatch $match, int $actorId, array $input): void {
        $this->assertCalendarEditable($tournament);
        if (!in_array($match->workflow_status, [TournamentMatch::UNPROGRAMMED, TournamentMatch::SUSPENDED], true)) {
            throw new HttpException('Aplace el partido (o quite su programación) antes de moverlo de jornada.', 409);
        }
        $v = new Validator($input);
        $matchdayId = $v->integer('matchday_id', 1, PHP_INT_MAX);
        $v->assertValid();
        $target = Matchday::findFirst(['id = :id: AND tournament_id = :t:', 'bind' => ['id' => $matchdayId, 't' => (int)$tournament->id]]);
        if (!$target) throw new HttpException('La jornada no pertenece a este torneo.', 422, ['matchday_id' => 'Elija una jornada de este torneo.']);
        if ((int)$target->id === (int)$match->matchday_id) throw new HttpException('El partido ya está en esa jornada.', 422, ['matchday_id' => 'Elija otra jornada.']);
        if ($target->lastDay() < $this->clock->today()) throw new HttpException('Esa jornada ya pasó.', 422, ['matchday_id' => 'Elija una jornada futura o cree una extraordinaria.']);
        $busy = TournamentMatch::find([
            'matchday_id = :m: AND id != :id: AND workflow_status != :cancelled:',
            'bind' => ['m' => (int)$target->id, 'id' => (int)$match->id, 'cancelled' => TournamentMatch::CANCELLED],
        ]);
        foreach ($busy as $other) {
            $shared = array_intersect($match->teamIds(), $other->teamIds());
            if ($shared) {
                $name = Team::findFirst((int)current($shared))?->name ?? 'Un equipo';
                throw new HttpException("{$name} ya juega en la jornada {$target->number}.", 409, ['matchday_id' => 'Elija una jornada en la que ninguno de los dos equipos juegue.']);
            }
        }
        $before = ['matchday_id' => $match->matchday_id, 'workflow_status' => $match->workflow_status];
        $match->assign(['matchday_id' => (int)$target->id, 'workflow_status' => TournamentMatch::UNPROGRAMMED]);
        $match->saveOrFail('No se pudo mover el partido.');
        $this->audit->record($match, $actorId, 'moved_matchday', ['before' => $before, 'after' => ['matchday_id' => (int)$target->id]]);
    }

    // ---- Table --------------------------------------------------------------------------------------

    /** Current table (confirmed results only) with the number of results still awaiting confirmation. */
    public function standings(Tournament $tournament): array {
        $id = (int)$tournament->id;
        $matches = TournamentMatch::find(['tournament_id = :t:', 'bind' => ['t' => $id], 'order' => 'scheduled_at, round_number, match_number'])->toArray();
        $confirmed = array_values(array_filter($matches, fn($m) => $m['workflow_status'] === TournamentMatch::CONFIRMED && $m['home_score'] !== null));
        $cards = [];
        $matchIds = array_map('intval', array_column($matches, 'id')) ?: [0];
        foreach (Incident::find(['tournament_match_id IN ({ids:array})', 'bind' => ['ids' => $matchIds]]) as $incident) {
            $cards[(int)$incident->team_id] ??= ['red' => 0, 'yellow' => 0];
            $cards[(int)$incident->team_id][$incident->card_type]++;
        }
        $teamIds = $this->seeding->teamIds($id);
        $names = array_column(Team::find(['id IN ({ids:array})', 'bind' => ['ids' => $teamIds ?: [0]]])->toArray(), 'name', 'id');
        return StandingsCalculator::calculate(
            $teamIds,
            $confirmed,
            ['win' => (int)$tournament->points_win, 'draw' => (int)$tournament->points_draw, 'loss' => (int)$tournament->points_loss],
            $cards,
            $names,
        );
    }

    // ---- Rules --------------------------------------------------------------------------------------

    private function assertCanGenerate(Tournament $tournament): void {
        if (!$tournament->isLeague()) throw new HttpException('Este torneo es de clasificación: genere su cuadro.', 409);
        if ($tournament->registrations_closed_at === null) throw new HttpException('Cierre las inscripciones antes de generar el calendario.', 409);
        if ($tournament->fixture_generated_at !== null) throw new HttpException('El calendario ya fue generado.', 409);
        if (count($this->seeding->teamIds((int)$tournament->id)) < self::MIN_TEAMS) throw new HttpException('Se requieren al menos ' . self::MIN_TEAMS . ' equipos.', 409);
    }

    private function assertCalendarEditable(Tournament $tournament): void {
        if (!$tournament->isLeague()) throw new HttpException('Los torneos de clasificación se organizan por rondas, no por jornadas.', 409);
        if ((int)$tournament->is_legacy) throw new HttpException('El torneo histórico es de solo consulta.', 409);
        if ($tournament->status !== 'in_progress') throw new HttpException('El calendario solo se modifica con el torneo en curso.', 409);
    }

    /** Dates in order, from today, inside the tournament. @param list<string> $dates */
    private function validateCalendarDates(Tournament $tournament, array $dates, string $field): void {
        $today = $this->clock->today();
        foreach ($dates as $index => $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $number = $index + 1;
            $problem = match (true) {
                !$parsed || $parsed->format('Y-m-d') !== $date => "La jornada {$number} no tiene una fecha válida.",
                $date < $today => "La jornada {$number} no puede ser antes de hoy (" . Dates::display($today) . ').',
                $date < $tournament->start_date => "La jornada {$number} es anterior al inicio del torneo (" . Dates::display($tournament->start_date) . ').',
                $date > $tournament->end_date => "La jornada {$number} (" . Dates::display($date) . ') queda después del final del torneo (' . Dates::display($tournament->end_date) . '). Reduzca el intervalo o amplíe la fecha final.',
                $index > 0 && $date <= $dates[$index - 1] => "La jornada {$number} debe ser posterior a la jornada {$index}.",
                default => null,
            };
            if ($problem) throw new HttpException($problem, 422, [$field => $problem]);
        }
    }

    private function validateMatchdayWindow(Tournament $tournament, string $date, ?string $end, Validator $v, bool $keepsStart = false): void {
        if (!$keepsStart && $date < $this->clock->today()) $v->fail('game_date', 'La jornada no puede ser antes de hoy (' . Dates::display($this->clock->today()) . ').');
        if ($date < $tournament->start_date || $date > $tournament->end_date) {
            $v->fail('game_date', 'Debe estar dentro del torneo: del ' . Dates::display($tournament->start_date) . ' al ' . Dates::display($tournament->end_date) . '.');
        }
        if ($end !== null && ($end < $date || $end > $tournament->end_date)) {
            $v->fail('end_date', 'El último día debe ser igual o posterior al primero y dentro del torneo.');
        }
    }

    /** Regular matchdays keep their order: after the previous one ends and before the next one starts. */
    private function validateRegularOrder(Matchday $matchday, string $start, string $end, Validator $v): void {
        $bind = ['t' => (int)$matchday->tournament_id, 'n' => (int)$matchday->number, 'kind' => Matchday::REGULAR];
        $previous = Matchday::findFirst(['tournament_id = :t: AND number < :n: AND kind = :kind:', 'bind' => $bind, 'order' => 'number DESC']);
        $next = Matchday::findFirst(['tournament_id = :t: AND number > :n: AND kind = :kind:', 'bind' => $bind, 'order' => 'number ASC']);
        if ($previous && $start <= $previous->lastDay()) $v->fail('game_date', "Debe ser después de la jornada {$previous->number} (" . Dates::display($previous->lastDay()) . ').');
        if ($next && $end >= $next->game_date) $v->fail('game_date', "Debe terminar antes de la jornada {$next->number} (" . Dates::display($next->game_date) . ').');
    }
}
