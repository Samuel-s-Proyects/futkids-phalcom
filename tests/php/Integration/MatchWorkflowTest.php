<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Live operation of knockout matches: start, incidents, result, confirmation, penalties and corrections. */
final class MatchWorkflowTest extends IntegrationTestCase
{
    private const KICK_PATTERN = [1, 1, 1, 0, 0];
    private const FIRST_MATCH_AT = '2027-01-10T14:00';

    /** Alternating kicks, both teams scoring the same pattern (3-3 after five rounds). */
    private function tiedKicks(int $first, int $second): array {
        $kicks = [];
        foreach (self::KICK_PATTERN as $scored) {
            $kicks[] = ['team_id' => $first, 'scored' => $scored];
            $kicks[] = ['team_id' => $second, 'scored' => $scored];
        }
        return $kicks;
    }

    private function auditActions(int $matchId): array {
        return array_column($this->rows('tournament_match_result_audit', 'tournament_match_id = ?', [$matchId]), 'action');
    }

    /** Two-team tournament (no sudden death) with its only match in progress. @return array{int, array} */
    private function twoTeamMatchInProgress(): array {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-12-01', 'end_date' => '2026-12-20', 'penalty_sudden_death' => 0]);
        $id = (int)$detail['id'];
        $matchId = (int)$detail['matches'][0]['id'];
        $this->schedule($id, $matchId, '2026-12-05T14:00');
        $this->assertRejected(fn() => $this->sheet->start($this->matchModel($id, $matchId), 999), 403);
        $this->sheet->start($this->matchModel($id, $matchId), self::REFEREE_ID);
        return [$id, $this->matchById($id, $matchId)];
    }

    /**
     * Five-team bracket in January 2027; round 1 match 2 (team 4 vs a new team without players) is started.
     * @return array{int, array, list<array>} tournament id, that match, players of team 4
     */
    private function firstMatchStarted(): array {
        $newTeam = (int)$this->catalog->save('teams', ['name' => 'Equipo de prueba', 'active' => 1])['id'];
        $detail = $this->bracket([1, 2, 3, 4, $newTeam], ['start_date' => '2027-01-01', 'end_date' => '2027-01-31']);
        $id = (int)$detail['id'];
        $first = $this->match($detail, 1, 2);
        $this->assertSame(4, (int)$first['home_team_id']);
        $this->scheduleAndStart($id, (int)$first['id'], self::FIRST_MATCH_AT);
        return [$id, $this->matchById($id, (int)$first['id']), $this->playersOf(4)];
    }

    /** Team 4 wins 2-1 in extra time; one of its players scores an own goal. */
    private function extraTimeEvents(array $homePlayers): array {
        return ['goal_events' => [
            self::goal($homePlayers[1], 20),
            self::ownGoal($homePlayers[0], 40),
            self::goal($homePlayers[1], 65, 1),
        ]];
    }

    public function testStartingRequiresAssignedRefereeAndHappensOnce(): void {
        [$id, $match] = $this->twoTeamMatchInProgress();
        $this->assertSame('in_progress', $match['workflow_status']);
        $this->assertSame('2026-10-16 09:00:00', $match['started_at']);
        $this->assertRejected(fn() => $this->sheet->start($this->matchModel($id, (int)$match['id']), self::REFEREE_ID), 409);
    }

