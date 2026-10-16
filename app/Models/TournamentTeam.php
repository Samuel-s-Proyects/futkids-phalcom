<?php
namespace App\Models;
class TournamentTeam extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_id;
    public $team_id;
    public $seed_order;
    public $created_at;
    public function initialize(): void {
        $this->setSource('tournament_teams');
        $this->belongsTo('tournament_id', Tournament::class, 'id', ['alias' => 'Tournament']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
    }
    public function beforeValidationOnCreate(): void { $this->created_at = date('Y-m-d H:i:s'); }
}
