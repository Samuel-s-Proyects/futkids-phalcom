<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Points tournaments: calendar, draws, table, postponements and the champion. */
final class LeagueLifecycleTest extends IntegrationTestCase
{
    private function fifthTeam(): int {
        return (int)$this->catalog->save('teams', ['name' => 'Equipo de prueba', 'active' => 1])['id'];
    }

    private function leagueInput(array $overrides = []): array {
        return $overrides + ['name' => 'Liga de prueba', 'type' => 'league', 'start_date' => '2026-10-16', 'end_date' => '2027-06-30', 'league_rounds' => 2, 'points_win' => 3, 'points_draw' => 1, 'points_loss' => 0];
    }

    private function matchesOf(array $detail, int $matchdayNumber): array {
        $matchday = current(array_filter($detail['matchdays'], fn($m) => (int)$m['number'] === $matchdayNumber));
        return array_values(array_filter($detail['matches'], fn($m) => (int)$m['matchday_id'] === (int)$matchday['id']));
    }

    public function testLeagueSettingsAreValidated(): void {
        $league = $this->tournaments->save($this->leagueInput());
        $this->assertSame(['league', 2, 3, 1, 0, 0], [$league['type'], (int)$league['league_rounds'], (int)$league['points_win'], (int)$league['points_draw'], (int)$league['points_loss'], (int)$league['extra_time_periods']]);
        $this->assertRejected(fn() => $this->tournaments->save($this->leagueInput(['name' => 'Otra', 'league_rounds' => 3])), 422, 'league_rounds');
        $this->assertRejected(fn() => $this->tournaments->save($this->leagueInput(['name' => 'Otra', 'points_win' => 1, 'points_draw' => 1])), 422, 'points_win');
        $this->assertRejected(fn() => $this->tournaments->save($this->leagueInput(['name' => 'Otra', 'points_draw' => 0, 'points_loss' => 1])), 422, 'points_draw');
        $this->assertRejected(fn() => $this->tournaments->save($this->leagueInput(['name' => 'Otra', 'points_win' => '11'])), 422, 'points_win');
    }

    public function testLeagueNeedsThreeTeams(): void {
        $id = (int)$this->tournaments->save($this->leagueInput())['id'];
        $this->tournaments->openRegistration($id);
        $this->tournaments->enroll($id, 1);
        $this->tournaments->enroll($id, 2);
        $this->assertRejected(fn() => $this->tournaments->closeRegistration($id, 'manual'), 409);
    }

    public function testFiveTeamsTwoRoundsGiveTenMatchdaysAndTwentyMatches(): void {
        $detail = $this->league([1, 2, 3, 4, $this->fifthTeam()], 2);
        $this->assertCount(10, $detail['matchdays']);
        $this->assertCount(20, $detail['matches']);
        $this->assertSame(['in_progress', '2026-10-17', '2026-12-19'], [$detail['status'], $detail['matchdays'][0]['game_date'], $detail['matchdays'][9]['game_date']]);
        $this->assertSame([1, 2], array_values(array_unique(array_map('intval', array_column($detail['matches'], 'leg')))));
        $this->assertCount(5, $detail['standings']);
        $this->assertSame('current', array_column($detail['lifecycle']['steps'], 'state', 'key')['matches']);
    }

    public function testPreviewShowsRestingTeamAndRejectsADateOutsideTheTournament(): void {
        $id = $this->closedTournament([1, 2, 3], 'manual', $this->leagueInput(['league_rounds' => 1, 'end_date' => '2026-10-30']));
        $preview = $this->tournaments->previewFixture($id, ['first_date' => '2026-10-17', 'interval_days' => 3]);
        $this->assertSame(3, $preview['matchday_count']);
        $resting = array_column($preview['matchdays'], 'resting');
        sort($resting);
        $this->assertSame([1, 2, 3], $resting, 'Cada equipo descansa una jornada.');
        $this->assertRejected(fn() => $this->tournaments->previewFixture($id, ['first_date' => '2026-10-17', 'interval_days' => 7]), 422, 'first_date');
        $this->assertRejected(fn() => $this->tournaments->previewFixture($id, ['first_date' => '2026-10-10']), 422, 'first_date');
        $this->assertRejected(fn() => $this->tournaments->generateFixture($id, ['game_dates' => ['2026-10-20', '2026-10-19', '2026-10-25']]), 422, 'game_dates');
        $this->assertRejected(fn() => $this->tournaments->generateFixture($id, ['game_dates' => ['2026-10-20']]), 422, 'game_dates');
    }

