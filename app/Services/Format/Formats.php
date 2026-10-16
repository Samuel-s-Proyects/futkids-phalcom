<?php
declare(strict_types=1);
namespace App\Services\Format;

use App\Exceptions\HttpException;
use App\Models\Tournament;
use App\Services\Knockout\KnockoutEngine;
use App\Services\League\LeagueEngine;

/** Picks the rules engine for a tournament's type. */
final class Formats
{
    /** @param array<string, TournamentFormat> $engines keyed by tournament type */
    public function __construct(private array $engines) {}

    public function for(Tournament $tournament): TournamentFormat {
        return $this->engines[(string)$tournament->type] ?? throw new HttpException('Tipo de torneo no admitido.', 409);
    }

    public function knockout(): KnockoutEngine {
        return $this->engines[Tournament::KNOCKOUT];
    }

    public function league(): LeagueEngine {
        return $this->engines[Tournament::LEAGUE];
    }
}
