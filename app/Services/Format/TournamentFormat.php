<?php
declare(strict_types=1);
namespace App\Services\Format;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Validation\Validator;

/** Rules that differ between a knockout ("clasificación") and a league ("puntos") tournament. */
interface TournamentFormat
{
    /** A league match may end level; a knockout match always needs a winner. */
    public function allowsDraws(): bool;

    /** Highest extra-time period a match may reach (0 = no extra time). */
    public function extraTimePeriods(Tournament $tournament): int;

    /**
     * Format-specific scheduling rules (knockout: after the feeding matches; league: inside the matchday).
     * Problems are reported on the validator under "scheduled_at".
     */
    public function validateSchedule(Tournament $tournament, TournamentMatch $match, \DateTimeImmutable $start, Validator $validator): void;

    /** Called once a result is confirmed: advance the winner, close the tournament, etc. */
    public function onResultConfirmed(Tournament $tournament, TournamentMatch $match): void;

    /** Throws when a confirmed result can no longer be corrected (e.g. the next round already started). */
    public function assertCorrectable(Tournament $tournament, TournamentMatch $match): void;

    /** Undo the consequences of a confirmed result that is being corrected. */
    public function onResultReopened(Tournament $tournament, TournamentMatch $match): void;
}
