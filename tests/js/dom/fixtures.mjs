/**
 * A league of three teams (1, 2, 3) in progress. Matchday 1 (team 3 rests): 1 vs 2 confirmed 2–1.
 * Matchday 2 (team 2 rests): 3 vs 1 unprogrammed. Matchday 3 (team 1 rests): 2 vs 3 postponed.
 * Matchday 4 is an empty extraordinary matchday.
 */
export function leagueDetail(overrides = {}) {
  const matchday = (id, number, game_date, kind = 'regular', label = null) => ({ id, tournament_id: 2, number, game_date, end_date: null, kind, label });
  const base = {
    id: 2, name: 'Liga Apertura', type: 'league', status: 'in_progress', start_date: '2026-10-16', end_date: '2026-12-31', is_legacy: 0,
    match_duration_minutes: 60, league_rounds: 1, points_win: 3, points_draw: 1, points_loss: 0, extra_time_periods: 0,
    draw_method: 'manual', registrations_closed_at: '2026-10-16 08:00:00', fixture_generated_at: '2026-10-16 08:30:00', champion_team_id: null,
    participants: [1, 2, 3].map((team_id, index) => ({ id: index + 10, tournament_id: 2, team_id, seed_order: index + 1 })),
    matchdays: [matchday(21, 1, '2026-10-17'), matchday(22, 2, '2026-10-24'), matchday(23, 3, '2026-10-31'), matchday(24, 4, '2026-11-07', 'extraordinary', 'Reposición')],
    matches: [
      { id: 31, tournament_id: 2, round_number: 1, match_number: 1, leg: 1, matchday_id: 21, home_team_id: 1, away_team_id: 2, status: 'completed', workflow_status: 'confirmed', scheduled_at: '2026-10-17 10:00:00', venue: 'Cancha 1', referee_user_id: 2, home_score: 2, away_score: 1, winner_team_id: 1, decided_by: 'regulation' },
      { id: 32, tournament_id: 2, round_number: 2, match_number: 1, leg: 1, matchday_id: 22, home_team_id: 3, away_team_id: 1, status: 'pending', workflow_status: 'unprogrammed', scheduled_at: null, venue: null, referee_user_id: null, home_score: null, away_score: null, winner_team_id: null },
      { id: 33, tournament_id: 2, round_number: 3, match_number: 1, leg: 1, matchday_id: 23, home_team_id: 2, away_team_id: 3, status: 'pending', workflow_status: 'suspended', suspension_reason: 'Cancha inundada.', scheduled_at: null, venue: null, referee_user_id: null, home_score: null, away_score: null, winner_team_id: null },
    ],
    goal_events: [],
    penalty_kicks: [],
    incidents: [],
    scorers: [],
    matchday_count: 3,
    standings: [
      { position: 1, team_id: 1, played: 1, won: 1, drawn: 0, lost: 0, goals_for: 2, goals_against: 1, goal_difference: 1, points: 3, form: ['G'], red_cards: 0, yellow_cards: 0, tie_unresolved: false },
      { position: 2, team_id: 3, played: 0, won: 0, drawn: 0, lost: 0, goals_for: 0, goals_against: 0, goal_difference: 0, points: 0, form: [], red_cards: 0, yellow_cards: 0, tie_unresolved: false },
      { position: 3, team_id: 2, played: 1, won: 0, drawn: 0, lost: 1, goals_for: 1, goals_against: 2, goal_difference: -1, points: 0, form: ['P'], red_cards: 0, yellow_cards: 0, tie_unresolved: false },
    ],
    lifecycle: {
      steps: [
        { key: 'setup', label: 'Configuración', state: 'done' },
        { key: 'registration', label: 'Inscripción de equipos', state: 'done' },
        { key: 'schedule', label: 'Calendario de jornadas', state: 'done' },
        { key: 'matches', label: 'Partidos', state: 'current' },
        { key: 'result', label: 'Tabla final', state: 'pending' },
      ],
      next_action: null,
      hint: 'Programe cada partido, regístrelo durante el juego y confirme el resultado.',
      blockers: [],
    },
  };
  return { ...base, ...overrides };
}

// A knockout tournament in progress: semifinal 1 (Leones 1 vs Estrellas 4) is being played,
// semifinal 2 (Halcones 2 vs Guerreros 3) is not scheduled, the final is undefined.
export function knockoutDetail(overrides = {}) {
  const base = {
    id: 1, name: 'Copa Apertura', type: 'knockout', status: 'in_progress', start_date: '2026-10-16', end_date: '2026-12-31',
    match_duration_minutes: 60, extra_time_periods: 2, extra_time_period_minutes: 10, penalty_attempts_per_team: 5, penalty_sudden_death: 1,
    draw_method: 'manual', registrations_closed_at: '2026-10-16 08:00:00', bracket_generated_at: '2026-10-16 08:30:00', champion_team_id: null,
    participants: [1, 4, 2, 3].map((team_id, index) => ({ id: index + 1, tournament_id: 1, team_id, seed_order: index + 1 })),
    matches: [
      { id: 11, tournament_id: 1, round_number: 1, match_number: 1, home_team_id: 1, away_team_id: 4, status: 'pending', workflow_status: 'in_progress', scheduled_at: '2026-10-20 10:00:00', venue: 'Cancha 2 Las Palmas', referee_user_id: 2, home_score: null, away_score: null, winner_team_id: null },
      { id: 12, tournament_id: 1, round_number: 1, match_number: 2, home_team_id: 2, away_team_id: 3, status: 'pending', workflow_status: 'unprogrammed', scheduled_at: null, venue: null, referee_user_id: null, home_score: null, away_score: null, winner_team_id: null },
      { id: 13, tournament_id: 1, round_number: 2, match_number: 1, home_team_id: null, away_team_id: null, status: 'pending', workflow_status: 'unprogrammed', scheduled_at: null, venue: null, referee_user_id: null, home_score: null, away_score: null, winner_team_id: null },
    ],
    goal_events: [],
    penalty_kicks: [],
    incidents: [],
    scorers: [],
    lifecycle: {
      steps: [
        { key: 'setup', label: 'Configuración', state: 'done' },
        { key: 'registration', label: 'Inscripción de equipos', state: 'done' },
        { key: 'schedule', label: 'Cuadro de eliminación', state: 'done' },
        { key: 'matches', label: 'Partidos', state: 'current' },
        { key: 'result', label: 'Campeón', state: 'pending' },
      ],
      next_action: null,
      hint: 'Programe cada partido, regístrelo durante el juego y confirme el resultado.',
      blockers: [],
    },
  };
  return { ...base, ...overrides };
}
