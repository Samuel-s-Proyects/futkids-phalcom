<?php
declare(strict_types=1);
namespace Tests\Support;

use App\Exceptions\HttpException;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\CatalogService;
use App\Services\Match\MatchIncidentService;
use App\Services\Match\MatchScheduler;
use App\Services\Match\MatchSheetService;
use App\Services\ReportService;
use App\Services\StateService;
use App\Services\TournamentService;
use App\Support\FrozenClock;
use Phalcon\Di\DiInterface;
use PHPUnit\Framework\TestCase;

/**
 * Each test gets its own migrated and seeded SQLite database and a clock frozen at 2026-10-16 09:00.
 * Seed accounts: 1 = administrator, 2 = referee. Seed teams 1–4 have five players each.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const ADMIN_ID = 1;
    protected const REFEREE_ID = 2;
    protected const TIE_RULES = [
        'extra_time_periods' => 2,
        'extra_time_period_minutes' => 10,
        'penalty_attempts_per_team' => 5,
        'penalty_sudden_death' => 1,
    ];

    protected DiInterface $di;
    protected TournamentService $tournaments;
    protected MatchScheduler $scheduler;
    protected MatchSheetService $sheet;
    protected MatchIncidentService $incidents;
    protected CatalogService $catalog;
    protected ReportService $reports;
    protected StateService $state;
    protected FrozenClock $clock;
    private string $databaseFile;

    protected function setUp(): void {
        $this->databaseFile = TestDatabase::freshCopy();
        $this->di = TestApplication::boot($this->databaseFile);
        $this->tournaments = $this->di->getShared('tournaments');
        $this->scheduler = $this->di->getShared('scheduler');
        $this->sheet = $this->di->getShared('matchSheet');
        $this->incidents = $this->di->getShared('matchIncidents');
        $this->catalog = $this->di->getShared('catalog');
        $this->reports = $this->di->getShared('reports');
        $this->state = $this->di->getShared('state');
        $this->clock = $this->di->getShared('clock');
    }

    protected function tearDown(): void {
        $this->di->getShared('db')->close();
        if (is_file($this->databaseFile)) unlink($this->databaseFile);
    }

    /** Asserts that the operation is refused with an HTTP error, optionally a specific status and field. */
    protected function assertRejected(callable $operation, ?int $status = null, ?string $field = null): HttpException {
        try {
            $operation();
        } catch (HttpException $e) {
            $this->assertGreaterThanOrEqual(400, $e->status, $e->getMessage());
            if ($status !== null) $this->assertSame($status, $e->status, $e->getMessage() . ' ' . json_encode($e->fields, JSON_UNESCAPED_UNICODE));
            if ($field !== null) $this->assertArrayHasKey($field, $e->fields, $e->getMessage() . ' ' . json_encode($e->fields, JSON_UNESCAPED_UNICODE));
            return $e;
        }
        $this->fail('Se esperaba que la operación fuera rechazada.');
    }

    protected function createTournament(array $overrides = []): array {
        static $sequence = 0;
        return $this->tournaments->save($overrides + [
            'name' => 'Torneo de prueba ' . ++$sequence,
            'type' => 'knockout',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
        ] + self::TIE_RULES);
    }

    /** Tournament with the given teams enrolled in that order and registration closed. */
    protected function closedTournament(array $teamIds, string $drawMethod = 'manual', array $overrides = []): int {
        $id = (int)$this->createTournament($overrides)['id'];
        $this->tournaments->openRegistration($id);
        foreach ($teamIds as $teamId) $this->tournaments->enroll($id, $teamId);
        $this->tournaments->closeRegistration($id, $drawMethod);
        return $id;
    }

    /** Knockout tournament with its bracket generated. @return array the tournament detail */
    protected function bracket(array $teamIds, array $overrides = []): array {
        $id = $this->closedTournament($teamIds, 'manual', $overrides);
        $this->tournaments->generateBracket($id);
        return $this->tournaments->detail($id);
    }

    /** League with the given teams, one matchday per week from 2026-10-17, calendar generated. @return array detail */
    protected function league(array $teamIds, int $rounds = 1, array $overrides = []): array {
        $id = $this->closedTournament($teamIds, 'manual', $overrides + [
            'type' => 'league', 'league_rounds' => $rounds, 'points_win' => 3, 'points_draw' => 1, 'points_loss' => 0,
            'start_date' => '2026-10-16', 'end_date' => '2027-06-30',
        ]);
        $preview = $this->tournaments->previewFixture($id, ['first_date' => '2026-10-17', 'interval_days' => 7]);
        $this->tournaments->generateFixture($id, ['game_dates' => array_column($preview['matchdays'], 'game_date')]);
        return $this->tournaments->detail($id);
    }

    protected function tournament(int $id): Tournament {
        return $this->tournaments->find($id);
    }

    protected function matchModel(int $tournamentId, int $matchId): TournamentMatch {
        return $this->tournaments->match($this->tournament($tournamentId), $matchId);
    }

    protected function match(array $detail, int $round, int $number): array {
        foreach ($detail['matches'] as $match) {
            if ((int)$match['round_number'] === $round && (int)$match['match_number'] === $number) return $match;
        }
        $this->fail("No existe el partido {$round}-{$number}.");
    }

    protected function matchById(int $tournamentId, int $matchId): array {
        return $this->matchModel($tournamentId, $matchId)->toArray();
    }

    protected function schedule(int $tournamentId, int $matchId, string $at, string $venue = 'Cancha 1', int $refereeId = self::REFEREE_ID): void {
        $this->scheduler->schedule($this->tournament($tournamentId), $this->matchModel($tournamentId, $matchId), ['scheduled_at' => $at, 'venue' => $venue, 'referee_user_id' => $refereeId], self::ADMIN_ID);
    }

    protected function scheduleAndStart(int $tournamentId, int $matchId, string $at, string $venue = 'Cancha 1'): void {
        $this->schedule($tournamentId, $matchId, $at, $venue);
        $this->sheet->start($this->matchModel($tournamentId, $matchId), self::REFEREE_ID);
    }

    protected function submit(int $tournamentId, int $matchId, array $input, int $actor = self::REFEREE_ID): void {
        $this->sheet->submitResult($this->tournament($tournamentId), $this->matchModel($tournamentId, $matchId), $actor, $input);
    }

    protected function confirm(int $tournamentId, int $matchId, int $actor = self::REFEREE_ID): void {
        $this->sheet->confirm($this->tournament($tournamentId), $this->matchModel($tournamentId, $matchId), $actor);
    }

    /** Plays a scheduled-and-started match to a confirmed result with the given events. */
    protected function play(int $tournamentId, int $matchId, string $at, array $events, array $extra = []): void {
        $this->scheduleAndStart($tournamentId, $matchId, $at);
        $this->submit($tournamentId, $matchId, ['goal_events' => $events] + $extra);
        $this->confirm($tournamentId, $matchId);
    }

    protected static function goal(array $player, int $minute, int $period = 0): array {
        return ['player_id' => (int)$player['id'], 'minute' => $minute, 'period_number' => $period];
    }

    protected static function ownGoal(array $player, int $minute, int $period = 0): array {
        return self::goal($player, $minute, $period) + ['own_goal' => 1];
    }

    /** @return list<array> players of the team ordered by id */
    protected function playersOf(int $teamId): array {
        return array_values(array_filter($this->state->state()['players'], fn($player) => (int)$player['team_id'] === $teamId));
    }

    protected function rows(string $table, string $where = '1 = 1', array $bind = []): array {
        return $this->di->getShared('db')->fetchAll("SELECT * FROM {$table} WHERE {$where}", \Phalcon\Db\Enum::FETCH_ASSOC, $bind);
    }
}
