<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Models\TournamentMatch;
use App\Models\TournamentMatchResultAudit;

/** Append-only trail of every change to a match (schedule, goals, incidents, results). */
final class MatchAudit
{
    public function record(TournamentMatch $match, int $actorId, string $action, array $snapshot): void {
        $audit = new TournamentMatchResultAudit();
        $audit->assign([
            'tournament_match_id' => (int)$match->id,
            'actor_user_id' => $actorId,
            'action' => $action,
            'revision' => (int)$match->result_revision,
            'audit_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $audit->saveOrFail('No se pudo guardar la trazabilidad del partido.');
    }

    /** @return list<array> newest first */
    public function history(int $matchId): array {
        return TournamentMatchResultAudit::find([
            'conditions' => 'tournament_match_id = :match:',
            'bind' => ['match' => $matchId],
            'order' => 'id DESC',
        ])->toArray();
    }
}
