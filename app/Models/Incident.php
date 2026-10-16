<?php
namespace App\Models;
class Incident extends Model
{
    public $id;
    public $player_id;
    public $description;
    public $card_type;
    public $incident_date;
    public $suspension_date;
    public $tournament_match_id;
    public $team_id;
    public $minute;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('incidents');
        $this->belongsTo('player_id', Player::class, 'id', ['alias' => 'Player']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
    }
    public function beforeValidationOnCreate(): void { $this->created_at = $this->updated_at = date('Y-m-d H:i:s'); }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }
}
