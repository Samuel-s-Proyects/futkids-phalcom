<?php
declare(strict_types=1);
namespace App\Services\Match;

/**
 * Pure score arithmetic: the score of a match is derived from its goal events, never typed separately.
 *
 * Each event: player_id, team_id (player's team), credited_team_id (who the goal counts for),
 * own_goal (0|1) and period_number (0 = regulation, 1..n = extra-time periods).
 */
final class Scoreboard
{
    /**
     * @param list<array{player_id: int, team_id: int, credited_team_id: int, own_goal: int, period_number: int}> $events
     * @param int $lastPeriod highest period to report (rows are produced for 0..$lastPeriod even without goals)
     * @return array{home: int, away: int, regulation: array{home: int, away: int}, periods: array<int, array{home_score: int, away_score: int, home_own_goals: int, away_own_goals: int}>}
     */
    public static function tally(array $events, int $homeTeamId, int $awayTeamId, int $lastPeriod = 0): array {
        $lastPeriod = max($lastPeriod, ...array_map(fn(array $event) => $event['period_number'], $events ?: [['period_number' => 0]]));
        $periods = array_fill(0, $lastPeriod + 1, ['home_score' => 0, 'away_score' => 0, 'home_own_goals' => 0, 'away_own_goals' => 0]);
        foreach ($events as $event) {
            $period = &$periods[$event['period_number']];
            $period[$event['credited_team_id'] === $homeTeamId ? 'home_score' : 'away_score']++;
            if ($event['own_goal']) $period[$event['team_id'] === $homeTeamId ? 'home_own_goals' : 'away_own_goals']++;
            unset($period);
        }
        return [
            'home' => array_sum(array_column($periods, 'home_score')),
            'away' => array_sum(array_column($periods, 'away_score')),
            'regulation' => ['home' => $periods[0]['home_score'], 'away' => $periods[0]['away_score']],
            'periods' => $periods,
        ];
    }

    /**
     * Goals per scorer (own goals are not credited to anyone's personal tally).
     * @return list<array{player_id: int, team_id: int, goals: int}>
     */
    public static function scorers(array $events): array {
        $totals = [];
        foreach ($events as $event) {
            if ($event['own_goal']) continue;
            $totals[$event['player_id']] ??= ['player_id' => $event['player_id'], 'team_id' => $event['team_id'], 'goals' => 0];
            $totals[$event['player_id']]['goals']++;
        }
        ksort($totals);
        return array_values($totals);
    }
}
