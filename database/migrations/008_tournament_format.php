<?php
declare(strict_types=1);

use App\Support\Clock;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * Generating the bracket now starts the tournament, and a finished tournament records its champion.
 * Existing rows are brought in line with those rules. Safe to run more than once.
 */
return static function (AdapterInterface $db, string $driver, Clock $clock): void {
    $db->execute(
        "UPDATE tournaments SET status = 'in_progress'
         WHERE bracket_generated_at IS NOT NULL AND status IN ('draft', 'registration')"
    );
    $db->execute('UPDATE tournaments SET started_at = bracket_generated_at WHERE started_at IS NULL AND bracket_generated_at IS NOT NULL');
    $finished = $db->fetchAll("SELECT id FROM tournaments WHERE status = 'finished' AND champion_team_id IS NULL", Enum::FETCH_ASSOC);
    foreach ($finished as $tournament) {
        $final = $db->fetchOne(
            'SELECT winner_team_id, confirmed_at FROM tournament_matches WHERE tournament_id = ? ORDER BY round_number DESC, match_number ASC LIMIT 1',
            Enum::FETCH_ASSOC,
            [(int)$tournament['id']],
        );
        if (!$final || $final['winner_team_id'] === null) continue;
        $db->execute(
            'UPDATE tournaments SET champion_team_id = ?, finished_at = ? WHERE id = ?',
            [(int)$final['winner_team_id'], $final['confirmed_at'] ?? $clock->now()->format('Y-m-d H:i:s'), (int)$tournament['id']],
        );
    }
};
