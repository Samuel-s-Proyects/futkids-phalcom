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
