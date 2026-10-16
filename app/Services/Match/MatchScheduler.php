<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Exceptions\HttpException;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\Format\Formats;
use App\Support\Clock;
use App\Support\Dates;
use App\Support\TextNormalizer;
use App\Validation\Validator;

/**
 * Assigns date, venue and referee to a match.
 *
 * Rules: never in the past; inside the tournament dates; after the matches that decide its teams
 * (knockout) or inside its matchday (league); and, across every tournament, no team, venue or referee
 * may have two matches whose playing time overlaps. The venue is compared ignoring case, accents and
 * punctuation, so "Cancha 2" and "cancha 2." are the same place.
 */
final class MatchScheduler
{
    /** Longest configurable match: candidates further apart than this cannot overlap. */
    private const MAX_DURATION_MINUTES = 180;

    private const OCCUPYING_STATES = [
        TournamentMatch::SCHEDULED,
        TournamentMatch::IN_PROGRESS,
        TournamentMatch::AWAITING_CONFIRMATION,
        TournamentMatch::CONFIRMED,
    ];

    public function __construct(private Clock $clock, private Formats $formats, private MatchAudit $audit) {}

    public function schedule(Tournament $tournament, TournamentMatch $match, array $input, int $actorId): void {
        $v = new Validator($input);
        $scheduledAt = $v->dateTime('scheduled_at');
        $venue = $v->text('venue', 150, 'title');
        $refereeId = $v->integer('referee_user_id', 1, PHP_INT_MAX);
        $v->assertValid();

        if (!in_array($match->workflow_status, [TournamentMatch::UNPROGRAMMED, TournamentMatch::SCHEDULED, TournamentMatch::SUSPENDED], true)
            || $match->status !== 'pending' || !$match->home_team_id || !$match->away_team_id) {
            throw new HttpException('Solo se puede programar un partido con sus dos equipos definidos que no haya comenzado.', 409);
        }

        $start = new \DateTimeImmutable($scheduledAt);
        if ($scheduledAt !== $match->scheduled_at && $start < $this->clock->now()) {
            $v->fail('scheduled_at', 'No se puede programar en el pasado. Elija una fecha y hora a partir de ahora (' . Dates::displayDateTime($this->clock->now()->format('Y-m-d H:i')) . ').');
        }
        $day = $start->format('Y-m-d');
        if ($day < $tournament->start_date || $day > $tournament->end_date) {
            $v->fail('scheduled_at', sprintf('La fecha debe estar dentro del torneo: del %s al %s.', Dates::display($tournament->start_date), Dates::display($tournament->end_date)));
        }
        $this->formats->for($tournament)->validateSchedule($tournament, $match, $start, $v);
        if (!User::findFirst(['conditions' => "id = :id: AND role = 'referee' AND active = 1", 'bind' => ['id' => $refereeId]])) {
            $v->fail('referee_user_id', 'Seleccione un árbitro de la lista.');
        }
        $v->assertValid('Revise la programación del partido.');

        $venueKey = TextNormalizer::key($venue);
        $this->assertNoClash($match, $start, (int)$tournament->match_duration_minutes, $venueKey, $refereeId);

        $before = ['scheduled_at' => $match->scheduled_at, 'venue' => $match->venue, 'referee_user_id' => $match->referee_user_id];
        $match->assign([
            'scheduled_at' => $scheduledAt,
            'venue' => $venue,
            'venue_key' => $venueKey,
            'referee_user_id' => $refereeId,
            'workflow_status' => TournamentMatch::SCHEDULED,
            'suspension_reason' => null,
        ]);
        $match->saveOrFail('No se pudo programar el partido.');
        $this->audit->record($match, $actorId, 'scheduled', ['before' => $before, 'after' => ['scheduled_at' => $scheduledAt, 'venue' => $venue, 'referee_user_id' => $refereeId]]);
    }

    private function assertNoClash(TournamentMatch $match, \DateTimeImmutable $start, int $duration, string $venueKey, int $refereeId): void {
        $end = $start->modify("+{$duration} minutes");
        $window = self::MAX_DURATION_MINUTES;
        $candidates = TournamentMatch::find([
            'conditions' => 'id != :id: AND scheduled_at >= :from: AND scheduled_at < :to: AND workflow_status IN ({states:array})',
            'bind' => [
                'id' => (int)$match->id,
                'from' => $start->modify("-{$window} minutes")->format('Y-m-d H:i:s'),
                'to' => $end->format('Y-m-d H:i:s'),
                'states' => self::OCCUPYING_STATES,
            ],
        ]);
        $durations = [];
        $errors = [];
        foreach ($candidates as $other) {
            $durations[(int)$other->tournament_id] ??= (int)Tournament::findFirst((int)$other->tournament_id)->match_duration_minutes;
            $otherStart = new \DateTimeImmutable((string)$other->scheduled_at);
            if ($otherStart->modify('+' . $durations[(int)$other->tournament_id] . ' minutes') <= $start) continue;
            $label = self::describe($other);
            if (array_intersect($match->teamIds(), $other->teamIds())) $errors['scheduled_at'] ??= "Un equipo ya juega a esa hora: {$label}.";
            if ($other->venue_key === $venueKey) $errors['venue'] ??= "La cancha está ocupada a esa hora: {$label}.";
            if ((int)$other->referee_user_id === $refereeId) $errors['referee_user_id'] ??= "El árbitro ya dirige otro partido a esa hora: {$label}.";
        }
        if ($errors) throw new HttpException('El horario choca con otro partido.', 409, $errors);
    }

    private static function describe(TournamentMatch $match): string {
        $name = fn($id) => $id ? (Team::findFirst((int)$id)?->name ?? 'Equipo') : 'Por definir';
        return sprintf('%s vs. %s, %s', $name($match->home_team_id), $name($match->away_team_id), Dates::displayDateTime((string)$match->scheduled_at));
    }

}
