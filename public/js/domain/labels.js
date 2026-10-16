// Human-readable states with the badge tone used to show them.

export const TOURNAMENT_TYPES = {
  knockout: { label: 'Clasificación', description: 'Eliminación directa: quien pierde queda fuera. Con empate se juega tiempo extra y penales.' },
  league: { label: 'Por puntos', description: 'Todos contra todos por jornadas. Se suman puntos y gana quien termina primero en la tabla.' },
};

/** @returns {{label: string, tone: string}} */
export function tournamentStatus(tournament) {
  if (tournament.status === 'registration') {
    return tournament.registrations_closed_at ? { label: 'Inscripción cerrada', tone: 'info' } : { label: 'Inscripción abierta', tone: 'primary' };
  }
  return {
    draft: { label: 'Borrador', tone: '' },
    in_progress: { label: 'En curso', tone: 'success' },
    finished: { label: 'Finalizado', tone: 'warning' },
  }[tournament.status] ?? { label: tournament.status, tone: '' };
}

const MATCH_STATES = {
  unprogrammed: { label: 'Por programar', tone: '' },
  scheduled: { label: 'Programado', tone: 'info' },
  in_progress: { label: 'En juego', tone: 'success' },
  awaiting_confirmation: { label: 'Resultado por confirmar', tone: 'warning' },
  confirmed: { label: 'Finalizado', tone: 'primary' },
  suspended: { label: 'Aplazado · por reprogramar', tone: 'danger' },
  cancelled: { label: 'Cancelado', tone: 'danger' },
  bye: { label: 'Pasa directo (sin rival)', tone: '' },
};
export const matchStatus = match => MATCH_STATES[match.workflow_status] ?? MATCH_STATES.unprogrammed;

export const DECIDED_BY = { regulation: 'Tiempo reglamentario', extra_time: 'Tiempo extra', penalties: 'Penales' };

export const roundLabel = (round, totalRounds) => ({ 0: 'Final', 1: 'Semifinales', 2: 'Cuartos de final', 3: 'Octavos de final' }[totalRounds - round] ?? `Ronda ${round}`);
