<?php
declare(strict_types=1);

use App\Support\Clock;
use App\Support\TextNormalizer;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/** Stores the final score on the match itself and a comparison key for the venue. Safe to run more than once. */
return static function (AdapterInterface $db, string $driver, Clock $clock): void {
    foreach ($db->fetchAll('SELECT id, venue FROM tournament_matches WHERE venue IS NOT NULL AND venue_key IS NULL', Enum::FETCH_ASSOC) as $match) {
        $db->execute('UPDATE tournament_matches SET venue_key = ? WHERE id = ?', [TextNormalizer::key((string)$match['venue']), (int)$match['id']]);
    }
    $completed = $db->fetchAll(
        "SELECT id, home_team_id, away_team_id FROM tournament_matches WHERE status = 'completed' AND home_score IS NULL",
        Enum::FETCH_ASSOC,
    );
    foreach ($completed as $match) {
        $id = (int)$match['id'];
        $periods = $db->fetchAll('SELECT home_score, away_score FROM tournament_match_period_scores WHERE tournament_match_id = ?', Enum::FETCH_ASSOC, [$id]);
        if (!$periods) continue;
        $kicks = $db->fetchAll('SELECT team_id, scored FROM tournament_match_penalties WHERE tournament_match_id = ?', Enum::FETCH_ASSOC, [$id]);
        $penalties = static fn(int $teamId): ?int => $kicks
            ? array_sum(array_map(fn(array $kick) => (int)$kick['team_id'] === $teamId ? (int)$kick['scored'] : 0, $kicks))
            : null;
        $db->execute(
            'UPDATE tournament_matches SET home_score = ?, away_score = ?, decided_by = ?, home_penalties = ?, away_penalties = ? WHERE id = ?',
            [
                array_sum(array_map('intval', array_column($periods, 'home_score'))),
                array_sum(array_map('intval', array_column($periods, 'away_score'))),
                $kicks ? 'penalties' : (count($periods) > 1 ? 'extra_time' : 'regulation'),
                $penalties((int)$match['home_team_id']),
                $penalties((int)$match['away_team_id']),
                $id,
            ],
        );
    }
};
