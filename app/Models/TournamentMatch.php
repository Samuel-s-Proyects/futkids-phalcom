<?php
namespace App\Models;
class TournamentMatch extends Model
{
    /** Workflow states. "suspended" is a match that could not be played and must be rescheduled. */
    public const UNPROGRAMMED = 'unprogrammed';
    public const SCHEDULED = 'scheduled';
    public const IN_PROGRESS = 'in_progress';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const CONFIRMED = 'confirmed';
    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';
    public const BYE = 'bye';

    public $id;
    public $tournament_id;
    public $round_number;
    public $match_number;
    public $leg;
    public $home_team_id;
    public $away_team_id;
    public $winner_team_id;
    public $home_score;
    public $away_score;
    public $decided_by;
    public $home_penalties;
    public $away_penalties;
    public $scheduled_at;
    public $first_kicker_team_id;
    public $status;
    public $workflow_status;
    public $venue;
    public $venue_key;
    public $matchday_id;
    public $original_matchday_id;
    public $suspension_reason;
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

    /** @return list<int> */
    public function teamIds(): array {
        return array_values(array_filter([(int)$this->home_team_id, (int)$this->away_team_id]));
    }

    public function involves(int $teamId): bool {
        return in_array($teamId, $this->teamIds(), true);
    }

    public function opponentOf(int $teamId): int {
        return $teamId === (int)$this->home_team_id ? (int)$this->away_team_id : (int)$this->home_team_id;
    }

    public function matchDate(): string {
        return substr((string)$this->scheduled_at, 0, 10);
    }
}
