<?php
namespace App\Models;
class TournamentMatch extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_id;
    public $round_number;
    public $match_number;
    public $home_team_id;
    public $away_team_id;
    public $winner_team_id;
    public $scheduled_at;
    public $first_kicker_team_id;
    public $status;
    public $workflow_status;
    public $venue;
    public $matchday_id;
    public $referee_user_id;
    public $started_at;
    public $confirmed_at;
    public $confirmed_by_user_id;
    public $result_revision;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('tournament_matches');
        $this->belongsTo('tournament_id', Tournament::class, 'id', ['alias' => 'Tournament']);
        $this->belongsTo('home_team_id', Team::class, 'id', ['alias' => 'HomeTeam']);
        $this->belongsTo('away_team_id', Team::class, 'id', ['alias' => 'AwayTeam']);
        $this->belongsTo('winner_team_id', Team::class, 'id', ['alias' => 'WinnerTeam']);
        $this->belongsTo('matchday_id', Matchday::class, 'id', ['alias' => 'Matchday']);
        $this->belongsTo('referee_user_id', User::class, 'id', ['alias' => 'Referee']);
        $this->belongsTo('confirmed_by_user_id', User::class, 'id', ['alias' => 'ConfirmedBy']);
        $this->hasMany('id', TournamentMatchGoalEvent::class, 'tournament_match_id', ['alias' => 'GoalEvents']);
    }
    public function beforeValidationOnCreate(): void { $this->created_at = $this->updated_at = date('Y-m-d H:i:s'); }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }
}
