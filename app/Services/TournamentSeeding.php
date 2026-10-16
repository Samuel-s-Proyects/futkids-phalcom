<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\TournamentTeam;
use Phalcon\Db\Adapter\AdapterInterface;

/** Seed order of a tournament's participants (1 = first seed). */
final class TournamentSeeding
{
    public function __construct(private AdapterInterface $db) {}

    /** @return list<int> team ids in seed order */
    public function teamIds(int $tournamentId): array {
        $participants = TournamentTeam::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $tournamentId], 'order' => 'seed_order ASC']);
        return array_map('intval', array_column($participants->toArray(), 'team_id'));
    }

    /** @param list<int> $teamIds in seed order */
    public function store(int $tournamentId, array $teamIds): void {
        // seed_order is unique per tournament: move every row out of the way before renumbering.
        $this->db->execute('UPDATE tournament_teams SET seed_order = -seed_order WHERE tournament_id = ?', [$tournamentId]);
        foreach (array_values($teamIds) as $index => $teamId) {
            $this->db->execute('UPDATE tournament_teams SET seed_order = ? WHERE tournament_id = ? AND team_id = ?', [$index + 1, $tournamentId, $teamId]);
        }
    }

    /** Fixes a random seed order (random draw) and returns it. @return list<int> */
    public function shuffle(int $tournamentId): array {
        $teamIds = $this->teamIds($tournamentId);
        shuffle($teamIds);
        $this->store($tournamentId, $teamIds);
        return $teamIds;
    }
}
