<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Goals are entered as events; the score is always derived from them. */
final class GoalFlowTest extends IntegrationTestCase
{
    /** @return array{int, int} tournament and a started match between teams 1 (home) and 2 (away) */
    private function liveMatch(array $overrides = []): array {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16', 'end_date' => '2026-12-31'] + $overrides);
        $matchId = (int)$detail['matches'][0]['id'];
        $this->scheduleAndStart((int)$detail['id'], $matchId, '2026-10-20T10:00');
        return [(int)$detail['id'], $matchId];
    }

    private function addGoal(int $id, int $matchId, array $input): array {
        return $this->sheet->addGoal($this->tournament($id), $this->matchModel($id, $matchId), self::REFEREE_ID, $input);
    }

    private function events(int $matchId): array {
        return $this->rows('tournament_match_goal_events', 'tournament_match_id = ? ORDER BY period_number, minute, id', [$matchId]);
    }

    public function testLiveGoalsAreNotDuplicatedWhenTheResultIsSubmitted(): void {
        [$id, $matchId] = $this->liveMatch();
        [$scorer] = $this->playersOf(1);
        $this->addGoal($id, $matchId, self::goal($scorer, 10));
        $this->addGoal($id, $matchId, self::goal($scorer, 30));
        $this->submit($id, $matchId, ['goal_events' => [self::goal($scorer, 10), self::goal($scorer, 30)]]);
        $this->assertCount(2, $this->events($matchId), 'El envío final reemplaza los goles registrados en vivo.');
        $this->assertSame([2, 0], [(int)$this->matchById($id, $matchId)['home_score'], (int)$this->matchById($id, $matchId)['away_score']]);
        $this->assertSame(2, (int)$this->rows('tournament_match_goals', 'tournament_match_id = ?', [$matchId])[0]['goals']);
    }

    public function testLiveGoalsCanBeCorrectedAndRemoved(): void {
        [$id, $matchId] = $this->liveMatch();
        [$scorer, $other] = $this->playersOf(1);
        $goal = $this->addGoal($id, $matchId, self::goal($scorer, 10));
        $updated = $this->sheet->updateGoal($this->tournament($id), $this->matchModel($id, $matchId), self::REFEREE_ID, (int)$goal['id'], self::goal($other, 12));
        $this->assertSame([(int)$other['id'], 12], [(int)$updated['player_id'], (int)$updated['minute']]);
        $this->sheet->deleteGoal($this->matchModel($id, $matchId), self::REFEREE_ID, (int)$goal['id']);
        $this->assertSame([], $this->events($matchId));
        $this->assertSame(['goal_recorded', 'goal_updated', 'goal_deleted'], array_values(array_intersect(
            array_column($this->rows('tournament_match_result_audit', 'tournament_match_id = ? ORDER BY id', [$matchId]), 'action'),
            ['goal_recorded', 'goal_updated', 'goal_deleted'],
        )));
    }

    public function testGoalsCanOnlyBeRecordedDuringTheMatch(): void {
        $detail = $this->bracket([1, 2], ['start_date' => '2026-10-16']);
        $id = (int)$detail['id'];
        $matchId = (int)$detail['matches'][0]['id'];
        $this->schedule($id, $matchId, '2026-10-20T10:00');
        $this->assertRejected(fn() => $this->addGoal($id, $matchId, self::goal($this->playersOf(1)[0], 5)), 409);
    }

    public function testOwnGoalCountsForTheOpponentButNotForTheScorer(): void {
        [$id, $matchId] = $this->liveMatch();
        $defender = $this->playersOf(1)[0];
        $striker = $this->playersOf(2)[0];
        $this->submit($id, $matchId, ['goal_events' => [self::ownGoal($defender, 15), self::goal($striker, 50)]]);
        $match = $this->matchById($id, $matchId);
        $this->assertSame([0, 2, 2], [(int)$match['home_score'], (int)$match['away_score'], (int)$match['winner_team_id']]);
        $ownGoal = $this->events($matchId)[0];
        $this->assertSame([1, 2, 1], [(int)$ownGoal['team_id'], (int)$ownGoal['credited_team_id'], (int)$ownGoal['own_goal']]);
        $this->assertSame([['player_id' => (int)$striker['id'], 'team_id' => 2, 'goals' => 1]], $this->tournaments->scorers($id));
    }

    public function testExtraTimeOnlyAfterADrawnRegulation(): void {
        [$id, $matchId] = $this->liveMatch();
        $home = $this->playersOf(1)[0];
        $this->assertRejected(fn() => $this->submit($id, $matchId, ['goal_events' => [self::goal($home, 10), self::goal($home, 70, 1)]]), 422, 'goal_events');
        $this->assertRejected(fn() => $this->submit($id, $matchId, ['goal_events' => [self::goal($home, 70, 3)]]), 422, 'goal_events');
    }

    public function testNoExtraTimeGoesStraightToPenalties(): void {
        [$id, $matchId] = $this->liveMatch(['extra_time_periods' => 0]);
        $kicks = [];
        foreach ([1, 0, 1, 0, 1, 0] as $index => $scored) $kicks[] = ['team_id' => $index % 2 ? 2 : 1, 'scored' => $scored];
        $this->submit($id, $matchId, ['goal_events' => [], 'first_kicker_team_id' => 1, 'penalty_kicks' => $kicks]);
        $match = $this->matchById($id, $matchId);
        $this->assertSame(['penalties', 1, 3, 0], [$match['decided_by'], (int)$match['winner_team_id'], (int)$match['home_penalties'], (int)$match['away_penalties']]);
        $this->assertCount(1, $this->rows('tournament_match_period_scores', 'tournament_match_id = ?', [$matchId]));
    }

    public function testPenaltiesAreRejectedWhenThereIsAWinner(): void {
        [$id, $matchId] = $this->liveMatch();
        $this->assertRejected(fn() => $this->submit($id, $matchId, ['goal_events' => [self::goal($this->playersOf(1)[0], 5)], 'first_kicker_team_id' => 1, 'penalty_kicks' => [['team_id' => 1, 'scored' => 1]]]), 422, 'penalty_kicks');
    }

    public function testRevisionKeepsTheEventsUntilReplaced(): void {
        [$id, $matchId] = $this->liveMatch();
        $scorer = $this->playersOf(1)[0];
        $this->submit($id, $matchId, ['goal_events' => [self::goal($scorer, 10)]]);
        $this->sheet->revise($this->tournament($id), $this->matchModel($id, $matchId), self::REFEREE_ID, ['goal_events' => [self::goal($scorer, 10), self::goal($scorer, 20)]]);
        $this->assertSame(2, (int)$this->matchById($id, $matchId)['home_score']);
        $this->assertSame(2, (int)$this->matchById($id, $matchId)['result_revision']);
    }
}
