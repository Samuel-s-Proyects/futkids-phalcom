<?php
declare(strict_types=1);
namespace Tests\Migration;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;
use Tests\Support\TestDatabase;

/** Global matchdays from the previous version move into a finished "historic" points tournament. */
final class MatchdaysMigrationTest extends TestCase
{
    private AdapterInterface $db;

    protected function setUp(): void {
        $di = TestApplication::boot(TestDatabase::emptyFile());
        $this->db = $di->getShared('db');
        $di->getShared('migrator')->migrate(until: '011_users_management');
        LegacyDataFixture::load($this->db);
        // A knockout match that the old interface linked to a matchday.
        $this->db->insertAsDict('tournaments', ['id' => 50, 'name' => 'Copa', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'in_progress', 'draw_method' => 'manual']);
        $this->db->insertAsDict('tournament_matches', ['id' => 500, 'tournament_id' => 50, 'round_number' => 1, 'match_number' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'status' => 'pending', 'workflow_status' => 'unprogrammed', 'matchday_id' => 7, 'result_revision' => 0]);
        $di->getShared('migrator')->migrate();
    }

    protected function tearDown(): void {
        $this->db->close();
    }

    private function all(string $sql, array $bind = []): array {
        return $this->db->fetchAll($sql, Enum::FETCH_ASSOC, $bind);
    }

    public function testHistoricTournamentKeepsMatchdaysGoalsAndSuspensions(): void {
        $historic = $this->db->fetchOne('SELECT * FROM tournaments WHERE is_legacy = 1', Enum::FETCH_ASSOC);
        $this->assertSame(['Torneo histórico 2026', 'league', 'finished', '2026-09-05', '2026-09-12'], [$historic['name'], $historic['type'], $historic['status'], $historic['start_date'], $historic['end_date']]);
        $matchdays = $this->all('SELECT id, tournament_id, number, game_date, kind FROM matchdays ORDER BY id');
        $this->assertSame([[7, (int)$historic['id'], 1, '2026-09-05', 'regular'], [8, (int)$historic['id'], 2, '2026-09-12', 'regular']],
            array_map(fn($m) => [(int)$m['id'], (int)$m['tournament_id'], (int)$m['number'], $m['game_date'], $m['kind']], $matchdays));
        $this->assertSame([[1, 7, 3], [6, 8, 1]], array_map(fn($g) => [(int)$g['player_id'], (int)$g['matchday_id'], (int)$g['goals']], $this->all('SELECT * FROM goals ORDER BY id')));
        $this->assertSame('2026-09-12', $this->db->fetchOne("SELECT suspension_date FROM incidents WHERE card_type = 'red'", Enum::FETCH_ASSOC)['suspension_date']);
    }

    public function testOnlyTeamsWithHistoryAreEnrolled(): void {
        $teams = array_map('intval', array_column($this->all('SELECT tt.team_id FROM tournament_teams tt JOIN tournaments t ON t.id = tt.tournament_id WHERE t.is_legacy = 1 ORDER BY tt.seed_order'), 'team_id'));
        $this->assertSame([1, 2], $teams);
    }

    public function testKnockoutMatchesAreDetachedFromMatchdaysWithAudit(): void {
        $this->assertNull($this->db->fetchOne('SELECT matchday_id FROM tournament_matches WHERE id = 500', Enum::FETCH_ASSOC)['matchday_id']);
        $audit = $this->db->fetchOne("SELECT audit_snapshot FROM tournament_match_result_audit WHERE tournament_match_id = 500 AND action = 'matchday_detached'", Enum::FETCH_ASSOC);
        $this->assertSame(['matchday_id' => 7], json_decode($audit['audit_snapshot'], true));
    }

    public function testMatchdayNumbersAreUniquePerTournamentOnly(): void {
        $this->db->insertAsDict('tournaments', ['id' => 60, 'name' => 'Liga', 'type' => 'league', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', 'status' => 'draft', 'draw_method' => 'manual']);
        $this->db->insertAsDict('matchdays', ['tournament_id' => 60, 'number' => 1, 'game_date' => '2026-09-05']);
        $this->expectException(\PDOException::class);
        $this->db->insertAsDict('matchdays', ['tournament_id' => 60, 'number' => 1, 'game_date' => '2026-11-08']);
    }

    public function testForeignKeysStayConsistent(): void {
        $this->assertSame([], $this->all('PRAGMA foreign_key_check'));
    }
}
