<?php
namespace App\Models;
class TournamentMatchPenalty extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_match_id;
    public $kick_number;
    public $team_id;
    public $scored;
    public function initialize(): void {
        $this->setSource('tournament_match_penalties');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
    }
}
