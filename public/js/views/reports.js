// Printable reports: referee list (who can play on a date), incidents and scorers.
import { api } from '../core/api.js';
import { today } from '../core/clock.js';
import { $, esc } from '../core/dom.js';
import { onAction, onChange, onSubmit } from '../core/events.js';
import { date, dateTime, fullName, plural } from '../core/format.js';
import { matchday, player, playersOf, store, teamName } from '../core/store.js';

const tournamentName = id => store.data.tournaments.find(t => +t.id === +id)?.name ?? '';
const scheduledMatches = () => store.data.tournament_matches
  .filter(m => m.scheduled_at && m.home_team_id && m.away_team_id && m.workflow_status !== 'bye')
  .sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
const matchLabel = m => `${teamName(m.home_team_id)} vs. ${teamName(m.away_team_id)} · ${dateTime(m.scheduled_at)} · ${tournamentName(m.tournament_id)}`;
const matchdayLabel = m => `Jornada ${m.number} · ${date(m.game_date)} · ${tournamentName(m.tournament_id)}`;
import { avatar, badge, button, emptyState, pageHeader, table, tabs } from '../ui/components.js';
import { field } from '../ui/form.js';
import { toast } from '../ui/toast.js';

const KINDS = {
  arbitro: { api: 'referee', label: 'Listado para el árbitro', help: 'Jugadores habilitados y suspendidos para un partido (o una jornada del histórico), según las tarjetas rojas.' },
  incidencias: { api: 'incidents', label: 'Incidencias', help: 'Tarjetas por equipo, jugador, tipo y rango de fechas.' },
  goleadores: { api: 'scorers', label: 'Goleadores', help: 'Goles por jugador en todos los torneos o en uno solo. Los autogoles no cuentan.' },
};

const state = { kind: 'arbitro', filters: {}, rows: [], generation: 0 };

/** The next scheduled match from today, or the last one played. */
const defaultMatch = () => scheduledMatches().find(m => String(m.scheduled_at) >= today()) ?? scheduledMatches().at(-1);

function filtersForm(kind) {
  const f = state.filters;
  const teams = store.data.teams.map(t => ({ value: t.id, label: t.name }));
  const matchdays = store.data.matchdays.map(m => ({ value: m.id, label: matchdayLabel(m) }));
  const parts = [field({ name: 'team_id', label: 'Equipo', type: 'select', value: f.team_id, placeholder: 'Todos los equipos', options: teams, change: 'report-team', hideOptional: true })];
  if (kind === 'arbitro') {
    parts.push(
      field({ name: 'match_id', label: 'Partido', type: 'select', value: f.match_id, placeholder: 'Seleccione un partido', options: scheduledMatches().map(m => ({ value: m.id, label: matchLabel(m) })), change: 'report-referee-source', hideOptional: true, hint: 'Solo aparecen partidos con fecha.' }),
      field({ name: 'matchday_id', label: 'O una jornada', type: 'select', value: f.matchday_id, placeholder: 'Ninguna', options: matchdays, change: 'report-referee-source', hideOptional: true }),
    );
  }
  if (kind === 'goleadores') {
    parts.push(
      field({ name: 'tournament_id', label: 'Torneo', type: 'select', value: f.tournament_id, placeholder: 'Todos los torneos', options: store.data.tournaments.map(t => ({ value: t.id, label: t.name })), hideOptional: true }),
      field({ name: 'matchday_id', label: 'Jornada', type: 'select', value: f.matchday_id, placeholder: 'Todas las jornadas', options: matchdays, hideOptional: true }),
    );
  }
  if (kind === 'incidencias') {
    parts.push(
      field({ name: 'player_id', label: 'Jugador', type: 'select', value: f.player_id, placeholder: 'Todos los jugadores', options: (f.team_id ? playersOf(f.team_id) : store.data.players).map(p => ({ value: p.id, label: fullName(p) })), hideOptional: true }),
      field({ name: 'card_type', label: 'Tarjeta', type: 'select', value: f.card_type, placeholder: 'Todas', options: [{ value: 'yellow', label: 'Amarilla' }, { value: 'red', label: 'Roja' }], hideOptional: true }),
      field({ name: 'date_from', label: 'Desde', type: 'date', value: f.date_from, hideOptional: true }),
      field({ name: 'date_to', label: 'Hasta', type: 'date', value: f.date_to, hideOptional: true }),
    );
  }
  return `<form id="report-form" class="report-filters" novalidate>${parts.join('')}${button('Generar', { type: 'submit', tone: 'primary', iconName: 'reports' })}<p class="form-error" role="alert"></p></form>`;
}

