<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\Match\Scoreboard;
use PHPUnit\Framework\TestCase;

final class ScoreboardTest extends TestCase
{
    private const HOME = 1;
    private const AWAY = 2;

    private function goal(int $player, int $team, int $period = 0): array {
        return ['player_id' => $player, 'team_id' => $team, 'credited_team_id' => $team, 'own_goal' => 0, 'period_number' => $period];
    }

    private function ownGoal(int $player, int $team, int $period = 0): array {
        return ['player_id' => $player, 'team_id' => $team, 'credited_team_id' => $team === self::HOME ? self::AWAY : self::HOME, 'own_goal' => 1, 'period_number' => $period];
    }

    public function testNoGoalsStillReportsEveryRequestedPeriod(): void {
        $score = Scoreboard::tally([], self::HOME, self::AWAY, 2);
        $this->assertSame([0, 0], [$score['home'], $score['away']]);
        $this->assertCount(3, $score['periods']);
    }

    public function testOwnGoalCountsForTheOpponent(): void {
        $score = Scoreboard::tally([$this->goal(10, self::HOME), $this->ownGoal(11, self::HOME)], self::HOME, self::AWAY);
        $this->assertSame([1, 1], [$score['home'], $score['away']]);
        $this->assertSame(['home_score' => 1, 'away_score' => 1, 'home_own_goals' => 1, 'away_own_goals' => 0], $score['periods'][0]);
    }

    public function testRegulationAndExtraTimeAreSeparated(): void {
        $score = Scoreboard::tally([$this->goal(10, self::HOME), $this->goal(20, self::AWAY), $this->goal(10, self::HOME, 2)], self::HOME, self::AWAY, 2);
        $this->assertSame(['home' => 1, 'away' => 1], $score['regulation']);
        $this->assertSame([2, 1], [$score['home'], $score['away']]);
        $this->assertSame(1, $score['periods'][2]['home_score']);
        $this->assertSame(0, $score['periods'][1]['home_score']);
    }

    public function testScorersExcludeOwnGoals(): void {
        $scorers = Scoreboard::scorers([$this->goal(10, self::HOME), $this->goal(10, self::HOME, 1), $this->ownGoal(11, self::HOME), $this->goal(20, self::AWAY)]);
        $this->assertSame([
            ['player_id' => 10, 'team_id' => self::HOME, 'goals' => 2],
            ['player_id' => 20, 'team_id' => self::AWAY, 'goals' => 1],
        ], $scorers);
    }
}
