<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Exceptions\HttpException;

/**
 * Validates a penalty shoot-out kick by kick and determines its winner.
 *
 * Teams alternate starting with $firstKicker. The shoot-out is decided early when one team can no longer
 * be caught within the initial attempts; after the initial attempts a tie goes to sudden death (if allowed),
 * where each pair of kicks can decide it. No kick may follow the deciding one.
 */
final class PenaltyShootout
{
    /**
     * @param list<array{team_id: int, scored: int}> $kicks
     * @return array{winner_team_id: int, home: int, away: int}
     */
    public static function evaluate(array $kicks, int $firstKicker, int $homeTeamId, int $awayTeamId, int $initialAttempts, bool $suddenDeath): array {
        if (!in_array($firstKicker, [$homeTeamId, $awayTeamId], true)) {
            throw new HttpException('Seleccione uno de los equipos como primer pateador.', 422, ['first_kicker_team_id' => 'Elija el equipo que patea primero.']);
        }
        if (!$kicks) throw new HttpException('Ingrese los tiros de la tanda de penales.', 422, ['penalty_kicks' => 'Registre cada tiro.']);
        $secondKicker = $firstKicker === $homeTeamId ? $awayTeamId : $homeTeamId;
        $attempts = [$homeTeamId => 0, $awayTeamId => 0];
        $goals = [$homeTeamId => 0, $awayTeamId => 0];
        $inSuddenDeath = false;
        $winner = 0;
        foreach ($kicks as $index => $kick) {
            if ($winner) throw new HttpException('La tanda continuó después de que ya había un ganador.', 422, ['penalty_kicks' => 'Quite los tiros posteriores al definitivo.']);
            $expected = $index % 2 === 0 ? $firstKicker : $secondKicker;
            if ($kick['team_id'] !== $expected) throw new HttpException('Los pateadores deben alternarse durante la tanda.', 422, ['penalty_kicks' => 'Revise el orden de los tiros.']);
            $attempts[$expected]++;
            $goals[$expected] += $kick['scored'];
            if (!$inSuddenDeath) {
                $homeLeft = max(0, $initialAttempts - $attempts[$homeTeamId]);
                $awayLeft = max(0, $initialAttempts - $attempts[$awayTeamId]);
                if ($goals[$homeTeamId] > $goals[$awayTeamId] + $awayLeft) $winner = $homeTeamId;
                elseif ($goals[$awayTeamId] > $goals[$homeTeamId] + $homeLeft) $winner = $awayTeamId;
                elseif (!$homeLeft && !$awayLeft) {
                    if (!$suddenDeath) throw new HttpException('La tanda terminó empatada y este torneo no aplica muerte súbita.', 409, ['penalty_kicks' => 'Revise los tiros: la tanda debe tener ganador.']);
                    $inSuddenDeath = true;
                }
            } elseif ($attempts[$homeTeamId] === $attempts[$awayTeamId] && $goals[$homeTeamId] !== $goals[$awayTeamId]) {
                $winner = $goals[$homeTeamId] > $goals[$awayTeamId] ? $homeTeamId : $awayTeamId;
            }
        }
        if (!$winner) throw new HttpException('La tanda debe continuar hasta que exista un ganador.', 422, ['penalty_kicks' => 'Complete los tiros iniciales y, si corresponde, la muerte súbita.']);
        return ['winner_team_id' => $winner, 'home' => $goals[$homeTeamId], 'away' => $goals[$awayTeamId]];
    }
}