    public function testAssignedRefereeOrAdministratorManageIncidentsDuringPlay(): void {
        [$id, $match] = $this->twoTeamMatchInProgress();
        $matchId = (int)$match['id'];
        $player = $this->playersOf((int)$match['home_team_id'])[0];
        $model = fn() => $this->matchModel($id, $matchId);
        $incident = $this->incidents->save($model(), self::REFEREE_ID, ['player_id' => (int)$player['id'], 'minute' => 12, 'card_type' => 'yellow', 'description' => 'Falta táctica']);
        $this->assertSame([(int)$player['team_id'], 12, '2026-12-05'], [(int)$incident['team_id'], (int)$incident['minute'], $incident['incident_date']]);

        $byAdmin = $this->incidents->save($model(), self::ADMIN_ID, ['player_id' => (int)$player['id'], 'minute' => 13, 'card_type' => 'yellow', 'description' => 'Prueba']);
        $this->incidents->delete($model(), self::ADMIN_ID, (int)$byAdmin['id']);
        $this->assertRejected(fn() => $this->catalog->save('incidents', ['player_id' => (int)$player['id'], 'description' => 'Cambio externo', 'card_type' => 'yellow', 'incident_date' => '2026-10-16'], (int)$incident['id']), 403);
        $this->assertRejected(fn() => $this->catalog->delete('incidents', (int)$incident['id']), 403);
        $this->assertRejected(fn() => $this->incidents->save($model(), self::REFEREE_ID, ['player_id' => 999, 'minute' => 200, 'card_type' => 'green', 'description' => '<b>']), 422);

        $incident = $this->incidents->save($model(), self::REFEREE_ID, ['player_id' => (int)$player['id'], 'minute' => 15, 'card_type' => 'yellow', 'description' => 'Falta revisada'], (int)$incident['id']);
        $this->assertSame('Falta revisada', $incident['description']);
        $this->incidents->delete($model(), self::REFEREE_ID, (int)$incident['id']);
        $this->assertSame([], $this->rows('incidents', 'tournament_match_id = ?', [$matchId]));
    }

    public function testRedCardSuspensionCannotPrecedeTheMatch(): void {
        [$id, $match] = $this->twoTeamMatchInProgress();
        $player = $this->playersOf((int)$match['home_team_id'])[0];
        $this->assertRejected(fn() => $this->incidents->save($this->matchModel($id, (int)$match['id']), self::REFEREE_ID, ['player_id' => (int)$player['id'], 'minute' => 30, 'card_type' => 'red', 'description' => 'Expulsión', 'suspension_date' => '2026-12-04']), 422, 'suspension_date');
    }

