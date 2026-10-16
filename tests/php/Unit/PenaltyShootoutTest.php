<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Exceptions\HttpException;
use App\Services\Match\PenaltyShootout;
use PHPUnit\Framework\TestCase;

final class PenaltyShootoutTest extends TestCase
{
    private const HOME = 10;
    private const AWAY = 20;

    /** @param list<int> $results alternating, first kicker first */
    private function kicks(array $results, int $first = self::HOME): array {
        $second = $first === self::HOME ? self::AWAY : self::HOME;
        return array_map(fn($scored, $i) => ['team_id' => $i % 2 ? $second : $first, 'scored' => $scored], $results, array_keys($results));
    }

    private function evaluate(array $results, bool $suddenDeath = true, int $first = self::HOME): array {
        return PenaltyShootout::evaluate($this->kicks($results, $first), $first, self::HOME, self::AWAY, 5, $suddenDeath);
    }

    private function assertInvalid(callable $operation, string $field): void {
        try {
            $operation();
            $this->fail('Se esperaba un rechazo.');
        } catch (HttpException $e) {
            $this->assertArrayHasKey($field, $e->fields);
        }
    }

    public function testDecidedWithinInitialAttempts(): void {
        $this->assertSame(['winner_team_id' => self::HOME, 'home' => 4, 'away' => 3], $this->evaluate([1, 1, 1, 1, 1, 1, 1, 0, 0, 0]));
    }

    public function testEarlyDecisionWhenTheOtherTeamCannotCatchUp(): void {
        $result = $this->evaluate([1, 0, 1, 0, 1, 0]);
        $this->assertSame(self::HOME, $result['winner_team_id']);
        $this->assertSame([3, 0], [$result['home'], $result['away']]);
    }

    public function testSuddenDeathDecidesAfterAPair(): void {
        $result = $this->evaluate([1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 1, 0]);
        $this->assertSame(self::HOME, $result['winner_team_id']);
        $this->assertSame([4, 3], [$result['home'], $result['away']]);
    }

    public function testAwayCanWinWhenKickingFirst(): void {
        $this->assertSame(self::AWAY, $this->evaluate([1, 0, 1, 0, 1, 0], first: self::AWAY)['winner_team_id']);
    }

    public function testTieWithoutSuddenDeathIsRejected(): void {
        $this->assertInvalid(fn() => $this->evaluate([1, 1, 1, 1, 1, 1, 0, 0, 0, 0], suddenDeath: false), 'penalty_kicks');
    }

    public function testIncompleteShootoutIsRejected(): void {
        $this->assertInvalid(fn() => $this->evaluate([1, 1, 0]), 'penalty_kicks');
    }

    public function testKicksAfterTheDecidingOneAreRejected(): void {
        $this->assertInvalid(fn() => $this->evaluate([1, 0, 1, 0, 1, 0, 1]), 'penalty_kicks');
    }

    public function testTeamsMustAlternate(): void {
        $kicks = [['team_id' => self::HOME, 'scored' => 1], ['team_id' => self::HOME, 'scored' => 1]];
        $this->assertInvalid(fn() => PenaltyShootout::evaluate($kicks, self::HOME, self::HOME, self::AWAY, 5, true), 'penalty_kicks');
    }

    public function testFirstKickerMustPlayTheMatch(): void {
        $this->assertInvalid(fn() => PenaltyShootout::evaluate($this->kicks([1]), 99, self::HOME, self::AWAY, 5, true), 'first_kicker_team_id');
    }
}