const filterSummary = () => {
  const f = state.filters;
  return [
    f.team_id ? teamName(f.team_id) : 'Todos los equipos',
    f.tournament_id ? tournamentName(f.tournament_id) : '',
    f.match_id ? matchLabel(store.data.tournament_matches.find(m => +m.id === +f.match_id) ?? {}) : '',
    f.matchday_id ? matchdayLabel(matchday(f.matchday_id) ?? {}) : '',
    f.player_id ? fullName(player(f.player_id)) : '',
    f.card_type ? (f.card_type === 'red' ? 'Tarjetas rojas' : 'Tarjetas amarillas') : '',
    f.date_from ? `desde ${date(f.date_from)}` : '',
    f.date_to ? `hasta ${date(f.date_to)}` : '',
  ].filter(Boolean).join(' · ');
};

function resultTable(kind, rows) {
  if (!rows.length) return emptyState('No hay datos para estos filtros', 'Ajuste la selección y vuelva a generar el reporte.', 'search');
  if (kind === 'incidencias') return table([{ label: 'Jugador' }, { label: 'Tarjeta' }, { label: 'Descripción' }, { label: 'Fecha' }, { label: 'Suspensión' }], rows.map(r => [`<strong>${esc(r.player_name)}</strong><br><span class="muted">${esc(r.team_name)}</span>`, badge(r.card_type === 'red' ? 'Roja' : 'Amarilla', r.card_type === 'red' ? 'danger' : 'warning'), esc(r.description), esc(date(r.incident_date)), esc(r.suspension_date ? date(r.suspension_date) : '—')]));
  if (kind === 'goleadores') return table([{ label: 'Pos.' }, { label: 'Jugador' }, { label: 'Equipo' }, { label: 'Goles', numeric: true }], rows.map((r, i) => [`<span class="rank">${i + 1}</span>`, `<span class="person">${avatar(r)}<strong>${esc(r.name)}</strong></span>`, esc(r.team_name), `<strong>${r.total_goals}</strong>`]));
  return `<div class="alert alert--info page-section">${plural(rows.filter(r => !r.suspended).length, 'jugador habilitado', 'jugadores habilitados')} · ${plural(rows.filter(r => r.suspended).length, 'suspendido')}</div>`
    + table([{ label: 'Jugador' }, { label: 'Equipo' }, { label: 'Camiseta' }, { label: 'Edad', numeric: true }, { label: 'Estado' }], rows.map(r => [`<span class="person">${avatar(r)}<strong>${esc(r.name)}</strong></span>`, esc(r.team_name), r.shirt_number ? `#${esc(r.shirt_number)}` : '—', `${r.age}`, r.suspended ? badge('Suspendido', 'danger') : badge('Habilitado', 'success')]));
}

async function generate() {
  const generation = ++state.generation;
  const region = $('#report-region');
  region.innerHTML = '<div class="loading">Generando reporte…</div>';
  try {
    const query = new URLSearchParams(Object.entries(state.filters).filter(([, value]) => value));
    const { rows } = await api(`/reports/${KINDS[state.kind].api}?${query}`);
    if (generation !== state.generation) return;
    state.rows = rows;
    region.innerHTML = `<div class="print-only"><h1>Cantera · ${esc(KINDS[state.kind].label)}</h1><p>${esc(filterSummary())} · Generado el ${esc(date(today()))}</p></div>
      <div class="report-header"><div><h2>${esc(KINDS[state.kind].label)}</h2><p class="muted">${esc(filterSummary())} · ${plural(rows.length, 'registro')}</p></div>
      <div class="cluster">${button('Descargar CSV', { action: 'report-csv', iconName: 'download' })}${button('Imprimir o PDF', { action: 'print', iconName: 'print' })}</div></div>
      <div class="card--flush">${resultTable(state.kind, rows)}</div>`;
  } catch (error) {
    if (generation !== state.generation) return;
    region.innerHTML = emptyState('No se pudo generar el reporte', error.message, 'alert');
  }
}

