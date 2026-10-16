import { esc } from '../../core/dom.js';
import { onAction } from '../../core/events.js';
import { go } from '../../core/router.js';
import { isAdmin, playersOf, store } from '../../core/store.js';
import { badge, button, emptyState, iconButton, pageHeader } from '../../ui/components.js';
import { presetFilters } from './records.js';

const teamGoals = teamId => store.data.goals
  .filter(g => store.data.players.some(p => +p.id === +g.player_id && +p.team_id === +teamId))
  .reduce((total, g) => total + +g.goals, 0)
  + store.data.tournament_match_goals.filter(g => +g.team_id === +teamId).reduce((total, g) => total + +g.goals, 0);

export function renderTeams(main) {
  const teams = store.data.teams;
  main.innerHTML = pageHeader({
    eyebrow: 'Catálogo',
    title: 'Equipos',
    subtitle: 'Los equipos activos pueden inscribirse en torneos y recibir jugadores.',
    actions: isAdmin() ? button('Nuevo equipo', { action: 'new-record', data: { entity: 'teams' }, tone: 'primary', iconName: 'plus' }) : '',
  }) + (teams.length ? `<div class="team-grid">${teams.map(team => `<article class="card team-card">
      <div class="team-card__top"><span class="emblem" aria-hidden="true">${esc(team.name[0])}</span>${badge(+team.active ? 'Activo' : 'Inactivo', +team.active ? 'success' : '')}</div>
      <h2>${esc(team.name)}</h2>
      <div class="team-card__numbers"><div><strong>${playersOf(team.id).length}</strong><span>jugadores</span></div><div><strong>${teamGoals(team.id)}</strong><span>goles</span></div></div>
      <div class="team-card__footer">${button('Ver jugadores', { action: 'team-players', data: { id: team.id }, tone: 'ghost', iconName: 'players' })}
        <div class="row-actions">${isAdmin() ? `${iconButton('edit', `Editar ${team.name}`, { action: 'edit-record', data: { entity: 'teams', id: team.id } })}${iconButton('delete', `Eliminar ${team.name}`, { action: 'delete-record', data: { entity: 'teams', id: team.id }, danger: true })}` : ''}</div></div>
    </article>`).join('')}</div>`
    : `<div class="card">${emptyState('No hay equipos registrados', 'Cree el primer equipo para empezar.', 'teams')}</div>`);
}

onAction('team-players', trigger => {
  presetFilters('players', { team_id: trigger.dataset.id });
  go('jugadores');
});

