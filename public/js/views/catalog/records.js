// Searchable, paginated lists for players, incidents and the historic matchdays and goals.
import { today } from '../../core/clock.js';
import { $, esc } from '../../core/dom.js';
import { onAction, onChange } from '../../core/events.js';
import { date, fullName, plural } from '../../core/format.js';
import { isAdmin, matchday, player, store, teamName } from '../../core/store.js';
import { alertBox, avatar, badge, button, emptyState, iconButton, pageHeader, table } from '../../ui/components.js';
import { field } from '../../ui/form.js';

const PAGE_SIZE = 10;
const filters = { q: '', team_id: '', card_type: '', page: 1, entity: '' };

const age = birth => {
  const [y, m, d] = birth.split('-').map(Number);
  const [ry, rm, rd] = store.settings.age_reference_date.split('-').map(Number);
  return ry - y - (rm < m || (rm === m && rd < d) ? 1 : 0);
};

/** Next date (today or later) on which the player is suspended. */
const upcomingSuspension = playerId => store.data.incidents
  .filter(i => +i.player_id === +playerId && i.card_type === 'red' && i.suspension_date >= today())
  .map(i => i.suspension_date).sort()[0];

const actionsFor = (entity, row) => {
  const locked = CONFIG[entity].readOnly || (entity === 'incidents' && row.tournament_match_id);
  return `<div class="row-actions">${entity === 'players' ? iconButton('eye', `Ver ficha de ${fullName(row)}`, { action: 'player-file', data: { id: row.id } }) : ''}${isAdmin() && !locked ? `${iconButton('edit', 'Editar', { action: 'edit-record', data: { entity, id: row.id } })}${iconButton('delete', 'Eliminar', { action: 'delete-record', data: { entity, id: row.id }, danger: true })}` : ''}</div>`;
};

const CONFIG = {
  players: {
    title: 'Jugadores',
    subtitle: 'Fichas de los jugadores de cada equipo.',
    newLabel: 'Nuevo jugador',
    search: 'Buscar por nombre o apellido',
    teamFilter: true,
    columns: [{ label: 'Jugador' }, { label: 'Equipo' }, { label: 'Edad', numeric: true }, { label: 'Estado' }, { label: '' }],
    text: p => fullName(p),
    teamOf: p => p.team_id,
    row: p => {
      const suspension = upcomingSuspension(p.id);
      return [
        `<span class="person">${avatar(p)}<span><strong>${esc(fullName(p))}</strong><small>${p.shirt_number ? `#${esc(p.shirt_number)} · ` : ''}${esc(p.position ?? 'Sin posición')}</small></span></span>`,
        esc(teamName(p.team_id)),
        `${age(p.birth_date)} años`,
        suspension ? badge(`Suspendido el ${date(suspension)}`, 'danger') : badge('Disponible', 'success'),
        actionsFor('players', p),
      ];
    },
  },
  incidents: {
    title: 'Incidencias',
    subtitle: 'Tarjetas y suspensiones. Las tarjetas de un partido las registra su árbitro desde el partido.',
    newLabel: 'Nueva incidencia',
    search: 'Buscar por jugador o descripción',
    teamFilter: true,
    cardFilter: true,
    columns: [{ label: 'Jugador' }, { label: 'Tarjeta' }, { label: 'Descripción' }, { label: 'Fecha' }, { label: 'Suspensión' }, { label: '' }],
    text: i => `${fullName(player(i.player_id))} ${i.description}`,
    teamOf: i => player(i.player_id)?.team_id,
    row: i => [
      `<span class="person"><span><strong>${esc(fullName(player(i.player_id)))}</strong><small>${esc(teamName(player(i.player_id)?.team_id))}</small></span></span>`,
      badge(i.card_type === 'red' ? 'Roja' : 'Amarilla', i.card_type === 'red' ? 'danger' : 'warning') + (i.tournament_match_id ? ` ${badge('En partido', 'info')}` : ''),
      esc(i.description),
      `<span class="nowrap">${esc(date(i.incident_date))}</span>`,
      `<span class="nowrap">${esc(i.suspension_date ? date(i.suspension_date) : '—')}</span>`,
      actionsFor('incidents', i),
    ],
  },
  matchdays: {
    title: 'Jornadas (histórico)',
    subtitle: 'Jornadas registradas antes de los torneos. Los torneos de clasificación se organizan por rondas.',
    readOnly: true,
    search: 'Buscar jornada',
    columns: [{ label: 'Jornada' }, { label: 'Fecha' }, { label: 'Goles', numeric: true }, { label: '' }],
    text: m => `Jornada ${m.number} ${m.game_date}`,
    row: m => [
      `<strong>Jornada ${esc(m.number)}</strong>`,
      esc(date(m.game_date)),
      String(store.data.goals.filter(g => +g.matchday_id === +m.id).reduce((total, g) => total + +g.goals, 0)),
      actionsFor('matchdays', m),
    ],
  },
  goals: {
    title: 'Goles (histórico)',
    subtitle: 'Goles por jugador y jornada registrados antes de los torneos. Los goles de un torneo se registran en la hoja de cada partido.',
    readOnly: true,
    search: 'Buscar jugador',
    teamFilter: true,
    columns: [{ label: 'Jugador' }, { label: 'Equipo' }, { label: 'Jornada' }, { label: 'Goles', numeric: true }, { label: '' }],
    text: g => fullName(player(g.player_id)),
    teamOf: g => player(g.player_id)?.team_id,
    row: g => [
      `<span class="person">${avatar(player(g.player_id))}<strong>${esc(fullName(player(g.player_id)))}</strong></span>`,
      esc(teamName(player(g.player_id)?.team_id)),
      `Jornada ${esc(matchday(g.matchday_id)?.number)} · ${esc(date(matchday(g.matchday_id)?.game_date))}`,
      `<strong>${+g.goals}</strong>`,
      actionsFor('goals', g),
    ],
  },
};

