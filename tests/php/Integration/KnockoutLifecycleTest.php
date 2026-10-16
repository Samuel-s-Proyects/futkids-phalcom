<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** A four-team knockout tournament from bracket to champion, and correcting the final. */
final class KnockoutLifecycleTest extends IntegrationTestCase
{
    /** @return array{int, int} tournament id and final id, with team 1 and team 2 through to the final */
    private function playSemifinals(): array {
        $detail = $this->bracket([1, 2, 3, 4], ['start_date' => '2026-10-16', 'end_date' => '2026-12-31']);
        $id = (int)$detail['id'];
        $this->play($id, (int)$this->match($detail, 1, 1)['id'], '2026-10-20T10:00', [self::goal($this->playersOf(1)[0], 5)]);
        $this->play($id, (int)$this->match($detail, 1, 2)['id'], '2026-10-20T12:00', [self::goal($this->playersOf(2)[0], 5)]);
        $final = $this->match($this->tournaments->detail($id), 2, 1);
        $this->assertSame([1, 2], [(int)$final['home_team_id'], (int)$final['away_team_id']]);
        return [$id, (int)$final['id']];
    }

    public function testConfirmingTheFinalCrownsTheChampion(): void {
        [$id, $final] = $this->playSemifinals();
        $this->play($id, $final, '2026-10-27T10:00', [self::goal($this->playersOf(2)[1], 33)]);
        $detail = $this->tournaments->detail($id);
        $this->assertSame(['finished', 2, '2026-10-16 09:00:00'], [$detail['status'], (int)$detail['champion_team_id'], $detail['finished_at']]);
        $this->assertSame(array_fill(0, 5, 'done'), array_column($detail['lifecycle']['steps'], 'state'));
    }

    public function testCorrectingTheFinalReopensTheTournament(): void {
        [$id, $final] = $this->playSemifinals();
        $this->play($id, $final, '2026-10-27T10:00', [self::goal($this->playersOf(2)[1], 33)]);
        $this->sheet->correct($this->tournament($id), $this->matchModel($id, $final), self::ADMIN_ID, ['goal_events' => [self::goal($this->playersOf(1)[1], 33)]]);
        $detail = $this->tournaments->detail($id);
        $this->assertSame(['in_progress', null], [$detail['status'], $detail['champion_team_id']]);
        $this->confirm($id, $final);
        $this->assertSame(1, (int)$this->tournaments->detail($id)['champion_team_id']);
    }

    public function testSemifinalCannotBeCorrectedOnceTheFinalStarted(): void {
        [$id, $final] = $this->playSemifinals();
        $this->scheduleAndStart($id, $final, '2026-10-27T10:00');
        $semi = (int)$this->match($this->tournaments->detail($id), 1, 1)['id'];
        $this->assertRejected(fn() => $this->sheet->correct($this->tournament($id), $this->matchModel($id, $semi), self::ADMIN_ID, ['goal_events' => []]), 409);
    }

    public function testCorrectingASemifinalUnschedulesTheFinal(): void {
        [$id, $final] = $this->playSemifinals();
        $this->schedule($id, $final, '2026-10-27T10:00');
        $semi = (int)$this->match($this->tournaments->detail($id), 1, 1)['id'];
        $this->sheet->correct($this->tournament($id), $this->matchModel($id, $semi), self::ADMIN_ID, ['goal_events' => [self::goal($this->playersOf(4)[0], 5)]]);
        $finalMatch = $this->matchById($id, $final);
        $this->assertSame(['unprogrammed', null], [$finalMatch['workflow_status'], $finalMatch['home_team_id']]);
        $this->confirm($id, $semi);
        $this->assertSame(4, (int)$this->matchById($id, $final)['home_team_id']);
    }
}
