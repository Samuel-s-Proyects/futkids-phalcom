<?php
declare(strict_types=1);
namespace App\Services\Knockout;

use App\Exceptions\HttpException;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\Format\TournamentFormat;
use App\Services\TournamentSeeding;
use App\Support\Clock;
use App\Validation\Validator;

/** Single-elimination tournaments: bracket generation and winner advancement. */
final class KnockoutEngine implements TournamentFormat
{
    public function __construct(private Clock $clock, private TournamentSeeding $seeding) {}

    public function allowsDraws(): bool {
        return false;
    }

    public function extraTimePeriods(Tournament $tournament): int {
        return (int)$tournament->extra_time_periods;
    }

    /**
     * Creates every match of the bracket. Round-one matches with a missing side are explicit byes whose
     * team already occupies its round-two slot. Random draws fix the seed order first. Idempotent.
     */
    public function generate(Tournament $tournament): void {
        if ($tournament->bracket_generated_at !== null || TournamentMatch::count(['tournament_id = :t:', 'bind' => ['t' => (int)$tournament->id]])) return;
        if ($tournament->registrations_closed_at === null) throw new HttpException('Cierre las inscripciones antes de generar el cuadro.', 409);
        $teamIds = $this->seededTeamIds($tournament);
        if (count($teamIds) < 2) throw new HttpException('Se requieren al menos dos equipos para generar el cuadro.', 409);

        $previousRound = [];
        foreach (BracketBuilder::firstRound($teamIds) as $pair) {
            $bye = $pair['home'] === null || $pair['away'] === null;
            $previousRound[$pair['match_number']] = $this->createMatch($tournament, 1, $pair['match_number'], [
                'home_team_id' => $pair['home'],
                'away_team_id' => $pair['away'],
                'winner_team_id' => $bye ? ($pair['home'] ?? $pair['away']) : null,
                'status' => $bye ? 'bye' : 'pending',
                'workflow_status' => $bye ? TournamentMatch::BYE : TournamentMatch::UNPROGRAMMED,
            ]);
        }
        for ($round = 2, $rounds = BracketBuilder::rounds(count($teamIds)); $round <= $rounds; $round++) {
            $currentRound = [];
            for ($number = 1; $number <= count($previousRound) / 2; $number++) {
                [$left, $right] = array_map(fn(int $feeder) => $previousRound[$feeder], BracketBuilder::feederMatchNumbers($number));
                $currentRound[$number] = $this->createMatch($tournament, $round, $number, [
                    'home_team_id' => $left->status === 'bye' ? $left->winner_team_id : null,
                    'away_team_id' => $right->status === 'bye' ? $right->winner_team_id : null,
                    'status' => 'pending',
                    'workflow_status' => TournamentMatch::UNPROGRAMMED,
                ]);
            }
            $previousRound = $currentRound;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $tournament->bracket_generated_at = $now;
        $tournament->started_at = $now;
        $tournament->status = 'in_progress';
        $tournament->saveOrFail('No se pudo marcar el cuadro como generado.');
    }

    public function validateSchedule(Tournament $tournament, TournamentMatch $match, \DateTimeImmutable $start, Validator $validator): void {
        foreach ($this->feeders($match) as $feeder) {
            if ($feeder->status === 'bye' || !$feeder->scheduled_at) continue;
            $earliest = (new \DateTimeImmutable((string)$feeder->scheduled_at))->modify('+' . (int)$tournament->match_duration_minutes . ' minutes');
            if ($start < $earliest) {
                $validator->fail('scheduled_at', 'Debe jugarse después de los partidos que lo definen: a partir del ' . $earliest->format('d/m/Y H:i') . '.');
            }
        }
    }

    public function onResultConfirmed(Tournament $tournament, TournamentMatch $match): void {
        $next = $this->nextMatch($match);
        if (!$next) {
            $tournament->status = 'finished';
            $tournament->finished_at = $this->clock->now()->format('Y-m-d H:i:s');
            $tournament->champion_team_id = (int)$match->winner_team_id;
            $tournament->saveOrFail('No se pudo cerrar el torneo.');
            return;
        }
        $side = BracketBuilder::nextSlot((int)$match->match_number)['side'];
        if ($next->$side !== null && (int)$next->$side !== (int)$match->winner_team_id) {
            throw new HttpException('El partido siguiente ya contiene otro clasificado; no se puede avanzar este resultado.', 409);
        }
        $next->$side = (int)$match->winner_team_id;
        $next->saveOrFail('No se pudo avanzar al ganador en el cuadro.');
    }

    public function assertCorrectable(Tournament $tournament, TournamentMatch $match): void {
        $next = $this->nextMatch($match);
        if ($next && ($next->status !== 'pending' || !in_array($next->workflow_status, [TournamentMatch::UNPROGRAMMED, TournamentMatch::SCHEDULED], true))) {
            throw new HttpException('No se puede corregir: el partido de la fase siguiente ya comenzó o fue confirmado.', 409);
        }
    }

    public function onResultReopened(Tournament $tournament, TournamentMatch $match): void {
        $next = $this->nextMatch($match);
        if ($next) {
            $side = BracketBuilder::nextSlot((int)$match->match_number)['side'];
            if ((int)$next->$side === (int)$match->winner_team_id) $next->$side = null;
            // A match missing one of its teams cannot stay scheduled; its date, venue and referee are kept as a draft.
            if ($next->workflow_status === TournamentMatch::SCHEDULED) $next->workflow_status = TournamentMatch::UNPROGRAMMED;
            $next->saveOrFail('No se pudo retirar temporalmente el clasificado anterior.');
        }
        if ($tournament->status === 'finished') {
            $tournament->status = 'in_progress';
            $tournament->finished_at = null;
            $tournament->champion_team_id = null;
            $tournament->saveOrFail('No se pudo reabrir el torneo.');
        }
    }

    /** Matches whose winners play this one, so it can only be scheduled after them. @return list<TournamentMatch> */
    public function feeders(TournamentMatch $match): array {
        if ((int)$match->round_number <= 1) return [];
        $feeders = [];
        foreach (BracketBuilder::feederMatchNumbers((int)$match->match_number) as $number) {
            $feeder = TournamentMatch::findFirst([
                'conditions' => 'tournament_id = :t: AND round_number = :round: AND match_number = :number:',
                'bind' => ['t' => (int)$match->tournament_id, 'round' => (int)$match->round_number - 1, 'number' => $number],
            ]);
            if ($feeder) $feeders[] = $feeder;
        }
        return $feeders;
    }

    private function nextMatch(TournamentMatch $match): ?TournamentMatch {
        return TournamentMatch::findFirst([
            'conditions' => 'tournament_id = :t: AND round_number = :round: AND match_number = :number:',
            'bind' => ['t' => (int)$match->tournament_id, 'round' => (int)$match->round_number + 1, 'number' => BracketBuilder::nextSlot((int)$match->match_number)['match_number']],
        ]) ?: null;
    }

    /** @return list<int> */
    private function seededTeamIds(Tournament $tournament): array {
        return $tournament->draw_method === 'random'
            ? $this->seeding->shuffle((int)$tournament->id)
            : $this->seeding->teamIds((int)$tournament->id);
    }

    private function createMatch(Tournament $tournament, int $round, int $number, array $data): TournamentMatch {
        $match = new TournamentMatch();
        $match->assign($data + ['tournament_id' => (int)$tournament->id, 'round_number' => $round, 'match_number' => $number, 'result_revision' => 0]);
        $match->saveOrFail('No se pudo guardar un partido del cuadro.');
        return $match;
    }
}