    public function testRandomDrawIsFixedWhenRegistrationCloses(): void {
        $id = $this->closedTournament([1, 2, 3, 4], 'random', $this->leagueInput());
        $first = $this->tournaments->previewFixture($id, ['first_date' => '2026-10-17']);
        $second = $this->tournaments->previewFixture($id, ['first_date' => '2026-10-17']);
        $this->assertSame($first['matchdays'], $second['matchdays'], 'La vista previa coincide con lo que se generará.');
    }

    public function testLeagueMatchesAllowDrawsButNoExtraTimeOrPenalties(): void {
        $detail = $this->league([1, 2, 3, 4]);
        $match = $this->matchesOf($detail, 1)[0];
        $id = (int)$detail['id'];
        $this->scheduleAndStart($id, (int)$match['id'], '2026-10-17T10:00');
        $home = $this->playersOf((int)$match['home_team_id'])[0];
        $away = $this->playersOf((int)$match['away_team_id'])[0];
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [self::goal($home, 70, 1)]]), 422, 'goal_events');
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [], 'first_kicker_team_id' => (int)$match['home_team_id'], 'penalty_kicks' => [['team_id' => (int)$match['home_team_id'], 'scored' => 1]]]), 422, 'penalty_kicks');
        $this->submit($id, (int)$match['id'], ['goal_events' => [self::goal($home, 10), self::goal($away, 20)]]);
        $this->confirm($id, (int)$match['id']);
        $played = $this->matchById($id, (int)$match['id']);
        $this->assertSame(['confirmed', null, 1, 1], [$played['workflow_status'], $played['winner_team_id'], (int)$played['home_score'], (int)$played['away_score']]);
        $table = array_column($this->tournaments->standings($id), null, 'team_id');
        $this->assertSame([1, 1, 1], [$table[(int)$match['home_team_id']]['drawn'], $table[(int)$match['home_team_id']]['points'], $table[(int)$match['away_team_id']]['points']]);
    }

    public function testMatchMustBeScheduledInsideItsMatchday(): void {
        $detail = $this->league([1, 2, 3, 4]);
        $match = $this->matchesOf($detail, 2)[0];
        $error = $this->assertRejected(fn() => $this->schedule((int)$detail['id'], (int)$match['id'], '2026-10-17T10:00'), 422, 'scheduled_at');
        $this->assertStringContainsString('jornada 2', $error->fields['scheduled_at']);
        $this->schedule((int)$detail['id'], (int)$match['id'], '2026-10-24T10:00');
        $this->assertSame('scheduled', $this->matchById((int)$detail['id'], (int)$match['id'])['workflow_status']);
    }

    public function testPostponeAddExtraordinaryMatchdayMoveAndPlay(): void {
        $detail = $this->league([1, 2, 3, 4]);
        $id = (int)$detail['id'];
        $match = $this->matchesOf($detail, 1)[0];
        $this->schedule($id, (int)$match['id'], '2026-10-17T10:00');
        $league = $this->di->getShared('formats')->league();
        $this->assertRejected(fn() => $league->postpone($this->tournament($id), $this->matchModel($id, (int)$match['id']), self::ADMIN_ID, ['reason' => '<b>']), 422, 'reason');
        $league->postpone($this->tournament($id), $this->matchModel($id, (int)$match['id']), self::ADMIN_ID, ['reason' => 'Cancha inundada por la lluvia.']);
        $postponed = $this->matchById($id, (int)$match['id']);
        $this->assertSame(['suspended', null, null], [$postponed['workflow_status'], $postponed['scheduled_at'], $postponed['referee_user_id']]);

        // The other match of matchday 1 involves the other two teams; moving into matchday 2 clashes (both teams play there).
        $this->assertRejected(fn() => $league->moveToMatchday($this->tournament($id), $this->matchModel($id, (int)$match['id']), self::ADMIN_ID, ['matchday_id' => (int)$detail['matchdays'][1]['id']]), 409, 'matchday_id');

        $this->assertRejected(fn() => $this->tournaments->addExtraordinaryMatchday($id, ['game_date' => '2026-10-10']), 422, 'game_date');
        $this->tournaments->addExtraordinaryMatchday($id, ['game_date' => '2026-11-11', 'label' => 'Reposición']);
        $detail = $this->tournaments->detail($id);
        $extra = end($detail['matchdays']);
        $this->assertSame([4, 'extraordinary', 'Reposición'], [(int)$extra['number'], $extra['kind'], $extra['label']]);

        $league->moveToMatchday($this->tournament($id), $this->matchModel($id, (int)$match['id']), self::ADMIN_ID, ['matchday_id' => (int)$extra['id']]);
        $moved = $this->matchById($id, (int)$match['id']);
        $this->assertSame(['unprogrammed', (int)$extra['id'], (int)$detail['matchdays'][0]['id']], [$moved['workflow_status'], (int)$moved['matchday_id'], (int)$moved['original_matchday_id']]);
        $this->play($id, (int)$match['id'], '2026-11-11T10:00', [self::goal($this->playersOf((int)$match['home_team_id'])[0], 5)]);
        $this->assertSame('confirmed', $this->matchById($id, (int)$match['id'])['workflow_status']);

        $this->assertRejected(fn() => $this->tournaments->deleteMatchday($id, (int)$extra['id']), 409);
        $this->assertRejected(fn() => $this->tournaments->deleteMatchday($id, (int)$detail['matchdays'][0]['id']), 409);
    }

    public function testChampionWhenEveryMatchIsConfirmedAndCorrectionReopens(): void {
        $detail = $this->league([1, 2, 3]);
        $id = (int)$detail['id'];
        foreach ($detail['matches'] as $index => $match) {
            $winner = $this->playersOf(1)[0];
            $matchday = current(array_filter($detail['matchdays'], fn($m) => (int)$m['id'] === (int)$match['matchday_id']));
            $events = in_array(1, [(int)$match['home_team_id'], (int)$match['away_team_id']], true) ? [self::goal($winner, 10 + $index)] : [];
            $this->play($id, (int)$match['id'], $matchday['game_date'] . 'T10:00', $events);
        }
        $finished = $this->tournaments->detail($id);
        $this->assertSame(['finished', 1], [$finished['status'], (int)$finished['champion_team_id']]);
        $this->assertSame([6, 1, 1], array_column($finished['standings'], 'points'));
        $this->assertSame(array_fill(0, 5, 'done'), array_column($finished['lifecycle']['steps'], 'state'));

        $last = end($detail['matches']);
        $this->sheet->correct($this->tournament($id), $this->matchModel($id, (int)$last['id']), self::ADMIN_ID, ['goal_events' => []]);
        $reopened = $this->tournaments->detail($id);
        $this->assertSame(['in_progress', null], [$reopened['status'], $reopened['champion_team_id']]);
    }

    public function testEndDateCanOnlyBeExtended(): void {
        $detail = $this->league([1, 2, 3]);
        $id = (int)$detail['id'];
        $this->assertRejected(fn() => $this->tournaments->extendEndDate($id, ['end_date' => '2027-01-01']), 422, 'end_date');
        $this->assertSame('2027-12-31', $this->tournaments->extendEndDate($id, ['end_date' => '2027-12-31'])['end_date']);
    }

    public function testKnockoutTournamentsHaveNoMatchdays(): void {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $this->assertArrayNotHasKey('matchdays', $detail);
        $this->assertRejected(fn() => $this->tournaments->addExtraordinaryMatchday((int)$detail['id'], ['game_date' => '2026-11-01']), 409);
        $this->assertRejected(fn() => $this->tournaments->standings((int)$detail['id']), 409);
    }

    public function testHistoricTournamentIsReadOnly(): void {
        $historic = (int)$this->state->state()['tournaments'][0]['id'];
        $this->assertRejected(fn() => $this->tournaments->addExtraordinaryMatchday($historic, ['game_date' => '2026-11-01']), 409);
        $this->assertNotEmpty($this->tournaments->scorers($historic), 'El histórico muestra sus goleadores por jornada.');
    }
}
