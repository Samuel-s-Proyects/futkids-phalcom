<?php
declare(strict_types=1);
namespace App\Services\Knockout;

/**
 * Pure single-elimination layout. Seeds are placed in standard bracket order (1 v 8, 4 v 5, 2 v 7, 3 v 6 …)
 * in a bracket padded to the next power of two, so byes go to the best seeds and top seeds meet last.
 */
final class BracketBuilder
{
    /** @return int bracket size (power of two, at least 2) */
    public static function size(int $teams): int {
        if ($teams < 2) throw new \InvalidArgumentException('Se requieren al menos dos equipos.');
        $size = 2;
        while ($size < $teams) $size *= 2;
        return $size;
    }

    public static function rounds(int $teams): int {
        return (int)log(self::size($teams), 2);
    }

    /** @return list<int> seed number for each slot, e.g. size 4 → [1, 4, 2, 3] */
    public static function seedPositions(int $size): array {
        $seeds = [1, 2];
        for ($round = 4; $round <= $size; $round *= 2) {
            $next = [];
            foreach ($seeds as $seed) array_push($next, $seed, $round + 1 - $seed);
            $seeds = $next;
        }
        return $seeds;
    }

    /**
     * First-round pairings for team ids ordered by seed. A null side means a bye.
     * @param list<int> $teamIdsBySeed
     * @return list<array{match_number: int, home: ?int, away: ?int}>
     */
    public static function firstRound(array $teamIdsBySeed): array {
        $positions = self::seedPositions(self::size(count($teamIdsBySeed)));
        $pairs = [];
        for ($slot = 0; $slot < count($positions); $slot += 2) {
            $pairs[] = [
                'match_number' => intdiv($slot, 2) + 1,
                'home' => $teamIdsBySeed[$positions[$slot] - 1] ?? null,
                'away' => $teamIdsBySeed[$positions[$slot + 1] - 1] ?? null,
            ];
        }
        return $pairs;
    }

    /** Where the winner of (round, match) plays next. */
    public static function nextSlot(int $matchNumber): array {
        return ['match_number' => (int)ceil($matchNumber / 2), 'side' => $matchNumber % 2 ? 'home_team_id' : 'away_team_id'];
    }

    /** The two matches of the previous round that feed (round, match). */
    public static function feederMatchNumbers(int $matchNumber): array {
        return [$matchNumber * 2 - 1, $matchNumber * 2];
    }

    /** Spanish label for a round given the total number of rounds. */
    public static function roundLabel(int $round, int $totalRounds): string {
        return match ($totalRounds - $round) {
            0 => 'Final',
            1 => 'Semifinales',
            2 => 'Cuartos de final',
            3 => 'Octavos de final',
            default => "Ronda {$round}",
        };
    }
}
