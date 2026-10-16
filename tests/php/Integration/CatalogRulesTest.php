<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Teams, players and incidents (generic catalogue), the read-only historic data, and the reports. */
final class CatalogRulesTest extends IntegrationTestCase
{
    public function testSeedDataIsComplete(): void {
        $state = $this->state->state();
        $this->assertCount(4, $state['teams']);
        $this->assertCount(20, $state['players']);
        $this->assertCount(5, $state['matchdays']);
        $this->assertCount(1, $state['tournaments']);
        $historic = $state['tournaments'][0];
        $this->assertSame(['league', 'finished', 1], [$historic['type'], $historic['status'], (int)$historic['is_legacy']]);
        $this->assertSame([(int)$historic['id']], array_values(array_unique(array_map('intval', array_column($state['matchdays'], 'tournament_id')))));
        $this->assertSame([], $state['tournament_matches']);
    }

    public function testHistoricMatchdaysAndGoalsAreReadOnly(): void {
        $this->assertRejected(fn() => $this->catalog->save('goals', ['player_id' => 1, 'matchday_id' => 1, 'goals' => 2]), 404);
        $this->assertRejected(fn() => $this->catalog->save('matchdays', ['number' => 6, 'game_date' => '2026-11-07']), 404);
        $this->assertRejected(fn() => $this->catalog->delete('matchdays', 3), 404);
    }

    public function testRefereeReportShowsSuspensionsOnlyOnTheirDate(): void {
        $suspended = fn(int $matchday) => count(array_filter($this->reports->report('referee', ['matchday_id' => $matchday])['rows'], fn($p) => $p['suspended']));
        $this->assertSame(3, $suspended(3));
        $this->assertSame(0, $suspended(4));
    }

    public function testScorersAreTotalledAndOrdered(): void {
        $scores = $this->reports->report('scorers', [])['rows'];
        $this->assertSame(10, $scores[0]['total_goals']);
        $this->assertGreaterThanOrEqual(5, count(array_unique(array_column($scores, 'total_goals'))));
    }

    public function testIncidentReportFilters(): void {
        $this->assertCount(1, $this->reports->report('incidents', ['team_id' => 1, 'card_type' => 'red'])['rows']);
        $this->assertCount(1, $this->reports->report('incidents', ['team_id' => 1, 'player_id' => 4])['rows']);
        $this->assertCount(5, $this->reports->report('incidents', ['date_from' => '2026-10-10', 'date_to' => '2026-10-10'])['rows']);
        $this->assertRejected(fn() => $this->reports->report('incidents', ['date_from' => '2026-11-01', 'date_to' => '2026-10-01']));
    }

    public function testPlayerFileIncludesHistory(): void {
        $player = $this->reports->player(2);
        $this->assertTrue($player['history'][2]['suspended']);
        $this->assertSame(9, $player['total_goals']);
    }

    public function testIncidentRules(): void {
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-10']));
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-10', 'suspension_date' => '2026-10-03']));
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-03', 'suspension_date' => '2026-10-10']), 409);
    }

    public function testYellowCardNeverSuspends(): void {
        $yellow = $this->catalog->save('incidents', ['player_id' => 5, 'description' => 'Prueba de amarilla', 'card_type' => 'yellow', 'incident_date' => '2026-10-10', 'suspension_date' => '2026-10-11']);
        $this->assertNull($yellow['suspension_date']);
    }

    public function testTeamNamesAreUniqueIgnoringCase(): void {
        $this->assertRejected(fn() => $this->catalog->save('teams', ['name' => 'LEONES DE PETAPA', 'active' => 1]), 422, 'name');
    }

    public function testRecordsWithHistoryCannotBeDeleted(): void {
        $this->assertRejected(fn() => $this->catalog->delete('teams', 1), 409);
        $this->assertRejected(fn() => $this->catalog->delete('players', 1), 409);
    }

    public function testPlayerRules(): void {
        $base = ['first_name' => 'Juan', 'last_name' => 'Pérez', 'birth_date' => '2015-04-02', 'team_id' => 1];
        $this->assertRejected(fn() => $this->catalog->save('players', ['first_name' => 'Juan1'] + $base), 422, 'first_name');
        $this->assertRejected(fn() => $this->catalog->save('players', ['birth_date' => '2000-04-02'] + $base), 422, 'birth_date');
        $this->assertRejected(fn() => $this->catalog->save('players', ['birth_date' => '2026-10-17'] + $base), 422, 'birth_date');
        $player = $this->state->state()['players'][0];
        $player['team_id'] = 2;
        $this->assertRejected(fn() => $this->catalog->save('players', $player, 1), 422, 'team_id');
    }

    public function testShirtNumbersAreUniquePerTeam(): void {
        $taken = $this->playersOf(1)[0];
        $base = ['first_name' => 'Nuevo', 'last_name' => 'Jugador', 'birth_date' => '2015-04-02', 'team_id' => 1];
        $this->assertRejected(fn() => $this->catalog->save('players', ['shirt_number' => (int)$taken['shirt_number']] + $base), 422, 'shirt_number');
        $this->assertRejected(fn() => $this->catalog->save('players', ['shirt_number' => '7a'] + $base), 422, 'shirt_number');
        $this->assertSame(99, (int)$this->catalog->save('players', ['shirt_number' => 99] + $base)['shirt_number']);
    }

