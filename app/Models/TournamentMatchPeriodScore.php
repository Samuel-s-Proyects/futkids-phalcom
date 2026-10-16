<?php
namespace App\Models;
class TournamentMatchPeriodScore extends \Phalcon\Mvc\Model
{
    public $id;
    public $tournament_match_id;
    public $period_number;
    public $home_score;
    public $away_score;
    public $home_own_goals;
    public $away_own_goals;
    public function initialize(): void {
        $this->setSource('tournament_match_period_scores');
        $this->belongsTo('tournament_match_id', TournamentMatch::class, 'id', ['alias' => 'Match']);
    }
}
