<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Incident;

/** A red card suspends the player for exactly one date (its suspension_date). */
final class SuspensionService
{
    /** @param int $ignoreMatchId a red card shown in this match does not suspend the player for the same match */
    public function isSuspended(int $playerId, string $date, int $ignoreMatchId = 0): bool {
        return Incident::count([
            'conditions' => "player_id = :player: AND card_type = 'red' AND suspension_date = :date: AND (tournament_match_id IS NULL OR tournament_match_id != :match:)",
            'bind' => ['player' => $playerId, 'date' => $date, 'match' => $ignoreMatchId],
        ]) > 0;
    }
}
