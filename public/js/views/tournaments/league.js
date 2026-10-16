// Points tournaments: calendar by matchday, table of positions, calendar generation and matchday changes.
import { api } from '../../core/api.js';
import { today } from '../../core/clock.js';
import { $$, esc, icon } from '../../core/dom.js';
import { onAction, onSubmit } from '../../core/events.js';
import { date, dateTime, longDate, plural } from '../../core/format.js';
import { isAdmin, store, teamName } from '../../core/store.js';
import { matchStatus } from '../../domain/labels.js';
import { alertBox, badge, button, emptyState, table } from '../../ui/components.js';
import { confirmDialog, dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, setControlError, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { tournamentChange } from './detail.js';

const matchdayDates = m => (m.end_date && m.end_date !== m.game_date ? `${date(m.game_date)} – ${date(m.end_date)}` : longDate(m.game_date));
const matchdayTitle = m => `${m.kind === 'extraordinary' ? (m.label || 'Jornada extraordinaria') : `Jornada ${m.number}`}`;

/** Teams that do not play in a matchday (with an odd number of teams one rests every matchday). */
function restingTeams(t, matchdayId) {
  const playing = new Set(t.matches.filter(m => +m.matchday_id === +matchdayId).flatMap(m => [+m.home_team_id, +m.away_team_id]));
  return t.participants.map(p => +p.team_id).filter(id => !playing.has(id));
}

function matchRow(m) {
  const state = matchStatus(m);
  const score = m.home_score !== null && m.home_score !== undefined ? ` <strong>${m.home_score} – ${m.away_score}</strong> ` : ' vs. ';
  return `<li class="list__row"><div class="list__main"><span class="list__title">${esc(teamName(m.home_team_id))}${score}${esc(teamName(m.away_team_id))}</span>
    <span class="list__meta">${m.scheduled_at ? `${icon('clock')} ${esc(dateTime(m.scheduled_at))} · ${icon('pin')} ${esc(m.venue ?? '')}` : 'Sin programar'}</span></div>
    ${badge(state.label, state.tone)}${button('Abrir', { action: 'open-match', data: { id: m.id }, small: true })}</li>`;
}

export function calendarTab(t) {
  if (!t.fixture_generated_at) {
    const count = t.matchday_count;
    const text = t.registrations_closed_at
      ? `Con ${plural(t.participants.length, 'equipo')} ${+t.league_rounds === 2 ? 'a ida y vuelta' : 'a una vuelta'} se jugarán ${plural(count, 'jornada')}. Elija las fechas y genere el calendario.`
      : 'Primero inscriba a los equipos y cierre la inscripción; luego se calcula el número de jornadas.';
    return `<div class="card">${emptyState('El calendario aún no se ha generado', text, 'calendar', isAdmin() && t.registrations_closed_at ? button('Preparar calendario', { action: 'open-fixture', tone: 'primary', iconName: 'calendar' }) : '')}</div>`;
  }
  const editable = isAdmin() && t.status === 'in_progress' && !+t.is_legacy;
  const pending = t.matches.filter(m => m.workflow_status === 'suspended');
  const current = t.matchdays.find(day => (day.end_date || day.game_date) >= today());
  const days = t.matchdays.map(m => {
    const matches = t.matches.filter(match => +match.matchday_id === +m.id);
    const resting = restingTeams(t, m.id);
    const done = matches.length > 0 && matches.every(match => ['confirmed', 'cancelled'].includes(match.workflow_status));
    const open = +m.id === +current?.id;
    return `<details class="card page-section" ${open ? 'open' : ''}><summary class="cluster"><strong>${esc(matchdayTitle(m))}</strong><span class="muted">${esc(matchdayDates(m))}</span>${m.kind === 'extraordinary' ? badge('Extraordinaria', 'warning') : ''}${done ? badge('Jugada', 'primary') : ''}</summary>
      ${matches.length ? `<ul class="list">${matches.map(matchRow).join('')}</ul>` : '<p class="muted">Sin partidos en esta jornada.</p>'}
      ${resting.length && m.kind !== 'extraordinary' ? `<p class="muted">${icon('info')} Descansa: ${esc(resting.map(teamName).join(', '))}</p>` : ''}
      ${editable ? `<div class="cluster">${button('Cambiar fechas', { action: 'edit-matchday', data: { id: m.id }, small: true, iconName: 'edit' })}${m.kind === 'extraordinary' && !matches.length ? button('Eliminar', { action: 'delete-matchday', data: { id: m.id }, small: true, iconName: 'delete' }) : ''}</div>` : ''}
    </details>`;
  }).join('');
  return `${pending.length ? `<section class="card page-section"><div class="card__header"><div><h2>Pendientes de reprogramar</h2><p class="card__subtitle">Partidos aplazados: muévalos a otra jornada o prográmelos de nuevo.</p></div></div><ul class="list">${pending.map(matchRow).join('')}</ul></section>` : ''}
    ${editable ? `<div class="cluster page-section">${button('Agregar jornada extraordinaria', { action: 'new-matchday', iconName: 'plus' })}${button('Extender fecha final', { action: 'extend-end-date', iconName: 'calendar' })}</div>` : ''}
    ${days}`;
}

export function standingsTab(t) {
  if (!t.standings?.length) return `<div class="card">${emptyState('La tabla aparecerá con los equipos inscritos', '', 'trophy')}</div>`;
  const provisional = t.matches.filter(m => m.workflow_status === 'awaiting_confirmation').length;
  const form = letters => letters.map(letter => badge(letter, { G: 'success', E: '', P: 'danger' }[letter])).join(' ');
  const rows = t.standings.map(row => [
    `<span class="rank">${row.position}</span>`,
    `<strong>${esc(teamName(row.team_id))}</strong>${row.tie_unresolved ? ` <span title="Empate en todos los criterios">${badge('Empate técnico')}</span>` : ''}`,
    row.played, row.won, row.drawn, row.lost, row.goals_for, row.goals_against,
    `${row.goal_difference > 0 ? '+' : ''}${row.goal_difference}`,
    `<strong>${row.points}</strong>`,
    form(row.form),
  ].map(String));
  return `${provisional ? `<div class="page-section">${alertBox('warning', 'Tabla provisional', `${plural(provisional, 'resultado')} por confirmar no se cuenta todavía.`)}</div>` : ''}
    <div class="card card--flush"><div class="card__header"><div><h2>Tabla de posiciones</h2><p class="card__subtitle">Ganar ${t.points_win} · Empatar ${t.points_draw} · Perder ${t.points_loss} puntos. Solo resultados confirmados.</p></div></div>
    ${table([{ label: 'Pos.' }, { label: 'Equipo' }, { label: 'PJ', numeric: true }, { label: 'G', numeric: true }, { label: 'E', numeric: true }, { label: 'P', numeric: true }, { label: 'GF', numeric: true }, { label: 'GC', numeric: true }, { label: 'DG', numeric: true }, { label: 'Pts', numeric: true }, { label: 'Últimos' }], rows, { caption: 'Tabla de posiciones', rowClass: index => (index === 0 ? 'is-highlighted' : '') })}
    <p class="muted report-header">Desempate: 1) puntos, 2) diferencia de goles, 3) goles a favor, 4) resultados entre los equipos empatados, 5) juego limpio (menos tarjetas rojas y luego amarillas). PJ jugados · G ganados · E empatados · P perdidos · GF/GC goles a favor y en contra · DG diferencia.</p></div>`;
}

function openFixture() {
  const t = store.tournament;
  const first = t.start_date > today() ? t.start_date : today();
  openDialog(`${dialogHeader({ eyebrow: 'Calendario', title: 'Preparar calendario', subtitle: `${plural(t.matchday_count, 'jornada')} · ${+t.league_rounds === 2 ? 'ida y vuelta' : 'solo ida'}`, size: 'wide' })}
    <form id="fixture-form" novalidate><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'first_date', label: 'Fecha de la primera jornada', type: 'date', value: first, required: true, min: first, max: t.end_date, hint: 'No antes de hoy ni del inicio del torneo.' })}
        ${field({ name: 'interval_days', label: 'Días entre jornadas', type: 'number', value: 7, required: true, min: 1, max: 30, hint: '7 = una jornada por semana. Después podrá ajustar cada fecha.' })}
      </div>
      <div>${button('Ver propuesta', { type: 'submit', iconName: 'eye' })}</div>
      <p class="form-error" role="alert"></p>
      <div id="fixture-preview"></div>
    </div><div class="dialog__footer">
      <button class="button" type="button" data-action="close-dialog">Cancelar</button>
      ${button('Generar calendario', { action: 'generate-fixture', tone: 'primary', iconName: 'check' })}
    </div></form>`, 'wide');
}

onAction('open-fixture', openFixture);

onSubmit('fixture-form', async form => {
  const preview = await submitForm(form, values => api(`/tournaments/${store.tournament.id}/fixture/preview`, { method: 'POST', body: values }));
  if (!preview) return;
  const region = form.querySelector('#fixture-preview');
  region.innerHTML = `<h3>Propuesta</h3><p class="muted">Revise o cambie la fecha de cada jornada. Deben ir en orden y dentro del torneo.</p>
    <div class="stack">${preview.preview.matchdays.map(day => `<div class="fieldset"><div class="cluster cluster--end">
      ${field({ name: `game_date_${day.number}`, label: `Jornada ${day.number}${day.leg === 2 ? ' (vuelta)' : ''}`, type: 'date', value: day.game_date, required: true, min: today(), max: store.tournament.end_date, hideOptional: true })}
      <span class="muted">${day.pairs.map(pair => `${esc(teamName(pair.home))} vs. ${esc(teamName(pair.away))}`).join(' · ')}${day.resting ? ` · Descansa: ${esc(teamName(day.resting))}` : ''}</span>
    </div></div>`).join('')}</div>`;
});

onAction('generate-fixture', async trigger => {
  const form = trigger.closest('form');
  const inputs = $$('[name^="game_date_"]', form);
  if (!inputs.length) {
    form.querySelector('.form-error').textContent = 'Primero pulse «Ver propuesta».';
    return;
  }
  const dates = inputs.map(input => input.value);
  const unordered = inputs.find((input, index) => index > 0 && input.value <= inputs[index - 1].value);
  if (unordered) {
    setControlError(unordered, 'Debe ser posterior a la jornada anterior.');
    unordered.focus();
    return;
  }
  try {
    await tournamentChange('/fixture', { body: { game_dates: dates }, message: 'Calendario generado. Programe los partidos de cada jornada.' });
    location.hash = `#torneos/${store.tournament.id}/calendario`;
  } catch (error) {
    if (!error.status) throw error;
    form.querySelector('.form-error').textContent = [error.message, ...Object.values(error.fields ?? {})].filter((v, i, all) => all.indexOf(v) === i).join(' ');
  }
});

function matchdayForm(matchday = null) {
  const t = store.tournament;
  return `${dialogHeader({ eyebrow: 'Calendario', title: matchday ? `Fechas de ${matchdayTitle(matchday)}` : 'Nueva jornada extraordinaria', subtitle: matchday ? '' : 'Se agrega al final del calendario para reponer partidos aplazados.' })}
    <form id="matchday-form" novalidate data-id="${matchday?.id ?? ''}"><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'game_date', label: 'Primer día', type: 'date', value: matchday?.game_date ?? '', required: true, min: today(), max: t.end_date, hint: `Dentro del torneo (hasta el ${date(t.end_date)}) y no antes de hoy.` })}
        ${field({ name: 'end_date', label: 'Último día', type: 'date', value: matchday?.end_date ?? '', min: today(), max: t.end_date, hint: 'Solo si la jornada dura varios días (por ejemplo, sábado y domingo).' })}
        ${!matchday || matchday.kind === 'extraordinary' ? field({ name: 'label', label: 'Nombre', value: matchday?.label ?? 'Jornada extraordinaria', rule: 'title', maxlength: 100, full: true, hint: 'Ej.: Reposición de partidos aplazados.' }) : ''}
      </div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      <button class="button" type="button" data-action="close-dialog">Cancelar</button>
      ${button('Guardar', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`;
}

onAction('new-matchday', () => openDialog(matchdayForm()));
onAction('edit-matchday', trigger => openDialog(matchdayForm(store.tournament.matchdays.find(m => +m.id === +trigger.dataset.id))));

onSubmit('matchday-form', async form => {
  const id = form.dataset.id;
  const saved = await submitForm(form, values => tournamentChange(id ? `/matchdays/${id}` : '/matchdays', { body: values, message: id ? 'Jornada actualizada.' : 'Jornada extraordinaria creada.' }));
  if (saved) document.getElementById('form-dialog').close();
});

onAction('delete-matchday', async trigger => {
  if (await confirmDialog({ title: '¿Eliminar la jornada?', text: 'Solo se pueden eliminar jornadas extraordinarias sin partidos.', confirmLabel: 'Eliminar', danger: true })) {
    await tournamentChange(`/matchdays/${trigger.dataset.id}`, { method: 'DELETE', message: 'Jornada eliminada.' });
  }
});

onAction('extend-end-date', () => {
  const t = store.tournament;
  openDialog(`${dialogHeader({ eyebrow: 'Torneo', title: 'Extender fecha final', subtitle: `Hoy termina el ${date(t.end_date)}.` })}
    <form id="end-date-form" novalidate><div class="dialog__body">
      ${field({ name: 'end_date', label: 'Nueva fecha final', type: 'date', required: true, min: t.end_date, hint: 'Solo se puede extender, para dar espacio a jornadas extraordinarias o partidos aplazados.' })}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer"><button class="button" type="button" data-action="close-dialog">Cancelar</button>${button('Extender', { type: 'submit', tone: 'primary', iconName: 'check' })}</div></form>`);
});

onSubmit('end-date-form', async form => {
  const saved = await submitForm(form, values => tournamentChange('/end-date', { body: values, message: 'Fecha final actualizada.' }));
  if (saved) document.getElementById('form-dialog').close();
});

