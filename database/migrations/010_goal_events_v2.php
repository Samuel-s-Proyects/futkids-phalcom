<?php
declare(strict_types=1);

use App\Support\Clock;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * Goal events become the single source of a match score:
 *  1. each event records the team it counts for (the opponent on an own goal);
 *  2. events duplicated by the old flow (a live goal later re-sent with the full result) are removed,
 *     oldest first, and every removal is written to the match audit;
 *  3. each event is assigned to its period (regulation or extra time) consistently with the stored
 *     period scores, dealing events in minute order.
 * Matches whose stored data cannot be reconciled are left untouched. Safe to run more than once.
 */
return static function (AdapterInterface $db, string $driver, Clock $clock): void {
    /**
     * Deals events (already in minute order) into the earliest period whose score still has room for the team.
     * @param array<int, array<int, int>> $quotas period number => [team id => goals]
     * @return array<int, int>|null event id => period number, or null when the scores do not match the events
     */
    $assignPeriods = static function (array $events, array $quotas): ?array {
        $assignments = [];
        foreach ($events as $event) {
            $team = (int)$event['credited_team_id'];
            $period = null;
            foreach ($quotas as $number => $quota) {
                if (($quota[$team] ?? 0) > 0) {
                    $period = $number;
                    break;
                }
            }
            if ($period === null) return null;
            $quotas[$period][$team]--;
            $assignments[(int)$event['id']] = $period;
        }
        return $assignments;
    };

    $db->execute(
        'UPDATE tournament_match_goal_events SET credited_team_id = CASE
            WHEN own_goal = 0 THEN team_id
            ELSE (SELECT CASE WHEN m.home_team_id = tournament_match_goal_events.team_id THEN m.away_team_id ELSE m.home_team_id END
                  FROM tournament_matches m WHERE m.id = tournament_match_goal_events.tournament_match_id)
         END
         WHERE credited_team_id IS NULL'
    );

    $auditor = $db->fetchOne("SELECT MIN(id) AS id FROM users WHERE role = 'admin'", Enum::FETCH_ASSOC)['id'] ?? null;
    $matches = $db->fetchAll(
        "SELECT id, home_team_id, away_team_id, result_revision FROM tournament_matches WHERE status = 'completed'",
        Enum::FETCH_ASSOC,
    );
    foreach ($matches as $match) {
        $matchId = (int)$match['id'];
        $home = (int)$match['home_team_id'];
        $periods = $db->fetchAll(
            'SELECT period_number, home_score, away_score, home_own_goals, away_own_goals FROM tournament_match_period_scores WHERE tournament_match_id = ? ORDER BY period_number',
            Enum::FETCH_ASSOC,
            [$matchId],
        );
        if (!$periods) continue;

        // Expected number of events per (player, own goal) for player goals, per (team, own goal) for own goals.
        $expected = [];
        foreach ($db->fetchAll('SELECT player_id, goals FROM tournament_match_goals WHERE tournament_match_id = ?', Enum::FETCH_ASSOC, [$matchId]) as $row) {
            $expected['player:' . (int)$row['player_id']] = (int)$row['goals'];
        }
        $expected['own:' . $home] = array_sum(array_map('intval', array_column($periods, 'home_own_goals')));
        $expected['own:' . (int)$match['away_team_id']] = array_sum(array_map('intval', array_column($periods, 'away_own_goals')));

        $events = $db->fetchAll('SELECT * FROM tournament_match_goal_events WHERE tournament_match_id = ? ORDER BY id', Enum::FETCH_ASSOC, [$matchId]);
        $groups = [];
        foreach ($events as $event) {
            $groups[(int)$event['own_goal'] ? 'own:' . (int)$event['team_id'] : 'player:' . (int)$event['player_id']][] = $event;
        }
        $removed = [];
        foreach ($groups as $key => $group) {
            $surplus = count($group) - ($expected[$key] ?? 0);
            if ($surplus > 0) array_push($removed, ...array_slice($group, 0, $surplus));
        }
        if ($removed && $auditor !== null) {
            foreach ($removed as $event) $db->execute('DELETE FROM tournament_match_goal_events WHERE id = ?', [(int)$event['id']]);
            $db->insertAsDict('tournament_match_result_audit', [
                'tournament_match_id' => $matchId,
                'actor_user_id' => (int)$auditor,
                'action' => 'migration_dedupe',
                'revision' => (int)$match['result_revision'],
                'audit_snapshot' => json_encode(['removed_events' => $removed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        if (count($periods) < 2) continue;
        $quotas = [];
        foreach ($periods as $period) {
            $quotas[(int)$period['period_number']] = [$home => (int)$period['home_score'], (int)$match['away_team_id'] => (int)$period['away_score']];
        }
        $events = $db->fetchAll('SELECT id, credited_team_id FROM tournament_match_goal_events WHERE tournament_match_id = ? ORDER BY minute, id', Enum::FETCH_ASSOC, [$matchId]);
        foreach ($assignPeriods($events, $quotas) ?? [] as $eventId => $number) {
            $db->execute('UPDATE tournament_match_goal_events SET period_number = ? WHERE id = ?', [$number, $eventId]);
        }
    }
};
