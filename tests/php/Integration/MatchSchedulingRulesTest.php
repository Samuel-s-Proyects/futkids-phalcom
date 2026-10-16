<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Today is 2026-10-16 09:00 (frozen clock). */
final class MatchSchedulingRulesTest extends IntegrationTestCase
{
    /** Four-team bracket from today to the end of the year. @return array{int, int, int} tournament, semi 1 (teams 1, 4), semi 2 (teams 2, 3) */
    private function semis(): array {
        $detail = $this->bracket([1, 2, 3, 4], ['start_date' => '2026-10-16', 'end_date' => '2026-12-31']);
        return [(int)$detail['id'], (int)$this->match($detail, 1, 1)['id'], (int)$this->match($detail, 1, 2)['id']];
    }

    public function testCannotScheduleInThePast(): void {
        [$id, $semi] = $this->semis();
        $this->assertRejected(fn() => $this->schedule($id, $semi, '2026-10-16T08:59'), 422, 'scheduled_at');
        $this->schedule($id, $semi, '2026-10-16T09:00');
        $this->assertSame('scheduled', $this->matchById($id, $semi)['workflow_status']);
    }

    public function testKeepingAnUnchangedPastScheduleIsAllowed(): void {
        [$id, $semi] = $this->semis();
        $this->schedule($id, $semi, '2026-10-16T10:00');
        $this->clock->advance('+1 day');
        $this->schedule($id, $semi, '2026-10-16T10:00', 'Cancha Norte');
        $this->assertSame('Cancha Norte', $this->matchById($id, $semi)['venue']);
        $this->assertRejected(fn() => $this->schedule($id, $semi, '2026-10-16T11:00'), 422, 'scheduled_at');
    }

    public function testMustFallInsideTheTournament(): void {
        [$id, $semi] = $this->semis();
        $this->assertRejected(fn() => $this->schedule($id, $semi, '2027-01-01T10:00'), 422, 'scheduled_at');
    }

    public function testFormatAndCharactersAreValidated(): void {
        [$id, $semi] = $this->semis();
        $error = $this->assertRejected(fn() => $this->scheduler->schedule($this->tournament($id), $this->matchModel($id, $semi), ['scheduled_at' => '2026-10-20 10:00', 'venue' => 'Cancha #1 <b>', 'referee_user_id' => 'dos'], self::ADMIN_ID), 422);
        $this->assertEqualsCanonicalizing(['scheduled_at', 'venue', 'referee_user_id'], array_keys($error->fields));
        $this->assertRejected(fn() => $this->schedule($id, $semi, '2026-10-20T10:00', 'Cancha 1', self::ADMIN_ID), 422, 'referee_user_id');
    }

    public function testFinalMustFollowItsSemifinals(): void {
        [$id, $semi1] = $this->semis();
        $this->schedule($id, $semi1, '2026-10-20T10:00');
        $final = (int)$this->match($this->tournaments->detail($id), 2, 1)['id'];
        $this->di->getShared('db')->execute('UPDATE tournament_matches SET home_team_id = 1, away_team_id = 2 WHERE id = ?', [$final]);
        $this->assertRejected(fn() => $this->schedule($id, $final, '2026-10-20T10:30'), 422, 'scheduled_at');
        $this->schedule($id, $final, '2026-10-20T11:00');
        $this->assertSame('scheduled', $this->matchById($id, $final)['workflow_status']);
    }

    public function testVenueClashIgnoresCaseAccentsAndPunctuation(): void {
        [$id, $semi1, $semi2] = $this->semis();
        $this->schedule($id, $semi1, '2026-10-20T10:00', 'Cancha 2 Las Palmas');
        foreach (['cancha  2 las palmas', 'CANCHA 2, LAS PALMAS.', 'Cánchá 2 Las Pálmas'] as $variant) {
            $this->assertRejected(fn() => $this->schedule($id, $semi2, '2026-10-20T10:30', $variant, self::REFEREE_ID), 409, 'venue');
        }
    }

    public function testClashesAreDetectedAcrossTournamentsWithinTheMatchDuration(): void {
        [$id, $semi1] = $this->semis();
        $this->schedule($id, $semi1, '2026-10-20T10:00', 'Cancha 1');
        $other = $this->bracket([1, 3], ['start_date' => '2026-10-16', 'end_date' => '2026-12-31', 'match_duration_minutes' => 30]);
        $otherMatch = (int)$other['matches'][0]['id'];
        $otherId = (int)$other['id'];
        $this->assertRejected(fn() => $this->schedule($otherId, $otherMatch, '2026-10-20T10:59', 'Cancha 9'), 409, 'scheduled_at');
        $this->assertRejected(fn() => $this->schedule($otherId, $otherMatch, '2026-10-20T09:31', 'Cancha 9'), 409, 'referee_user_id');
        $this->schedule($otherId, $otherMatch, '2026-10-20T11:00', 'Cancha 1');
        $this->assertSame('scheduled', $this->matchById($otherId, $otherMatch)['workflow_status']);
    }

    public function testRefereeCannotDirectTwoOverlappingMatches(): void {
        [$id, $semi1, $semi2] = $this->semis();
        $this->schedule($id, $semi1, '2026-10-20T10:00', 'Cancha 1');
        $this->assertRejected(fn() => $this->schedule($id, $semi2, '2026-10-20T10:45', 'Cancha 2'), 409, 'referee_user_id');
        $this->schedule($id, $semi2, '2026-10-20T11:00', 'Cancha 2');
        $this->assertSame('scheduled', $this->matchById($id, $semi2)['workflow_status']);
    }
}
