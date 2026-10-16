<?php
namespace App\Models;
class TournamentMatchGoal extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_match_id;
    public $player_id;
    public $team_id;
    public $goals;
    public function initialize(): void {
        $this->setSource('tournament_match_goals');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
        $this->belongsTo('player_id', Player::class, 'id', ['alias' => 'Player']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
    }
}
