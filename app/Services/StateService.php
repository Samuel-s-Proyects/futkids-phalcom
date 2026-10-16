<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Goal;
use App\Models\Incident;
use App\Models\Matchday;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchGoal;
use App\Models\TournamentMatchGoalEvent;
use App\Models\TournamentMatchPenalty;
use App\Models\TournamentMatchPeriodScore;
use App\Models\TournamentTeam;

/** Snapshot of the catalogue and tournaments loaded by the interface at start-up. */
final class StateService
{
    public function __construct(private TournamentService $tournaments) {}

    public function state(): array {
        return [
            'teams' => Team::find(['order' => 'id'])->toArray(),
            'players' => Player::find(['order' => 'id'])->toArray(),
            'matchdays' => Matchday::find(['order' => 'number'])->toArray(),
            'goals' => Goal::find(['order' => 'id'])->toArray(),
            'incidents' => Incident::find(['order' => 'id'])->toArray(),
            'tournaments' => $this->tournaments->list(),
            'tournament_teams' => TournamentTeam::find(['order' => 'tournament_id, seed_order'])->toArray(),
            'tournament_matches' => TournamentMatch::find(['order' => 'tournament_id, round_number, match_number'])->toArray(),
            'tournament_match_period_scores' => TournamentMatchPeriodScore::find(['order' => 'tournament_match_id, period_number'])->toArray(),
            'tournament_match_goals' => TournamentMatchGoal::find(['order' => 'tournament_match_id, player_id'])->toArray(),
            'tournament_match_goal_events' => TournamentMatchGoalEvent::find(['order' => 'tournament_match_id, period_number, minute, id'])->toArray(),
            'tournament_match_penalties' => TournamentMatchPenalty::find(['order' => 'tournament_match_id, kick_number'])->toArray(),
            'referees' => User::find(['conditions' => "role = 'referee' AND active = 1", 'columns' => 'id, name, email', 'order' => 'name'])->toArray(),
        ];
    }
}
