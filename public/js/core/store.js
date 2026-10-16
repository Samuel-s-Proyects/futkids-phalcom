// Application state: the signed-in user, server settings and the data loaded from the API.
import { api } from './api.js';

const EMPTY_DATA = {
  teams: [], players: [], matchdays: [], goals: [], incidents: [], tournaments: [], tournament_teams: [],
  tournament_matches: [], tournament_match_goal_events: [], tournament_match_goals: [], referees: [],
};

export const store = {
  user: null,
  settings: { age_min: 5, age_max: 14, age_reference_date: '', validation: {}, positions: [] },
  data: structuredClone(EMPTY_DATA),
  /** Detail of the tournament being viewed (GET /tournaments/{id}). */
  tournament: null,
  /** True after a tournament change: catalogue-wide figures (dashboard, scorers) must be reloaded. */
  stale: false,
};

export const isAdmin = () => store.user?.role === 'admin';
export const isReferee = () => store.user?.role === 'referee';

export async function loadState() {
  const data = await api('/state');
  store.data = { ...structuredClone(EMPTY_DATA), ...data };
}

export async function loadTournament(id) {
  store.tournament = await api(`/tournaments/${id}`);
  return store.tournament;
}

/** Keeps the cached tournament detail and the list in sync after a change answered with the detail. */
export function applyTournament(detail) {
  store.tournament = detail;
  const index = store.data.tournaments.findIndex(t => +t.id === +detail.id);
  const summary = { ...detail, participant_count: detail.participants.length };
  if (index >= 0) store.data.tournaments[index] = summary;
  else store.data.tournaments.unshift(summary);
  store.data.tournament_matches = [...store.data.tournament_matches.filter(m => +m.tournament_id !== +detail.id), ...detail.matches];
  store.stale = true;
}

/** Reloads the catalogue when a change made it stale. */
export async function refreshIfStale() {
  if (!store.stale) return;
  store.stale = false;
  await loadState();
}

const byId = list => id => list().find(item => +item.id === +id);
export const team = byId(() => store.data.teams);
export const player = byId(() => store.data.players);
export const matchday = byId(() => store.data.matchdays);
export const referee = byId(() => store.data.referees);
export const playersOf = teamId => store.data.players.filter(p => +p.team_id === +teamId);
export const teamName = id => team(id)?.name ?? 'Por definir';