    public function testTiedShootoutIsRejectedWithoutSuddenDeath(): void {
        [$id, $match] = $this->twoTeamMatchInProgress();
        $home = (int)$match['home_team_id'];
        $away = (int)$match['away_team_id'];
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [], 'first_kicker_team_id' => $home, 'penalty_kicks' => $this->tiedKicks($home, $away)]), 409, 'penalty_kicks');
    }

    public function testKnockoutDrawNeedsAShootout(): void {
        [$id, $match] = $this->twoTeamMatchInProgress();
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => []]), 422, 'first_kicker_team_id');
    }

    public function testResultRejectsSuspendedScorersAndStrangers(): void {
        [$id, $match, $homePlayers] = $this->firstMatchStarted();
        $suspended = $homePlayers[2];
        $this->catalog->save('incidents', ['player_id' => (int)$suspended['id'], 'description' => 'Suspensión de prueba', 'card_type' => 'red', 'incident_date' => '2026-10-16', 'suspension_date' => '2027-01-10']);
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [self::goal($suspended, 10)]]), 409, 'goal_events');
        $stranger = $this->playersOf(1)[0];
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [self::goal($stranger, 10)]]), 422, 'goal_events');
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], ['goal_events' => [['player_id' => (int)$homePlayers[0]['id']]]]), 422, 'goal_events');
    }

    public function testPlayerCannotScoreAfterRedCardInSameMatch(): void {
        [$id, $match, $homePlayers] = $this->firstMatchStarted();
        $this->incidents->save($this->matchModel($id, (int)$match['id']), self::REFEREE_ID, ['player_id' => (int)$homePlayers[1]['id'], 'minute' => 30, 'card_type' => 'red', 'description' => 'Expulsión de prueba', 'suspension_date' => '2027-02-01']);
        $this->assertRejected(fn() => $this->submit($id, (int)$match['id'], $this->extraTimeEvents($homePlayers)), 409, 'goal_events');
    }

    public function testExtraTimeWinnerAdvancesOnlyAfterConfirmation(): void {
        [$id, $match, $homePlayers] = $this->firstMatchStarted();
        $matchId = (int)$match['id'];
        $scorerGoalsBefore = (int)$this->reports->player((int)$homePlayers[1]['id'])['total_goals'];

        $this->submit($id, $matchId, $this->extraTimeEvents($homePlayers));
        $detail = $this->tournaments->detail($id);
        $pending = $this->match($detail, 1, 2);
        $this->assertSame(['awaiting_confirmation', 2, 1, 'extra_time', 4], [$pending['workflow_status'], (int)$pending['home_score'], (int)$pending['away_score'], $pending['decided_by'], (int)$pending['winner_team_id']]);
        $this->assertNull($this->match($detail, 2, 1)['away_team_id']);

        $this->sheet->revise($this->tournament($id), $this->matchModel($id, $matchId), self::REFEREE_ID, $this->extraTimeEvents($homePlayers));
        $this->assertContains('result_revised', $this->auditActions($matchId));

        $this->confirm($id, $matchId, self::ADMIN_ID);
        $next = $this->match($this->tournaments->detail($id), 2, 1);
        $this->assertSame([1, 4], [(int)$next['home_team_id'], (int)$next['away_team_id']]);

        $this->assertSame($scorerGoalsBefore + 2, (int)$this->reports->player((int)$homePlayers[1]['id'])['total_goals'], 'Los autogoles no se atribuyen al ranking.');
        $this->assertCount(3, $this->rows('tournament_match_goal_events', 'tournament_match_id = ?', [$matchId]));
        $periods = $this->rows('tournament_match_period_scores', 'tournament_match_id = ? ORDER BY period_number', [$matchId]);
        $this->assertSame([[1, 1], [1, 0], [0, 0]], array_map(fn($p) => [(int)$p['home_score'], (int)$p['away_score']], $periods));
    }

    public function testShootoutCorrectionAndAdvancement(): void {
        [$id, $match, $homePlayers] = $this->firstMatchStarted();
        $this->submit($id, (int)$match['id'], $this->extraTimeEvents($homePlayers));
        $this->confirm($id, (int)$match['id'], self::ADMIN_ID);
        $semiId = (int)$this->match($this->tournaments->detail($id), 2, 1)['id'];

        $this->assertRejected(fn() => $this->schedule($id, $semiId, self::FIRST_MATCH_AT, 'Cancha 2'), 422, 'scheduled_at');
        $this->scheduleAndStart($id, $semiId, '2027-01-15T14:00');

        $kicks = array_merge($this->tiedKicks(1, 4), [['team_id' => 1, 'scored' => 1], ['team_id' => 4, 'scored' => 0]]);
        $result = ['goal_events' => [], 'first_kicker_team_id' => 1, 'penalty_kicks' => $kicks];
        $this->submit($id, $semiId, $result);
        $this->confirm($id, $semiId);
        $detail = $this->tournaments->detail($id);
        $semi = $this->match($detail, 2, 1);
        $this->assertSame([1, 'penalties', 4, 3], [(int)$semi['winner_team_id'], $semi['decided_by'], (int)$semi['home_penalties'], (int)$semi['away_penalties']]);
        $this->assertCount(12, $this->rows('tournament_match_penalties', 'tournament_match_id = ?', [$semiId]));
        $this->assertSame(1, (int)$this->match($detail, 3, 1)['home_team_id']);

        $this->assertRejected(fn() => $this->sheet->correct($this->tournament($id), $this->matchModel($id, $semiId), self::REFEREE_ID, $result), 403);

        $this->sheet->correct($this->tournament($id), $this->matchModel($id, $semiId), self::ADMIN_ID, $result);
        $final = $this->match($this->tournaments->detail($id), 3, 1);
        $this->assertSame('unprogrammed', $final['workflow_status']);
        $this->assertNull($final['home_team_id'], 'El clasificado se retira hasta confirmar el resultado corregido.');
        $this->assertSame('awaiting_confirmation', $this->matchById($id, $semiId)['workflow_status']);
        $this->assertContains('exceptional_correction', $this->auditActions($semiId));

        $this->confirm($id, $semiId, self::ADMIN_ID);
        $this->assertSame(1, (int)$this->match($this->tournaments->detail($id), 3, 1)['home_team_id']);
    }
}
