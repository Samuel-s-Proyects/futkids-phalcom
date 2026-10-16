<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\League\StandingsCalculator;
use PHPUnit\Framework\TestCase;

final class StandingsCalculatorTest extends TestCase
{
    private const POINTS = ['win' => 3, 'draw' => 1, 'loss' => 0];
    private const NAMES = [1 => 'Águilas', 2 => 'Búhos', 3 => 'Cóndores', 4 => 'Delfines'];

    private static function match(int $home, int $away, int $homeScore, int $awayScore): array {
        return ['home_team_id' => $home, 'away_team_id' => $away, 'home_score' => $homeScore, 'away_score' => $awayScore];
    }

    private static function order(array $table): array {
        return array_column($table, 'team_id');
    }

    public function testCountsMatchesGoalsAndPoints(): void {
        $table = StandingsCalculator::calculate([1, 2, 3], [self::match(1, 2, 2, 0), self::match(2, 3, 1, 1), self::match(3, 1, 0, 1)], self::POINTS);
        $first = $table[0];
        $this->assertSame([1, 2, 2, 0, 0, 3, 0, 3, 6, ['G', 'G'], 1], [$first['team_id'], $first['played'], $first['won'], $first['drawn'], $first['lost'], $first['goals_for'], $first['goals_against'], $first['goal_difference'], $first['points'], $first['form'], $first['position']]);
        $this->assertSame([1, 3, 2], self::order($table), 'Empatados a 1 punto: Cóndores tiene mejor diferencia (−1 contra −2).');
    }

    public function testCustomPointValues(): void {
        $table = StandingsCalculator::calculate([1, 2], [self::match(1, 2, 1, 1)], ['win' => 2, 'draw' => 1, 'loss' => 0]);
        $this->assertSame([1, 1], array_column($table, 'points'));
    }

    public function testGoalDifferenceBeatsGoalsFor(): void {
        // 1: +2 (2-0); 2: +1 (5-4). Both 3 points.
        $table = StandingsCalculator::calculate([1, 2, 3, 4], [self::match(1, 3, 2, 0), self::match(2, 4, 5, 4)], self::POINTS);
        $this->assertSame([1, 2], array_slice(self::order($table), 0, 2));
    }

    public function testGoalsForBreaksEqualDifference(): void {
        // 1: 3-2, 2: 1-0 → both +1, 3 points; 1 scored more.
        $table = StandingsCalculator::calculate([1, 2, 3, 4], [self::match(2, 3, 1, 0), self::match(1, 4, 3, 2)], self::POINTS);
        $this->assertSame([1, 2], array_slice(self::order($table), 0, 2));
    }

    public function testHeadToHeadBetweenTwoTeams(): void {
        // 3 and 4 finish equal on points, difference and goals; 4 beat 3.
        $results = [self::match(3, 4, 0, 1), self::match(1, 3, 0, 1), self::match(2, 4, 1, 0), self::match(3, 2, 1, 1), self::match(4, 1, 1, 1)];
        $table = StandingsCalculator::calculate([1, 2, 3, 4], $results, self::POINTS);
        $rows = array_column($table, null, 'team_id');
        $this->assertSame([$rows[3]['points'], $rows[3]['goal_difference'], $rows[3]['goals_for']], [$rows[4]['points'], $rows[4]['goal_difference'], $rows[4]['goals_for']]);
        $this->assertLessThan($rows[3]['position'], $rows[4]['position']);
        $this->assertFalse($rows[4]['tie_unresolved']);
    }

    public function testHeadToHeadAddsBothRounds(): void {
        // 1 and 2: 1 won 1-0 at home, 2 won 3-1 at home → aggregate favours 2.
        $results = [self::match(1, 2, 1, 0), self::match(2, 1, 3, 1), self::match(1, 3, 3, 0), self::match(2, 3, 1, 0)];
        $table = StandingsCalculator::calculate([1, 2, 3], $results, self::POINTS);
        $rows = array_column($table, null, 'team_id');
        $this->assertSame([6, 6], [$rows[1]['points'], $rows[2]['points']]);
        $this->assertSame([2, 2], [$rows[1]['goal_difference'], $rows[2]['goal_difference']]);
        $this->assertSame([5, 4], [$rows[1]['goals_for'], $rows[2]['goals_for']], 'Desempata goles a favor antes que enfrentamiento directo.');
        $this->assertSame([1, 2, 3], self::order($table));
    }

    public function testCircularTieFallsThroughToFairPlay(): void {
        // 1 beats 2, 2 beats 3, 3 beats 1, all 1-0: identical everywhere, including head-to-head.
        $results = [self::match(1, 2, 1, 0), self::match(2, 3, 1, 0), self::match(3, 1, 1, 0)];
        $cards = [1 => ['red' => 1, 'yellow' => 0], 2 => ['red' => 0, 'yellow' => 2], 3 => ['red' => 0, 'yellow' => 1]];
        $table = StandingsCalculator::calculate([1, 2, 3], $results, self::POINTS, $cards, self::NAMES);
        $this->assertSame([3, 2, 1], self::order($table), 'Menos rojas primero, luego menos amarillas.');
        $this->assertSame([false, false, false], array_column($table, 'tie_unresolved'));
    }

    public function testUnresolvedTieIsAlphabeticalAndFlagged(): void {
        $results = [self::match(2, 1, 1, 1)];
        $table = StandingsCalculator::calculate([2, 1], $results, self::POINTS, [], self::NAMES);
        $this->assertSame([1, 2], self::order($table));
        $this->assertSame([true, true], array_column($table, 'tie_unresolved'));
    }

    public function testTeamsWithoutMatchesAppear(): void {
        $table = StandingsCalculator::calculate([1, 2, 3], [], self::POINTS, [], self::NAMES);
        $this->assertSame([0, 0, 0], array_column($table, 'played'));
        $this->assertSame([1, 2, 3], array_column($table, 'position'));
    }

    public function testFormKeepsTheLastFiveResults(): void {
        $results = [];
        foreach ([[1, 0], [0, 1], [1, 1], [2, 0], [0, 0], [3, 1]] as [$for, $against]) $results[] = self::match(1, 2, $for, $against);
        $table = StandingsCalculator::calculate([1, 2], $results, self::POINTS);
        $this->assertSame(['P', 'E', 'G', 'E', 'G'], array_column($table, null, 'team_id')[1]['form']);
    }
}
