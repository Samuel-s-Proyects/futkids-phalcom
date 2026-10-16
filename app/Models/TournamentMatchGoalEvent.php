<?php
namespace App\Models;
class TournamentMatchGoalEvent extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_match_id;
    public $player_id;
    public $team_id;
    public $minute;
    public $own_goal;
    public $created_by_user_id;
    public $created_at;
    public function initialize(): void {
        $this->setSource('tournament_match_goal_events');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
        $this->belongsTo('player_id', Player::class, 'id', ['alias' => 'Player']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
        $this->belongsTo('created_by_user_id', User::class, 'id', ['alias' => 'CreatedBy']);
    }
}
