<?php
declare(strict_types=1);

use App\Support\Clock;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * Matchdays (jornadas) now belong to a points (league) tournament, numbered per tournament.
 *
 * Matchdays recorded before tournaments existed, with their goals and suspensions, are kept intact
 * (same ids) inside a finished "Torneo histórico" that enrolls every team involved. Knockout matches
 * no longer use matchdays: any link is removed and written to the match audit. Safe to run more than once.
 */
return static function (AdapterInterface $db, string $driver, Clock $clock): void {
    $columns = array_map(fn($column) => $column->getName(), $db->describeColumns('matchdays'));
    if (in_array('tournament_id', $columns, true)) return;

    $now = $clock->now()->format('Y-m-d H:i:s');
    $range = $db->fetchOne('SELECT COUNT(*) AS total, MIN(game_date) AS first_day, MAX(game_date) AS last_day FROM matchdays', Enum::FETCH_ASSOC);
    $legacyId = null;
    if ((int)$range['total'] > 0) {
        $db->insertAsDict('tournaments', [
            'name' => 'Torneo histórico ' . substr((string)$range['first_day'], 0, 4),
            'type' => 'league',
            'start_date' => $range['first_day'],
            'end_date' => $range['last_day'],
            'status' => 'finished',
            'draw_method' => 'manual',
            'league_rounds' => 1,
            'points_win' => 3,
            'points_draw' => 1,
            'points_loss' => 0,
            'match_duration_minutes' => 60,
            'is_legacy' => 1,
            'registrations_closed_at' => $now,
            'fixture_generated_at' => $now,
            'started_at' => $range['first_day'] . ' 00:00:00',
            'finished_at' => $range['last_day'] . ' 23:59:59',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $legacyId = (int)$db->lastInsertId();
        $teams = $db->fetchAll(
            'SELECT DISTINCT p.team_id FROM players p WHERE p.id IN (SELECT player_id FROM goals UNION SELECT player_id FROM incidents) ORDER BY p.team_id',
            Enum::FETCH_ASSOC,
        );
        foreach (array_values($teams) as $index => $team) {
            $db->insertAsDict('tournament_teams', ['tournament_id' => $legacyId, 'team_id' => (int)$team['team_id'], 'seed_order' => $index + 1, 'created_at' => $now]);
        }
    }

    // Knockout matches are organised by rounds: detach them from matchdays, keeping a trace.
    $auditor = $db->fetchOne("SELECT MIN(id) AS id FROM users WHERE role = 'admin'", Enum::FETCH_ASSOC)['id'] ?? null;
    foreach ($db->fetchAll('SELECT id, matchday_id, result_revision FROM tournament_matches WHERE matchday_id IS NOT NULL', Enum::FETCH_ASSOC) as $match) {
        if ($auditor !== null) {
            $db->insertAsDict('tournament_match_result_audit', [
                'tournament_match_id' => (int)$match['id'],
                'actor_user_id' => (int)$auditor,
                'action' => 'matchday_detached',
                'revision' => (int)$match['result_revision'],
                'audit_snapshot' => json_encode(['matchday_id' => (int)$match['matchday_id']], JSON_THROW_ON_ERROR),
            ]);
        }
        $db->execute('UPDATE tournament_matches SET matchday_id = NULL WHERE id = ?', [(int)$match['id']]);
    }

    if ($driver === 'sqlite') {
        // SQLite cannot change constraints in place: rebuild the table (documented 12-step procedure).
        $db->execute('PRAGMA foreign_keys = OFF');
        $db->execute(
            "CREATE TABLE matchdays_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE RESTRICT,
                number INTEGER NOT NULL CHECK (number BETWEEN 1 AND 200),
                game_date DATE NOT NULL,
                end_date DATE NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'regular' CHECK (kind IN ('regular','extraordinary')),
                label VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tournament_id, number),
                CHECK (end_date IS NULL OR end_date >= game_date)
            )"
        );
        $db->execute(
            'INSERT INTO matchdays_new (id, tournament_id, number, game_date, created_at, updated_at)
             SELECT id, ?, number, game_date, created_at, updated_at FROM matchdays',
            [$legacyId ?? 0],
        );
        $db->execute('DROP TABLE matchdays');
        $db->execute('ALTER TABLE matchdays_new RENAME TO matchdays');
        $db->execute('CREATE INDEX idx_matchdays_tournament_date ON matchdays (tournament_id, game_date)');
        $problems = $db->fetchAll('PRAGMA foreign_key_check', Enum::FETCH_ASSOC);
        $db->execute('PRAGMA foreign_keys = ON');
        if ($problems) throw new RuntimeException('La migración de jornadas dejó referencias inválidas: ' . json_encode($problems));
        return;
    }

    $db->execute('ALTER TABLE matchdays ADD COLUMN tournament_id INTEGER NULL AFTER id, ADD COLUMN end_date DATE NULL, ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT \'regular\', ADD COLUMN label VARCHAR(100) NULL');
    if ($legacyId !== null) $db->execute('UPDATE matchdays SET tournament_id = ?', [$legacyId]);
    foreach (array_keys($db->describeIndexes('matchdays')) as $index) {
        $definition = $db->describeIndexes('matchdays')[$index];
        if ($index !== 'PRIMARY' && $definition->getType() === 'UNIQUE') $db->execute("ALTER TABLE matchdays DROP INDEX `{$index}`");
    }
    $db->execute(
        'ALTER TABLE matchdays
            MODIFY tournament_id INTEGER NOT NULL,
            ADD CONSTRAINT uq_matchdays_tournament_number UNIQUE (tournament_id, number),
            ADD CONSTRAINT fk_matchdays_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
            ADD CONSTRAINT chk_matchdays_kind CHECK (kind IN (\'regular\',\'extraordinary\')),
            ADD CONSTRAINT chk_matchdays_range CHECK (end_date IS NULL OR end_date >= game_date),
            ADD INDEX idx_matchdays_tournament_date (tournament_id, game_date)'
    );
};
