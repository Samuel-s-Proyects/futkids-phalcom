// Helpers shared by the match dialogs.
import { api } from '../../core/api.js';
import { applyTournament, isAdmin, isReferee, loadTournament, playersOf, store } from '../../core/store.js';
import { rerender } from '../../core/router.js';

export const currentMatch = id => store.tournament?.matches.find(m => +m.id === +id);

/** The tournament detail containing the match, loading it when the match belongs to another tournament. */
export async function ensureTournamentFor(matchId, tournamentId = 0) {
  if (tournamentId && +store.tournament?.id !== +tournamentId) await loadTournament(tournamentId);
  return currentMatch(matchId);
}

/** Administrators, or the referee assigned to this match. */
export const canOperate = match => isAdmin() || (isReferee() && +match.referee_user_id === +store.user.id);
export const isAssignedReferee = match => isReferee() && +match.referee_user_id === +store.user.id;

export const matchDate = match => String(match.scheduled_at ?? '').slice(0, 10);

/** Players of a team who are not suspended on the match date (a red card in this same match does not count). */
export function eligiblePlayers(match, teamId) {
  const day = matchDate(match);
  const suspended = new Set(store.data.incidents
    .filter(i => i.card_type === 'red' && i.suspension_date === day && +i.tournament_match_id !== +match.id)
    .map(i => +i.player_id));
  return playersOf(teamId).filter(p => !suspended.has(+p.id));
}

export const matchEvents = match => (store.tournament?.goal_events ?? [])
  .filter(e => +e.tournament_match_id === +match.id)
  .sort((a, b) => a.period_number - b.period_number || a.minute - b.minute || a.id - b.id);

export const matchIncidents = match => (store.tournament?.incidents ?? []).filter(i => +i.tournament_match_id === +match.id);

/** POST/DELETE on /tournaments/{t}/matches/{m}/…; refreshes the tournament page and returns the match. */
export async function matchChange(match, path, { method = 'POST', body } = {}) {
  const { record } = await api(`/tournaments/${match.tournament_id}/matches/${match.id}${path}`, { method, body: method === 'DELETE' ? undefined : (body ?? {}) });
  applyTournament(record);
  // The catalogue keeps incidents (suspensions) used by other screens.
  store.data.incidents = [...store.data.incidents.filter(i => +i.tournament_match_id !== +match.id), ...record.incidents.filter(i => +i.tournament_match_id === +match.id)];
  rerender();
  return currentMatch(match.id);
}
