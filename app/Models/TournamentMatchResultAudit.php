<?php
namespace App\Models;
class TournamentMatchResultAudit extends Model
{
    public $id;
    public $tournament_match_id;
    public $actor_user_id;
    public $action;
    public $revision;
    public $audit_snapshot;
    public $created_at;
    public function initialize(): void {
        $this->setSource('tournament_match_result_audit');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
        $this->belongsTo('actor_user_id', User::class, 'id', ['alias' => 'Actor']);
    }
}