export function renderReports(main, kindKey) {
  const kind = KINDS[kindKey] ? kindKey : 'arbitro';
  if (kind !== state.kind) state.filters = {};
  state.kind = kind;
  const hasSource = () => state.filters.match_id || state.filters.matchday_id;
  if (kind === 'arbitro' && !hasSource()) state.filters.match_id = String(defaultMatch()?.id ?? '');
  main.innerHTML = pageHeader({ eyebrow: 'Reportes', title: 'Reportes del torneo', subtitle: 'Listos para consultar, descargar o imprimir.' })
    + tabs(Object.entries(KINDS).map(([key, value]) => ({ key, label: value.label, href: `#reportes/${key}` })), kind, 'Tipo de reporte')
    + `<section class="card card--flush" aria-label="${esc(KINDS[kind].label)}"><p class="muted report-header">${esc(KINDS[kind].help)}</p>${filtersForm(kind)}<div id="report-region"></div></section>`;
  if (kind !== 'arbitro' || hasSource()) generate();
  else $('#report-region').innerHTML = emptyState('Elija un partido', 'El listado indica quién puede jugar en esa fecha. Programe un partido para verlo aquí.', 'calendar');
}

onSubmit('report-form', form => {
  state.filters = Object.fromEntries([...new FormData(form)].map(([key, value]) => [key, String(value).trim()]));
  if (state.kind === 'incidencias' && state.filters.date_from && state.filters.date_to && state.filters.date_from > state.filters.date_to) {
    form.querySelector('.form-error').textContent = 'La fecha «Desde» debe ser anterior a «Hasta».';
    return;
  }
  form.querySelector('.form-error').textContent = '';
  if (state.kind === 'arbitro' && !state.filters.match_id && !state.filters.matchday_id) {
    form.querySelector('.form-error').textContent = 'Elija un partido o una jornada.';
    return;
  }
  generate();
});

/** A referee list is for one match or one matchday: choosing one clears the other. */
onChange('report-referee-source', select => {
  const other = select.form.elements.namedItem(select.name === 'match_id' ? 'matchday_id' : 'match_id');
  if (select.value && other) other.value = '';
});

onChange('report-team', select => {
  const players = select.form.elements.namedItem('player_id');
  if (!players) return;
  const list = select.value ? playersOf(select.value) : store.data.players;
  players.innerHTML = '<option value="">Todos los jugadores</option>' + list.map(p => `<option value="${p.id}">${esc(fullName(p))}</option>`).join('');
});

onAction('report-csv', () => {
  const kind = state.kind;
  const rows = state.rows;
  const columns = { incidencias: ['Jugador', 'Equipo', 'Tarjeta', 'Descripción', 'Fecha', 'Suspensión'], goleadores: ['Posición', 'Jugador', 'Equipo', 'Goles'], arbitro: ['Jugador', 'Equipo', 'Camiseta', 'Edad', 'Estado'] }[kind];
  const values = rows.map((r, i) => ({
    incidencias: [r.player_name, r.team_name, r.card_type === 'red' ? 'Roja' : 'Amarilla', r.description, r.incident_date, r.suspension_date ?? ''],
    goleadores: [i + 1, r.name, r.team_name, r.total_goals],
    arbitro: [r.name, r.team_name, r.shirt_number ?? '', r.age, r.suspended ? 'Suspendido' : 'Habilitado'],
  })[kind]);
  // Neutralise spreadsheet formulas and quote every cell.
  const cell = value => `"${String(value ?? '').replace(/^([=+\-@\t\r])/, "'$1").replace(/"/g, '""')}"`;
  const csv = '﻿' + [columns, ...values].map(row => row.map(cell).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const link = Object.assign(document.createElement('a'), { href: url, download: `cantera-${kind}-${today()}.csv` });
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  toast('Reporte descargado.');
});
