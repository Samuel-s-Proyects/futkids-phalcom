// Score derived from goal events (mirror of App\Services\Match\Scoreboard).
// Event: {player_id, team_id, credited_team_id, own_goal, period_number}

/** @returns {{home: number, away: number, regulation: {home: number, away: number}}} */
export function tally(events, homeTeamId, awayTeamId) {
  const result = { home: 0, away: 0, regulation: { home: 0, away: 0 } };
  for (const event of events) {
    const side = +event.credited_team_id === +homeTeamId ? 'home' : +event.credited_team_id === +awayTeamId ? 'away' : null;
    if (!side) continue;
    result[side]++;
    if (+event.period_number === 0) result.regulation[side]++;
  }
  return result;
}

/** Team the goal counts for: the scorer's team, or the opponent on an own goal. */
export const creditedTeam = (playerTeamId, ownGoal, homeTeamId, awayTeamId) =>
  ownGoal ? (+playerTeamId === +homeTeamId ? +awayTeamId : +homeTeamId) : +playerTeamId;

export const periodLabel = (period, extraMinutes) => (+period === 0 ? 'Tiempo reglamentario' : `Tiempo extra ${period}${extraMinutes ? ` (${extraMinutes} min)` : ''}`);
