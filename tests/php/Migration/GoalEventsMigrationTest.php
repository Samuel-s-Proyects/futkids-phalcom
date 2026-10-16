<?php
declare(strict_types=1);
namespace Tests\Migration;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;
use Tests\Support\TestDatabase;

/**
 * Upgrades a database created with the previous version (migration 007) that contains a finished knockout
 * tournament whose final suffered the old duplicated-live-goal bug.
 */
final class GoalEventsMigrationTest extends TestCase
{
    private AdapterInterface $db;
    private int $matchId;

    protected function setUp(): void {
        $di = TestApplication::boot(TestDatabase::emptyFile());
        $this->db = $di->getShared('db');
        $di->getShared('migrator')->migrate(until: '007_match_audit_snapshot');
        LegacyDataFixture::load($this->db);
        $this->buildFinishedTournament();
        $di->getShared('migrator')->migrate();
    }

    protected function tearDown(): void {
        $this->db->close();
    }

    /** Team 1 beat team 2 2-1 in extra time (A 10', B 20', A 70'); the 10' goal was stored twice. */
    private function buildFinishedTournament(): void {
        $this->db->insertAsDict('tournaments', ['name' => 'Copa histórica', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'finished', 'draw_method' => 'manual',
            'registrations_closed_at' => '2026-09-01 08:00:00', 'bracket_generated_at' => '2026-09-01 09:00:00',
            'extra_time_periods' => 1, 'extra_time_period_minutes' => 10, 'penalty_attempts_per_team' => 5, 'penalty_sudden_death' => 1]);
        $tournament = (int)$this->db->lastInsertId();
        $this->db->insertAsDict('tournament_matches', ['tournament_id' => $tournament, 'round_number' => 1, 'match_number' => 1, 'home_team_id' => 1, 'away_team_id' => 2,
            'winner_team_id' => 1, 'status' => 'completed', 'workflow_status' => 'confirmed', 'scheduled_at' => '2026-09-10 10:00:00', 'venue' => 'Cancha  Central.',
            'referee_user_id' => 2, 'confirmed_at' => '2026-09-10 12:00:00', 'result_revision' => 1]);
        $this->matchId = (int)$this->db->lastInsertId();
        foreach ([[0, 1, 1], [1, 1, 0]] as [$period, $home, $away]) {
            $this->db->insertAsDict('tournament_match_period_scores', ['tournament_match_id' => $this->matchId, 'period_number' => $period, 'home_score' => $home, 'away_score' => $away, 'home_own_goals' => 0, 'away_own_goals' => 0]);
        }
        $this->db->insertAsDict('tournament_match_goals', ['tournament_match_id' => $this->matchId, 'player_id' => 1, 'team_id' => 1, 'goals' => 2]);
        $this->db->insertAsDict('tournament_match_goals', ['tournament_match_id' => $this->matchId, 'player_id' => 6, 'team_id' => 2, 'goals' => 1]);
        foreach ([[1, 1, 10], [1, 1, 10], [6, 2, 20], [1, 1, 70]] as [$player, $team, $minute]) {
            $this->db->insertAsDict('tournament_match_goal_events', ['tournament_match_id' => $this->matchId, 'player_id' => $player, 'team_id' => $team, 'minute' => $minute, 'own_goal' => 0, 'created_by_user_id' => 2]);
        }
    }

    private function one(string $sql): array {
        return $this->db->fetchOne($sql, Enum::FETCH_ASSOC, [$this->matchId]);
    }

    public function testDuplicatedEventsAreRemovedAndAudited(): void {
        $events = $this->db->fetchAll('SELECT player_id, minute, period_number, credited_team_id FROM tournament_match_goal_events WHERE tournament_match_id = ? ORDER BY minute', Enum::FETCH_ASSOC, [$this->matchId]);
        $this->assertSame([[1, 10, 0, 1], [6, 20, 0, 2], [1, 70, 1, 1]], array_map(fn($e) => array_map('intval', array_values($e)), $events));
        $audit = $this->one("SELECT action, audit_snapshot FROM tournament_match_result_audit WHERE tournament_match_id = ? AND action = 'migration_dedupe'");
        $this->assertCount(1, json_decode($audit['audit_snapshot'], true)['removed_events']);
    }

    public function testFinalScoreAndVenueKeyAreStoredOnTheMatch(): void {
        $match = $this->one('SELECT home_score, away_score, decided_by, venue_key FROM tournament_matches WHERE id = ?');
        $this->assertSame(['2', '1', 'extra_time', 'cancha central'], [(string)$match['home_score'], (string)$match['away_score'], $match['decided_by'], $match['venue_key']]);
    }

    public function testFinishedTournamentGetsItsChampion(): void {
        $tournament = $this->db->fetchOne("SELECT type, status, champion_team_id, started_at, finished_at FROM tournaments WHERE name = 'Copa histórica'", Enum::FETCH_ASSOC);
        $this->assertSame(['knockout', 'finished', 1, '2026-09-01 09:00:00', '2026-09-10 12:00:00'], [$tournament['type'], $tournament['status'], (int)$tournament['champion_team_id'], $tournament['started_at'], $tournament['finished_at']]);
    }

    public function testForeignKeysStayConsistent(): void {
        $this->assertSame([], $this->db->fetchAll('PRAGMA foreign_key_check'));
    }
}
