<?php
declare(strict_types=1);
namespace App\Services\League;

/**
 * Round-robin calendar (circle method). Every team plays every other team once per round ("vuelta").
 *
 * With an odd number of teams one team rests on each matchday. Matchdays per round: N − 1 for an even
 * number of teams, N for an odd number. The second round repeats the first with home and away swapped.
 * Home and away alternate so that no team plays more than two consecutive matchdays at home or away.
 */
final class FixtureGenerator
{
    public static function matchdaysPerRound(int $teams): int {
        if ($teams < 2) throw new \InvalidArgumentException('Se requieren al menos dos equipos.');
        return $teams % 2 === 0 ? $teams - 1 : $teams;
    }

    public static function totalMatchdays(int $teams, int $rounds): int {
        return self::matchdaysPerRound($teams) * $rounds;
    }

    /**
     * @param list<int> $teamIds in seed order
     * @return list<array{matchday: int, leg: int, pairs: list<array{home: int, away: int}>, resting: ?int}>
     */
    public static function generate(array $teamIds, int $rounds): array {
        if (!in_array($rounds, [1, 2], true)) throw new \InvalidArgumentException('Una o dos vueltas.');
        $slots = array_values($teamIds);
        self::matchdaysPerRound(count($slots));
        if (count($slots) % 2) $slots[] = null; // the team paired with this empty slot rests
        $size = count($slots);
        $fixed = $size - 1;
        $polygon = $size - 1;

        $firstLeg = [];
        for ($round = 0; $round < $size - 1; $round++) {
            $pairs = [];
            $resting = null;
            for ($i = 0; $i < $size / 2; $i++) {
                // Polygon construction: slot `fixed` stays in the centre, the rest rotate around it.
                [$a, $b] = $i === 0
                    ? [$round, $fixed]
                    : [($round + $i) % $polygon, ($round - $i + $polygon) % $polygon];
                [$teamA, $teamB] = [$slots[$a], $slots[$b]];
                if ($teamA === null || $teamB === null) {
                    $resting = $teamA ?? $teamB;
                    continue;
                }
                // Home side: the centre team alternates every matchday; on the other chords it is set by
                // the chord's parity. This keeps home and away within one of each other per round and never
                // gives a team three consecutive home (or away) matchdays.
                $aIsHome = $i === 0 ? $round % 2 === 0 : $i % 2 === 1;
                $pairs[] = $aIsHome ? ['home' => $teamA, 'away' => $teamB] : ['home' => $teamB, 'away' => $teamA];
            }
            $firstLeg[] = ['pairs' => $pairs, 'resting' => $resting];
        }

        $calendar = [];
        foreach ($firstLeg as $index => $matchday) {
            $calendar[] = ['matchday' => $index + 1, 'leg' => 1] + $matchday;
        }
        if ($rounds === 2) {
            foreach ($firstLeg as $index => $matchday) {
                $calendar[] = [
                    'matchday' => count($firstLeg) + $index + 1,
                    'leg' => 2,
                    'pairs' => array_map(fn(array $pair) => ['home' => $pair['away'], 'away' => $pair['home']], $matchday['pairs']),
                    'resting' => $matchday['resting'],
                ];
            }
        }
        return $calendar;
    }
}
