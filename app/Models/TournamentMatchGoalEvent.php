<?php
namespace App\Models;
class TournamentMatchGoalEvent extends Model
{
    public $id;
    public $tournament_match_id;
    public $player_id;
    /** Team of the player who touched the ball last. */
    public $team_id;
    /** Team the goal counts for (the opponent on an own goal). */
    public $credited_team_id;
    public $minute;
    /** 0 = regulation, 1..n = extra-time periods. */
    public $period_number;
    public $own_goal;
    public $created_by_user_id;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('tournament_match_goal_events');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
        $this->belongsTo('player_id', Player::class, 'id', ['alias' => 'Player']);
        $this->belongsTo('team_id', Team::class, 'id', ['alias' => 'Team']);
        $this->belongsTo('credited_team_id', Team::class, 'id', ['alias' => 'CreditedTeam']);
        $this->belongsTo('created_by_user_id', User::class, 'id', ['alias' => 'CreatedBy']);
    }
    public function beforeValidationOnCreate(): void {
        $this->created_at = $this->updated_at = date('Y-m-d H:i:s');
        $this->period_number ??= 0;
        $this->own_goal ??= 0;
        $this->credited_team_id ??= $this->own_goal ? null : $this->team_id;
    }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }
}
