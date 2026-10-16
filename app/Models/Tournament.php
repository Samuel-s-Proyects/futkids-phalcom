<?php
namespace App\Models;
class Tournament extends Model
{
    public const KNOCKOUT = 'knockout';
    public const LEAGUE = 'league';

    public $id;
    public $name;
    public $type;
    public $start_date;
    public $end_date;
    public $match_duration_minutes;
    public $extra_time_periods;
    public $extra_time_period_minutes;
    public $penalty_attempts_per_team;
    public $penalty_sudden_death;
    public $league_rounds;
    public $points_win;
    public $points_draw;
    public $points_loss;
    public $status;
    public $draw_method;
    public $is_legacy;
    public $registrations_closed_at;
    public $bracket_generated_at;
    public $fixture_generated_at;
    public $started_at;
    public $finished_at;
    public $champion_team_id;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('tournaments');
        $this->hasMany('id', TournamentTeam::class, 'tournament_id', ['alias' => 'Participants']);
        $this->hasMany('id', TournamentMatch::class, 'tournament_id', ['alias' => 'Matches']);
        $this->belongsTo('champion_team_id', Team::class, 'id', ['alias' => 'Champion']);
    }
    public function beforeValidationOnCreate(): void {
        $this->created_at = $this->updated_at = date('Y-m-d H:i:s');
        $this->status = 'draft';
        // Phalcon treats a zero column default as "no default", so defaults are applied here explicitly.
        $this->type ??= self::KNOCKOUT;
        $this->draw_method ??= 'random';
        $this->match_duration_minutes ??= 60;
        $this->points_win ??= 3;
        $this->points_draw ??= 1;
        $this->points_loss ??= 0;
        $this->is_legacy ??= 0;
    }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }
    public function isKnockout(): bool { return $this->type === self::KNOCKOUT; }
    public function isLeague(): bool { return $this->type === self::LEAGUE; }
}
