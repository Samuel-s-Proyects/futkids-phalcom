<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\Knockout\BracketBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BracketBuilderTest extends TestCase
{
    public function testStandardSeedOrder(): void {
        $this->assertSame([1, 2], BracketBuilder::seedPositions(2));
        $this->assertSame([1, 4, 2, 3], BracketBuilder::seedPositions(4));
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], BracketBuilder::seedPositions(8));
    }

    public static function teamCounts(): iterable {
        foreach (range(2, 17) as $teams) yield "{$teams} equipos" => [$teams];
    }

    #[DataProvider('teamCounts')]
    public function testEveryTeamAppearsOnceAndByesGoToTopSeeds(int $teams): void {
        $ids = range(101, 100 + $teams);
        $pairs = BracketBuilder::firstRound($ids);
        $size = BracketBuilder::size($teams);
        $this->assertCount($size / 2, $pairs);
        $placed = [];
        $byeTeams = [];
        foreach ($pairs as $pair) {
            $this->assertFalse($pair['home'] === null && $pair['away'] === null, 'Ningún partido queda vacío.');
            foreach (['home', 'away'] as $side) if ($pair[$side] !== null) $placed[] = $pair[$side];
            if ($pair['home'] === null || $pair['away'] === null) $byeTeams[] = $pair['home'] ?? $pair['away'];
        }
        sort($placed);
        $this->assertSame($ids, $placed);
        sort($byeTeams);
        $this->assertSame(array_slice($ids, 0, $size - $teams), $byeTeams, 'Los byes corresponden a los mejores sembrados.');
    }

    public function testTopSeedsCanOnlyMeetInTheFinal(): void {
        $pairs = BracketBuilder::firstRound(range(1, 8));
        $topHalf = array_merge(...array_map(fn($p) => [$p['home'], $p['away']], array_slice($pairs, 0, 2)));
        $this->assertContains(1, $topHalf);
        $this->assertNotContains(2, $topHalf);
    }

    public function testNavigationHelpers(): void {
        $this->assertSame(['match_number' => 2, 'side' => 'home_team_id'], BracketBuilder::nextSlot(3));
        $this->assertSame(['match_number' => 2, 'side' => 'away_team_id'], BracketBuilder::nextSlot(4));
        $this->assertSame([5, 6], BracketBuilder::feederMatchNumbers(3));
        $this->assertSame(3, BracketBuilder::rounds(5));
        $this->assertSame('Final', BracketBuilder::roundLabel(3, 3));
        $this->assertSame('Semifinales', BracketBuilder::roundLabel(2, 3));
        $this->assertSame('Cuartos de final', BracketBuilder::roundLabel(1, 3));
    }

    public function testRejectsFewerThanTwoTeams(): void {
        $this->expectException(\InvalidArgumentException::class);
        BracketBuilder::size(1);
    }
}
