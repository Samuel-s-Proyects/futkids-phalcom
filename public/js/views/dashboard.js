// Home: what is happening now and what needs attention.
import { nowLocal } from '../core/clock.js';
import { esc, icon } from '../core/dom.js';
import { onAction } from '../core/events.js';
import { dateTime, fullName, plural } from '../core/format.js';
import { isAdmin, isReferee, player, store, teamName } from '../core/store.js';
import { matchStatus } from '../domain/labels.js';
import { avatar, badge, button, emptyState, pageHeader } from '../ui/components.js';
import { openMatch } from './matches/match-dialog.js';

const tournamentName = id => store.data.tournaments.find(t => +t.id === +id)?.name ?? '';

function matchRows(matches) {
  return `<ul class="list">${matches.map(m => {
    const state = matchStatus(m);
    return `<li class="list__row"><div class="list__main"><span class="list__title">${esc(teamName(m.home_team_id))} vs. ${esc(teamName(m.away_team_id))}</span>
      <span class="list__meta">${esc(tournamentName(m.tournament_id))} · ${esc(dateTime(m.scheduled_at))} · ${esc(m.venue ?? '')}</span></div>
      ${badge(state.label, state.tone)}${button('Abrir', { action: 'dashboard-match', data: { id: m.id, tournament: m.tournament_id }, small: true })}</li>`;
  }).join('')}</ul>`;
}

function topScorers() {
  const totals = new Map();
  for (const goal of store.data.goals) totals.set(+goal.player_id, (totals.get(+goal.player_id) ?? 0) + +goal.goals);
  for (const goal of store.data.tournament_match_goals) totals.set(+goal.player_id, (totals.get(+goal.player_id) ?? 0) + +goal.goals);
  return [...totals.entries()].filter(([, goals]) => goals > 0).sort((a, b) => b[1] - a[1] || a[0] - b[0]).slice(0, 5);
}

export function renderDashboard(main) {
  const now = nowLocal().replace('T', ' ');
  const matches = store.data.tournament_matches;
  const mine = m => !isReferee() || +m.referee_user_id === +store.user.id;
  const live = matches.filter(m => m.workflow_status === 'in_progress' && mine(m));
  const pending = matches.filter(m => m.workflow_status === 'awaiting_confirmation' && mine(m));
  const upcoming = matches.filter(m => m.workflow_status === 'scheduled' && mine(m) && String(m.scheduled_at) >= now.slice(0, 10))
    .sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at))).slice(0, 6);
  const toSchedule = isAdmin() ? matches.filter(m => m.workflow_status === 'unprogrammed' && m.home_team_id && m.away_team_id).length : 0;
  const running = store.data.tournaments.filter(t => t.status === 'in_progress').length;
  const stats = [
    ['Torneos en curso', running, 'trophy', plural(store.data.tournaments.length, 'torneo') + ' en total'],
    ['Equipos activos', store.data.teams.filter(t => +t.active).length, 'teams', plural(store.data.teams.length, 'equipo') + ' registrados'],
    ['Jugadores', store.data.players.length, 'players', 'En todas las categorías'],
    [isReferee() ? 'Mis partidos próximos' : 'Partidos programados', upcoming.length, 'calendar', toSchedule ? `${toSchedule} por programar` : 'Próximos encuentros'],
  ];
  const attention = [...live, ...pending];
  const scorers = topScorers();
  main.innerHTML = pageHeader({
    eyebrow: `Hola, ${store.user.name}`,
    title: 'Panel general',
    subtitle: isReferee() ? 'Sus partidos asignados y lo que falta registrar.' : 'Lo que está pasando en los torneos y lo que necesita atención.',
    actions: isAdmin() ? button('Nuevo torneo', { action: 'new-tournament', tone: 'primary', iconName: 'plus' }) : '',
  })
    + `<div class="stat-grid">${stats.map(([label, value, iconName, caption]) => `<article class="card stat"><span class="stat__label">${esc(label)}${icon(iconName)}</span><strong class="stat__value">${value}</strong><span class="stat__caption">${esc(caption)}</span></article>`).join('')}</div>`
    + `<div class="grid grid--sidebar"><div class="stack">
        ${attention.length ? `<section class="card"><div class="card__header"><div><h2>Necesita atención</h2><p class="card__subtitle">Partidos en juego o con resultado por confirmar.</p></div></div>${matchRows(attention)}</section>` : ''}
        <section class="card"><div class="card__header"><div><h2>Próximos partidos</h2><p class="card__subtitle">${isReferee() ? 'Partidos que usted dirigirá.' : 'Partidos programados de todos los torneos.'}</p></div>${button('Ver torneos', { href: '#torneos', tone: 'ghost', iconName: 'arrow' })}</div>
          ${upcoming.length ? matchRows(upcoming) : emptyState('No hay partidos programados', isAdmin() ? 'Programe partidos desde el cuadro de cada torneo.' : '', 'calendar')}</section>
      </div>
      <section class="card"><div class="card__header"><div><h2>Goleadores</h2><p class="card__subtitle">Todos los torneos y jornadas.</p></div>${button('Reporte', { href: '#reportes/goleadores', tone: 'ghost', iconName: 'arrow' })}</div>
        ${scorers.length ? `<ol class="list">${scorers.map(([playerId, goals], index) => `<li class="list__row"><span class="rank">${index + 1}</span>${avatar(player(playerId))}<div class="list__main"><span class="list__title">${esc(fullName(player(playerId)))}</span><span class="list__meta">${esc(teamName(player(playerId)?.team_id))}</span></div><strong>${plural(goals, 'gol', 'goles')}</strong></li>`).join('')}</ol>` : emptyState('Aún no hay goles', '', 'ball')}
      </section></div>`;
}

onAction('dashboard-match', trigger => openMatch(+trigger.dataset.id, +trigger.dataset.tournament));
