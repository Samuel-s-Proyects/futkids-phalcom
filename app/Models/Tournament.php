<?php
namespace App\Models;
class Tournament extends \Phalcon\Mvc\Model
{
    public $id;
    public $name;
    public $start_date;
    public $end_date;
    public $extra_time_periods;
    public $extra_time_period_minutes;
    public $penalty_attempts_per_team;
    public $penalty_sudden_death;
    public $status;
    public $draw_method;
    public $registrations_closed_at;
    public $bracket_generated_at;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('tournaments');
        $this->hasMany('id', TournamentTeam::class, 'tournament_id', ['alias' => 'Participants']);
        $this->hasMany('id', TournamentMatch::class, 'tournament_id', ['alias' => 'Matches']);
    }
    public function beforeValidationOnCreate(): void {
        $this->created_at = $this->updated_at = date('Y-m-d H:i:s');
        $this->status = 'draft';
        $this->draw_method = 'random';
    }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }
}
