<?php
namespace App\Models;
/** A matchday (jornada) of a points tournament. "extraordinary" ones are added to replay postponed matches. */
class Matchday extends Model
{
    public const REGULAR = 'regular';
    public const EXTRAORDINARY = 'extraordinary';

    public $id;
    public $tournament_id;
    public $number;
    public $game_date;
    public $end_date;
    public $kind;
    public $label;
    public $created_at;
    public $updated_at;
    public function initialize(): void {
        $this->setSource('matchdays');
        $this->belongsTo('tournament_id', Tournament::class, 'id', ['alias' => 'Tournament']);
        $this->hasMany('id', Goal::class, 'matchday_id', ['alias' => 'Goals']);
        $this->hasMany('id', TournamentMatch::class, 'matchday_id', ['alias' => 'Matches']);
    }
    public function beforeValidationOnCreate(): void {
        $this->created_at = $this->updated_at = date('Y-m-d H:i:s');
        $this->kind ??= self::REGULAR;
    }
    public function beforeValidationOnUpdate(): void { $this->updated_at = date('Y-m-d H:i:s'); }

    /** Last day of the matchday (a matchday may span a weekend). */
    public function lastDay(): string {
        return (string)($this->end_date ?: $this->game_date);
    }
}