    public function testPlayerPositionMustComeFromTheList(): void {
        $base = ['first_name' => 'Nuevo', 'last_name' => 'Jugador', 'birth_date' => '2015-04-02', 'team_id' => 1];
        $this->assertRejected(fn() => $this->catalog->save('players', ['position' => 'Líbero <b>'] + $base), 422, 'position');
    }

    public function testTextIsNormalisedAndSpecialCharactersRejected(): void {
        $team = $this->catalog->save('teams', ['name' => '  Club   Atlético  Ñandú ', 'active' => 1]);
        $this->assertSame('Club Atlético Ñandú', $team['name']);
        $this->assertRejected(fn() => $this->catalog->save('teams', ['name' => 'Equipo #1', 'active' => 1]), 422, 'name');
        $this->assertRejected(fn() => $this->catalog->save('teams', ['name' => 'Leones de Pétapa', 'active' => 1]), 422, 'name');
    }

    public function testIncidentCannotBeDatedInTheFuture(): void {
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => 5, 'description' => 'Prueba', 'card_type' => 'yellow', 'incident_date' => '2026-10-17']), 422, 'incident_date');
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => 5, 'description' => 'Ver <script>', 'card_type' => 'yellow', 'incident_date' => '2026-10-16']), 422, 'description');
    }

    public function testPlayerCrudWithPhotoFallback(): void {
        $team = $this->catalog->save('teams', ['name' => 'Equipo de prueba', 'active' => 1]);
        $data = ['first_name' => 'Jean-Luc', 'last_name' => "D'Ávila", 'birth_date' => '2015-04-02', 'team_id' => $team['id'], 'shirt_number' => 10, 'position' => 'Delantero'];
        $player = $this->catalog->save('players', $data);
        $this->assertSame('images/player-placeholder.svg', $player['photo_path']);

        $photoPath = 'uploads/players/' . str_repeat('a', 32) . '.jpg';
        $player = $this->catalog->save('players', $data, (int)$player['id'], $photoPath);
        $this->assertSame($photoPath, $player['photo_path']);
        $player = $this->catalog->save('players', $player, (int)$player['id'], removePhoto: true);
        $this->assertSame('images/player-placeholder.svg', $player['photo_path']);

        $this->catalog->delete('players', (int)$player['id']);
        $this->catalog->delete('teams', (int)$team['id']);
        $this->assertCount(20, $this->state->state()['players']);
    }

    public function testScorersCanBeLimitedToATournament(): void {
        $historicId = (int)$this->state->state()['tournaments'][0]['id'];
        $historic = $this->reports->report('scorers', ['tournament_id' => $historicId])['rows'];
        $this->assertSame(10, $historic[0]['total_goals']);

        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $scorer = $this->playersOf(2)[0];
        $this->play((int)$detail['id'], (int)$detail['matches'][0]['id'], '2026-10-20T10:00', [self::goal($scorer, 5), self::goal($scorer, 9)]);
        $rows = $this->reports->report('scorers', ['tournament_id' => $detail['id']])['rows'];
        $this->assertSame([(int)$scorer['id'], 2], [(int)$rows[0]['id'], $rows[0]['total_goals']]);
        $this->assertSame(0, $rows[1]['total_goals'], 'Solo cuentan los goles de ese torneo.');
        $everything = array_column($this->reports->report('scorers', [])['rows'], 'total_goals', 'id');
        $this->assertSame($this->reports->player((int)$scorer['id'])['total_goals'], $everything[(int)$scorer['id']]);
    }

    public function testRefereeListForAMatchCoversBothTeamsOnItsDate(): void {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $matchId = (int)$detail['matches'][0]['id'];
        $this->assertRejected(fn() => $this->reports->report('referee', ['match_id' => $matchId]), 409, 'match_id');
        $this->schedule((int)$detail['id'], $matchId, '2026-10-20T10:00');
        $suspended = $this->playersOf(1)[0];
        $this->catalog->save('incidents', ['player_id' => (int)$suspended['id'], 'description' => 'Expulsión', 'card_type' => 'red', 'incident_date' => '2026-10-16', 'suspension_date' => '2026-10-20']);
        $rows = $this->reports->report('referee', ['match_id' => $matchId])['rows'];
        $this->assertCount(10, $rows);
        $this->assertSame([(int)$suspended['id']], array_values(array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['suspended']))));
        $this->assertRejected(fn() => $this->reports->report('referee', []), 422, 'match_id');
    }

    public function testInactiveTeamReceivesNoNewPlayers(): void {
        $team = $this->catalog->save('teams', ['name' => 'Equipo inactivo', 'active' => 0]);
        $this->assertRejected(fn() => $this->catalog->save('players', ['first_name' => 'Nuevo', 'last_name' => 'Jugador', 'birth_date' => '2015-04-02', 'team_id' => $team['id']]));
    }
}
