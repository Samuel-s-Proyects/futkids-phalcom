import { esc, icon } from '../../core/dom.js';
import { date, plural } from '../../core/format.js';
import { isAdmin, store, teamName } from '../../core/store.js';
import { TOURNAMENT_TYPES, tournamentStatus } from '../../domain/labels.js';
import { badge, button, emptyState, pageHeader } from '../../ui/components.js';

function card(tournament) {
  const status = tournamentStatus(tournament);
  const lifecycle = tournament.lifecycle ?? {};
  const next = tournament.status === 'finished' && tournament.champion_team_id
    ? `${icon('trophy')} Campeón: <strong>${esc(teamName(tournament.champion_team_id))}</strong>`
    : esc(lifecycle.hint ?? '');
  return `<article class="card tournament-card">
    <div class="cluster">${badge(TOURNAMENT_TYPES[tournament.type]?.label ?? 'Clasificación', 'info')}${badge(status.label, status.tone)}</div>
    <h2>${esc(tournament.name)}</h2>
    <div class="tournament-card__meta">
      <span>${icon('calendar')} ${esc(date(tournament.start_date))} – ${esc(date(tournament.end_date))}</span>
      <span>${icon('teams')} ${esc(plural(+tournament.participant_count || 0, 'equipo'))}</span>
    </div>
    ${next ? `<p class="tournament-card__next">${next}</p>` : ''}
    ${button(isAdmin() && lifecycle.next_action ? 'Continuar' : 'Ver torneo', { href: `#torneos/${tournament.id}`, tone: 'primary', iconName: 'arrow' })}
  </article>`;
}

export function renderTournamentList(main) {
  const tournaments = store.data.tournaments;
  const actions = isAdmin() ? button('Nuevo torneo', { action: 'new-tournament', tone: 'primary', iconName: 'plus' }) : '';
  main.innerHTML = pageHeader({
    eyebrow: 'Competencias',
    title: 'Torneos',
    subtitle: 'Cree torneos de clasificación (eliminación directa), inscriba equipos y siga cada partido hasta el campeón.',
    actions,
  }) + (tournaments.length
    ? `<div class="tournament-grid">${tournaments.map(card).join('')}</div>`
    : `<div class="card">${emptyState('Todavía no hay torneos', isAdmin() ? 'Cree el primero con el botón «Nuevo torneo».' : 'Cuando el administrador cree un torneo aparecerá aquí.', 'trophy')}</div>`);
}