export const recordSections = Object.keys(CONFIG);

function filtered(entity) {
  const config = CONFIG[entity];
  const query = filters.q.toLocaleLowerCase('es');
  return store.data[entity].filter(row => (!query || config.text(row).toLocaleLowerCase('es').includes(query))
    && (!filters.team_id || +config.teamOf?.(row) === +filters.team_id)
    && (!filters.card_type || row.card_type === filters.card_type));
}

function renderTable(entity) {
  const rows = filtered(entity);
  const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  filters.page = Math.min(filters.page, pages);
  const slice = rows.slice((filters.page - 1) * PAGE_SIZE, filters.page * PAGE_SIZE);
  $('#records-count').textContent = plural(rows.length, 'resultado');
  $('#records-region').innerHTML = rows.length
    ? table(CONFIG[entity].columns, slice.map(CONFIG[entity].row), { caption: CONFIG[entity].title })
      + `<div class="table-footer"><span>Mostrando ${(filters.page - 1) * PAGE_SIZE + 1}–${Math.min(filters.page * PAGE_SIZE, rows.length)} de ${rows.length}</span>
        <nav class="pagination" aria-label="Páginas">${iconButton('back', 'Página anterior', { action: 'records-page', data: { page: filters.page - 1 }, disabled: filters.page <= 1 })}
        <span>Página ${filters.page} de ${pages}</span>${iconButton('arrow', 'Página siguiente', { action: 'records-page', data: { page: filters.page + 1 }, disabled: filters.page >= pages })}</nav></div>`
    : emptyState('No hay resultados', 'Pruebe con otra búsqueda o filtro.', 'search');
}

/** Opens a list already filtered, e.g. the players of one team or a global search. */
export function presetFilters(entity, values) {
  Object.assign(filters, { q: '', team_id: '', card_type: '', page: 1 }, values, { entity });
}

export function renderRecords(main, entity) {
  if (filters.entity !== entity) Object.assign(filters, { q: '', team_id: '', card_type: '', page: 1, entity });
  const config = CONFIG[entity];
  const toolbar = [
    field({ name: 'q', label: config.search, type: 'search', value: filters.q, change: 'records-filter', live: true, hideOptional: true, placeholder: 'Escriba para buscar' }),
    config.teamFilter ? field({ name: 'team_id', label: 'Equipo', type: 'select', value: filters.team_id, placeholder: 'Todos los equipos', options: store.data.teams.map(t => ({ value: t.id, label: t.name })), change: 'records-filter', hideOptional: true }) : '',
    config.cardFilter ? field({ name: 'card_type', label: 'Tarjeta', type: 'select', value: filters.card_type, placeholder: 'Todas', options: [{ value: 'yellow', label: 'Amarilla' }, { value: 'red', label: 'Roja' }], change: 'records-filter', hideOptional: true }) : '',
  ].join('');
  const legacy = entity === 'matchdays' || entity === 'goals';
  main.innerHTML = pageHeader({
    eyebrow: legacy ? 'Histórico' : 'Catálogo',
    title: config.title,
    subtitle: config.subtitle,
    actions: isAdmin() && !config.readOnly ? button(config.newLabel, { action: 'new-record', data: { entity }, tone: 'primary', iconName: 'plus' }) : '',
  }) + (legacy ? `<div class="page-section">${alertBox('info', 'Módulo histórico', 'Se conserva para consultar datos anteriores. Para un torneo nuevo, registre los goles en la hoja de cada partido.')}</div>` : '')
    + `<section class="card card--flush" aria-label="${esc(config.title)}"><div class="toolbar" role="search">${toolbar}<span class="toolbar__count" id="records-count" aria-live="polite"></span></div><div id="records-region"></div></section>`;
  renderTable(entity);
}

onChange('records-filter', control => {
  filters[control.name] = control.value.trim();
  filters.page = 1;
  renderTable(filters.entity);
});

onAction('records-page', trigger => {
  filters.page = Math.max(1, +trigger.dataset.page);
  renderTable(filters.entity);
});
