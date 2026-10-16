<?php
declare(strict_types=1);
namespace App\Services\League;

use App\Support\TextNormalizer;

/**
 * League table from confirmed results.
 *
 * Order: 1. points; 2. goal difference; 3. goals for; 4. head-to-head among the teams still level
 * (points, then goal difference, then goals for, counting only the matches between those teams, both
 * rounds); 5. fair play (fewer red cards, then fewer yellow cards); 6. alphabetical, flagged as an
 * unresolved tie. Head-to-head is applied once to each level group, not recursively.
 */
final class StandingsCalculator
{
    /**
     * @param list<int> $teamIds participants
     * @param list<array{home_team_id: int, away_team_id: int, home_score: int, away_score: int}> $results confirmed matches in date order
     * @param array{win: int, draw: int, loss: int} $points
     * @param array<int, array{red: int, yellow: int}> $cards by team
     * @param array<int, string> $names by team, for the final alphabetical order
     * @return list<array{position: int, team_id: int, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int, form: list<string>, red_cards: int, yellow_cards: int, tie_unresolved: bool}>
     */
    public static function calculate(array $teamIds, array $results, array $points, array $cards = [], array $names = []): array {
        $table = self::tally($teamIds, $results, $points);
        foreach ($table as $teamId => &$row) {
            $row['red_cards'] = $cards[$teamId]['red'] ?? 0;
            $row['yellow_cards'] = $cards[$teamId]['yellow'] ?? 0;
            $row['tie_unresolved'] = false;
        }
        unset($row);

        $rows = array_values($table);
        usort($rows, fn($a, $b) => self::compare($a, $b, ['points', 'goal_difference', 'goals_for']));
        $ordered = [];
        foreach (self::groups($rows, ['points', 'goal_difference', 'goals_for']) as $group) {
            array_push($ordered, ...(count($group) > 1 ? self::breakTie($group, $results, $points, $names) : $group));
        }
        foreach ($ordered as $index => &$row) $row['position'] = $index + 1;
        unset($row);
        return $ordered;
    }

    /** @return array<int, array> rows keyed by team id */
    private static function tally(array $teamIds, array $results, array $points): array {
        $table = [];
        foreach ($teamIds as $teamId) {
            $table[$teamId] = ['team_id' => $teamId, 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0, 'form' => []];
        }
        foreach ($results as $result) {
            $sides = [
                [(int)$result['home_team_id'], (int)$result['home_score'], (int)$result['away_score']],
                [(int)$result['away_team_id'], (int)$result['away_score'], (int)$result['home_score']],
            ];
            foreach ($sides as [$teamId, $for, $against]) {
                if (!isset($table[$teamId])) continue;
                $row = &$table[$teamId];
                $outcome = $for <=> $against;
                $row['played']++;
                $row['goals_for'] += $for;
                $row['goals_against'] += $against;
                $row['goal_difference'] = $row['goals_for'] - $row['goals_against'];
                $row[[1 => 'won', 0 => 'drawn', -1 => 'lost'][$outcome]]++;
                $row['points'] += [1 => $points['win'], 0 => $points['draw'], -1 => $points['loss']][$outcome];
                $row['form'] = array_slice([...$row['form'], [1 => 'G', 0 => 'E', -1 => 'P'][$outcome]], -5);
                unset($row);
            }
        }
        return $table;
    }

    /** Head-to-head mini table, then fair play, then name. */
    private static function breakTie(array $group, array $results, array $points, array $names): array {
        $ids = array_column($group, 'team_id');
        $between = array_values(array_filter($results, fn($r) => in_array((int)$r['home_team_id'], $ids, true) && in_array((int)$r['away_team_id'], $ids, true)));
        $mini = self::tally($ids, $between, $points);
        foreach ($group as &$row) {
            $row['h2h'] = [$mini[$row['team_id']]['points'], $mini[$row['team_id']]['goal_difference'], $mini[$row['team_id']]['goals_for']];
            $row['fair_play'] = [-$row['red_cards'], -$row['yellow_cards']];
        }
        unset($row);
        $decisive = fn($a, $b) => ($b['h2h'] <=> $a['h2h']) ?: ($b['fair_play'] <=> $a['fair_play']);
        $name = fn(array $row) => TextNormalizer::key($names[$row['team_id']] ?? '');
        usort($group, fn($a, $b) => $decisive($a, $b) ?: strcmp($name($a), $name($b)) ?: $a['team_id'] <=> $b['team_id']);
        foreach ($group as $index => &$row) {
            $tiedWithPrevious = $index > 0 && $decisive($group[$index - 1], $row) === 0;
            $tiedWithNext = isset($group[$index + 1]) && $decisive($row, $group[$index + 1]) === 0;
            $row['tie_unresolved'] = $tiedWithPrevious || $tiedWithNext;
        }
        unset($row);
        return array_map(function (array $row) {
            unset($row['h2h'], $row['fair_play']);
            return $row;
        }, $group);
    }

    /** @return list<list<array>> consecutive rows equal on the given keys */
    private static function groups(array $rows, array $keys): array {
        $groups = [];
        foreach ($rows as $row) {
            $last = array_key_last($groups);
            if ($last !== null && self::compare($groups[$last][0], $row, $keys) === 0) $groups[$last][] = $row;
            else $groups[] = [$row];
        }
        return $groups;
    }

    private static function compare(array $a, array $b, array $keys): int {
        foreach ($keys as $key) if ($a[$key] !== $b[$key]) return $b[$key] <=> $a[$key];
        return 0;
    }
}
