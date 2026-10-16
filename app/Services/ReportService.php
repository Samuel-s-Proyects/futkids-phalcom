<?php
declare(strict_types=1);
namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\Incident;
use App\Models\Matchday;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchGoal;
use App\Models\TournamentTeam;
use App\Validation\Validator;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * Referee list (who may play on a date), incident report, scorers table and the player file.
 *
 * Goals come from two sources: historic per-matchday totals (goals table) and the goals recorded on
 * tournament match sheets. Own goals never count for anybody.
 */
final class ReportService
{
    public function __construct(private AdapterInterface $db, private SuspensionService $suspensions, private array $settings) {}

    public function report(string $kind, array $filters): array {
        $v = new Validator($filters);
        $teamId = $v->integer('team_id', 1, PHP_INT_MAX, required: false);
        $matchdayId = $v->integer('matchday_id', 1, PHP_INT_MAX, required: false);
        $matchId = $v->integer('match_id', 1, PHP_INT_MAX, required: false);
        $tournamentId = $v->integer('tournament_id', 1, PHP_INT_MAX, required: false);
        if ($kind === 'referee' && !$matchdayId && !$matchId) $v->fail('match_id', 'Elija el partido (o la jornada) para revisar quién puede jugar.');
        $v->assertValid('Revise los filtros del reporte.');
        if ($teamId) Team::findOrFail($teamId, 'El equipo no existe.');
        if ($tournamentId) Tournament::findOrFail($tournamentId, 'El torneo no existe.');

        return ['rows' => match ($kind) {
            'incidents' => $this->incidents($filters, $teamId),
            'referee' => $this->refereeList($teamId, $matchdayId, $matchId),
            'scorers' => $this->scorers($teamId, $tournamentId, $matchdayId),
            default => throw new HttpException('Reporte no encontrado.', 404),
        }, 'filters' => $filters];
    }

    public function player(int $id): array {
        $player = $this->decorate(Player::findOrFail($id, 'El jugador no existe.')->toArray(), $this->teamNames());
        $player['incidents'] = Incident::find(['player_id = :p:', 'bind' => ['p' => $id], 'order' => 'incident_date DESC, id DESC'])->toArray();
        $historic = $this->totals('SELECT matchday_id AS id, SUM(goals) AS total FROM goals WHERE player_id = ? GROUP BY matchday_id', [$id]);
        $player['history'] = array_map(fn(array $matchday) => $matchday + [
            'goals' => $historic[(int)$matchday['id']] ?? 0,
            'suspended' => $this->suspensions->isSuspended($id, (string)$matchday['game_date']),
        ], Matchday::find(['order' => 'tournament_id, number'])->toArray());
        $player['match_history'] = TournamentMatchGoal::find(['player_id = :p:', 'bind' => ['p' => $id]])->toArray();
        $player['total_goals'] = array_sum($historic) + array_sum(array_map('intval', array_column($player['match_history'], 'goals')));
        return $player;
    }

    /** Players who can or cannot play: the two teams of a match on its date, or everybody on a matchday's date. */
    private function refereeList(?int $teamId, ?int $matchdayId, ?int $matchId): array {
        if ($matchId) {
            $match = TournamentMatch::findOrFail($matchId, 'El partido no existe.');
            if (!$match->scheduled_at) throw new HttpException('El partido aún no tiene fecha.', 409, ['match_id' => 'Programe el partido primero.']);
            $date = $match->matchDate();
            $players = array_filter($this->players($teamId), fn(array $p) => $match->involves((int)$p['team_id']));
        } else {
            $matchday = Matchday::findOrFail((int)$matchdayId, 'La jornada no existe.');
            $date = (string)$matchday->game_date;
            $enrolled = array_map('intval', array_column(TournamentTeam::find(['tournament_id = :t:', 'bind' => ['t' => (int)$matchday->tournament_id]])->toArray(), 'team_id'));
            $players = array_filter($this->players($teamId), fn(array $p) => !$enrolled || in_array((int)$p['team_id'], $enrolled, true));
        }
        $teams = $this->teamNames();
        $rows = array_map(fn(array $p) => $this->decorate($p, $teams) + ['suspended' => $this->suspensions->isSuspended((int)$p['id'], $date, $matchId ?? 0)], array_values($players));
        usort($rows, fn($a, $b) => strcmp($a['team_name'], $b['team_name']) ?: strcmp($a['name'], $b['name']));
        return $rows;
    }

