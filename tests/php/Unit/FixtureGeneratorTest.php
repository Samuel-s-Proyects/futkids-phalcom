<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\League\FixtureGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixtureGeneratorTest extends TestCase
{
    public static function sizes(): iterable {
        foreach (range(2, 12) as $teams) foreach ([1, 2] as $rounds) yield "{$teams} equipos, {$rounds} vuelta(s)" => [$teams, $rounds];
    }

    #[DataProvider('sizes')]
    public function testEveryPairMeetsOncePerRoundAndNobodyPlaysTwiceADay(int $teams, int $rounds): void {
        $ids = range(101, 100 + $teams);
        $calendar = FixtureGenerator::generate($ids, $rounds);
        $this->assertCount(FixtureGenerator::totalMatchdays($teams, $rounds), $calendar);
        $this->assertSame($teams % 2 ? $teams * $rounds : ($teams - 1) * $rounds, count($calendar));

        $meetings = [];
        foreach ($calendar as $index => $matchday) {
            $this->assertSame($index + 1, $matchday['matchday']);
            $playing = [];
            foreach ($matchday['pairs'] as $pair) {
                $this->assertNotSame($pair['home'], $pair['away']);
                array_push($playing, $pair['home'], $pair['away']);
                $key = min($pair) . '-' . max($pair);
                $meetings[$key] = ($meetings[$key] ?? 0) + 1;
            }
            $this->assertSame(count($playing), count(array_unique($playing)), 'Nadie juega dos veces en la misma jornada.');
            $expectedResting = $teams % 2 ? 1 : 0;
            $this->assertSame($teams - $expectedResting, count($playing));
            if ($expectedResting) $this->assertNotContains($matchday['resting'], $playing);
            else $this->assertNull($matchday['resting']);
        }
        $this->assertCount($teams * ($teams - 1) / 2, $meetings);
        $this->assertSame([$rounds], array_values(array_unique($meetings)), 'Cada par se enfrenta una vez por vuelta.');
    }

    #[DataProvider('sizes')]
    public function testHomeAndAwayAreBalanced(int $teams, int $rounds): void {
        $calendar = FixtureGenerator::generate(range(1, $teams), $rounds);
        foreach (range(1, $teams) as $team) {
            $sequence = [];
            foreach (array_filter($calendar, fn($m) => $m['leg'] === 1) as $matchday) {
                foreach ($matchday['pairs'] as $pair) {
                    if ($pair['home'] === $team) $sequence[] = 'H';
                    if ($pair['away'] === $team) $sequence[] = 'A';
                }
            }
            $home = count(array_keys($sequence, 'H'));
            $this->assertLessThanOrEqual(1, abs($home - (count($sequence) - $home)), "Equipo {$team}: local y visitante equilibrados.");
            $this->assertStringNotContainsString('HHH', implode('', $sequence), "Equipo {$team}: no más de dos jornadas seguidas de local.");
            $this->assertStringNotContainsString('AAA', implode('', $sequence), "Equipo {$team}: no más de dos jornadas seguidas de visitante.");
        }
    }

    public function testSecondRoundSwapsHomeAndAway(): void {
        $calendar = FixtureGenerator::generate([1, 2, 3, 4], 2);
        foreach (range(0, 2) as $index) {
            $first = $calendar[$index]['pairs'];
            $second = $calendar[$index + 3]['pairs'];
            $this->assertSame(array_map(fn($p) => [$p['away'], $p['home']], $first), array_map(fn($p) => [$p['home'], $p['away']], $second));
            $this->assertSame(2, $calendar[$index + 3]['leg']);
        }
    }

    public function testOddTeamsEachRestOncePerRound(): void {
        $calendar = FixtureGenerator::generate([1, 2, 3, 4, 5], 1);
        $resting = array_column($calendar, 'resting');
        sort($resting);
        $this->assertSame([1, 2, 3, 4, 5], $resting);
    }
}
