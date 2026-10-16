<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

final class BracketGenerationTest extends IntegrationTestCase
{
    private function fiveTeams(): array {
        return [1, 2, 3, 4, (int)$this->catalog->save('teams', ['name' => 'Equipo de prueba', 'active' => 1])['id']];
    }

    public function testBracketRequiresClosedRegistration(): void {
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        $this->tournaments->enroll($id, 1);
        $this->tournaments->enroll($id, 2);
        $this->assertRejected(fn() => $this->tournaments->generateBracket($id), 409);
    }

    public function testFiveTeamsProduceThreeExplicitByesForTopSeeds(): void {
        $bracket = $this->bracket($this->fiveTeams());
        $byes = array_values(array_filter($bracket['matches'], fn($m) => $m['status'] === 'bye'));
        $roundTwo = array_values(array_filter($bracket['matches'], fn($m) => (int)$m['round_number'] === 2));

        $this->assertCount(7, $bracket['matches']);
        $this->assertCount(3, $byes);
        $this->assertCount(2, $roundTwo);
        $this->assertSame([1, 2, 3], array_map('intval', array_column($byes, 'winner_team_id')));
        $this->assertCount(1, array_filter($roundTwo, fn($m) => $m['home_team_id'] && $m['away_team_id']));
    }

    public function testGeneratingStartsTheTournament(): void {
        $bracket = $this->bracket([1, 2, 3, 4]);
        $this->assertSame('in_progress', $bracket['status']);
        $this->assertSame('2026-10-16 09:00:00', $bracket['started_at']);
        $this->assertSame('2026-10-16 09:00:00', $bracket['bracket_generated_at']);
    }

    public function testGenerationIsIdempotent(): void {
        $first = $this->bracket($this->fiveTeams());
        $this->tournaments->generateBracket((int)$first['id']);
        $again = $this->tournaments->detail((int)$first['id']);
        $this->assertSame(array_column($first['matches'], 'id'), array_column($again['matches'], 'id'));
    }

    public function testRandomDrawStoresAFullSeedOrder(): void {
        $id = $this->closedTournament([1, 2, 3, 4], 'random');
        $this->tournaments->generateBracket($id);
        $detail = $this->tournaments->detail($id);
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($detail['participants'], 'seed_order')));
        $this->assertCount(3, $detail['matches']);
    }
}