    private function scorers(?int $teamId, ?int $tournamentId, ?int $matchdayId): array {
        $historicWhere = [];
        $matchWhere = [];
        $historicBind = [];
        $matchBind = [];
        if ($tournamentId) {
            $historicWhere[] = 'matchday_id IN (SELECT id FROM matchdays WHERE tournament_id = ?)';
            $historicBind[] = $tournamentId;
            $matchWhere[] = 'm.tournament_id = ?';
            $matchBind[] = $tournamentId;
        }
        if ($matchdayId) {
            $historicWhere[] = 'matchday_id = ?';
            $historicBind[] = $matchdayId;
            $matchWhere[] = 'm.matchday_id = ?';
            $matchBind[] = $matchdayId;
        }
        $historic = $this->totals('SELECT player_id AS id, SUM(goals) AS total FROM goals' . ($historicWhere ? ' WHERE ' . implode(' AND ', $historicWhere) : '') . ' GROUP BY player_id', $historicBind);
        $sheets = $this->totals('SELECT g.player_id AS id, SUM(g.goals) AS total FROM tournament_match_goals g JOIN tournament_matches m ON m.id = g.tournament_match_id' . ($matchWhere ? ' WHERE ' . implode(' AND ', $matchWhere) : '') . ' GROUP BY g.player_id', $matchBind);
        $teams = $this->teamNames();
        $rows = array_map(fn(array $p) => $this->decorate($p, $teams) + ['total_goals' => ($historic[(int)$p['id']] ?? 0) + ($sheets[(int)$p['id']] ?? 0)], $this->players($teamId));
        usort($rows, fn($a, $b) => ($b['total_goals'] <=> $a['total_goals']) ?: strcmp($a['name'], $b['name']));
        return $rows;
    }

    private function incidents(array $filters, ?int $teamId): array {
        $v = new Validator($filters);
        $playerId = $v->integer('player_id', 1, PHP_INT_MAX, required: false);
        $card = $v->choice('card_type', ['yellow', 'red'], 'Filtro de tarjeta inválido.', required: false);
        $from = $v->date('date_from', required: false);
        $to = $v->date('date_to', required: false);
        if ($from && $to && $from > $to) $v->fail('date_to', 'La fecha final debe ser posterior a la inicial.');
        $v->assertValid('Revise los filtros del reporte.');
        $players = [];
        $teams = $this->teamNames();
        foreach ($this->players($teamId) as $player) $players[(int)$player['id']] = $this->decorate($player, $teams);
        $rows = [];
        foreach (Incident::find(['order' => 'incident_date DESC, id DESC']) as $incident) {
            $player = $players[(int)$incident->player_id] ?? null;
            if (!$player || ($playerId && (int)$incident->player_id !== $playerId) || ($card && $incident->card_type !== $card)
                || ($from && $incident->incident_date < $from) || ($to && $incident->incident_date > $to)) continue;
            $rows[] = $incident->toArray() + ['player_name' => $player['name'], 'team_name' => $player['team_name']];
        }
        return $rows;
    }

    /** @return array<int, int> total by id from a "SELECT … AS id, … AS total" query */
    private function totals(string $sql, array $bind): array {
        $totals = [];
        foreach ($this->db->fetchAll($sql, Enum::FETCH_ASSOC, $bind) as $row) $totals[(int)$row['id']] = (int)$row['total'];
        return $totals;
    }

    private function players(?int $teamId): array {
        return Player::find($teamId ? ['team_id = :t:', 'bind' => ['t' => $teamId], 'order' => 'id'] : ['order' => 'id'])->toArray();
    }

    /** @return array<int, string> team names by id */
    private function teamNames(): array {
        return array_column(Team::find()->toArray(), 'name', 'id');
    }

    /** @param array<int, string> $teams team names by id */
    private function decorate(array $player, array $teams): array {
        $player['name'] = $player['first_name'] . ' ' . $player['last_name'];
        $player['team_name'] = $teams[$player['team_id']] ?? '';
        $player['age'] = (new \DateTimeImmutable($player['birth_date']))->diff(new \DateTimeImmutable($this->settings['age_reference_date']))->y;
        return $player;
    }
}
