// Tournament page: progress, next step and one tab per concern. Knockout: summary, teams, bracket,
// scorers. League: summary, teams, calendar, table, scorers.
import { api } from '../../core/api.js';
import { esc, icon } from '../../core/dom.js';
import { onAction, onSubmit } from '../../core/events.js';
import { date, dateTime, fullName, plural } from '../../core/format.js';
import { rerender } from '../../core/router.js';
import { applyTournament, isAdmin, player, store, team, teamName } from '../../core/store.js';
import { DECIDED_BY, TOURNAMENT_TYPES, matchStatus, roundLabel, tournamentStatus } from '../../domain/labels.js';
import { alertBox, avatar, badge, button, emptyState, iconButton, pageHeader, stepper, table, tabs } from '../../ui/components.js';
import { confirmDialog } from '../../ui/dialog.js';
import { field } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { openMatch } from '../matches/match-dialog.js';
import { calendarTab, standingsTab } from './league.js';

const TABS = {
  knockout: [
    { key: 'resumen', label: 'Resumen' },
    { key: 'equipos', label: 'Equipos' },
    { key: 'cuadro', label: 'Cuadro' },
    { key: 'goleadores', label: 'Goleadores' },
  ],
  league: [
    { key: 'resumen', label: 'Resumen' },
    { key: 'equipos', label: 'Equipos' },
    { key: 'calendario', label: 'Calendario' },
    { key: 'tabla', label: 'Tabla' },
    { key: 'goleadores', label: 'Goleadores' },
  ],
};
const TAB_RENDERERS = { resumen: t => summaryTab(t), equipos: t => teamsTab(t), cuadro: t => bracketTab(t), calendario: t => calendarTab(t), tabla: t => standingsTab(t), goleadores: t => scorersTab(t) };

/** Draws the tournament already loaded in store.tournament (main.js loads it before routing here). */
export function renderTournamentDetail(main, tabKey = 'resumen') {
  const t = store.tournament;
  const available = TABS[t.type] ?? TABS.knockout;
  const tab = available.some(item => item.key === tabKey) ? tabKey : 'resumen';
  const status = tournamentStatus(t);
  const body = TAB_RENDERERS[tab](t);
  main.innerHTML = pageHeader({
    eyebrow: `Torneo · ${TOURNAMENT_TYPES[t.type]?.label ?? ''}`,
    title: t.name,
    subtitle: `${date(t.start_date)} – ${date(t.end_date)} · ${status.label}`,
    actions: [
      button('Todos los torneos', { href: '#torneos', iconName: 'back' }),
      isAdmin() && t.status === 'draft' ? button('Editar datos', { action: 'edit-tournament', iconName: 'edit' }) : '',
    ].join(''),
  })
    + progressCard(t)
    + tabs(available.map(item => ({ ...item, href: `#torneos/${t.id}/${item.key}` })), tab, 'Secciones del torneo')
    + `<section aria-label="${esc(available.find(item => item.key === tab).label)}">${body}</section>`;
}

// ---- Progress -----------------------------------------------------------------------------------

const NEXT_ACTIONS = {
  'open-registration': { action: 'open-registration', iconName: 'teams' },
  'close-registration': { tab: 'equipos', iconName: 'check' },
  'generate-bracket': { action: 'generate-bracket', iconName: 'trophy' },
  'generate-fixture': { action: 'open-fixture', iconName: 'calendar' },
};

function progressCard(t) {
  const lifecycle = t.lifecycle;
  const champion = t.status === 'finished' && t.champion_team_id
    ? `<div class="champion">${icon('trophy')}<div><span class="muted">Campeón del torneo</span><strong>${esc(teamName(t.champion_team_id))}</strong></div></div>`
    : '';
  let next = `<p>${esc(lifecycle.hint)}</p>`;
  const action = lifecycle.next_action;
  if (isAdmin() && action && NEXT_ACTIONS[action.key]) {
    const definition = NEXT_ACTIONS[action.key];
    const blocked = lifecycle.blockers.length > 0;
    next += definition.tab
      ? button(action.label, { href: `#torneos/${t.id}/${definition.tab}`, tone: 'primary', iconName: definition.iconName })
      : blocked ? '' : button(action.label, { action: definition.action, tone: 'primary', iconName: definition.iconName });
    if (blocked) next += `<p class="field__error">${esc(lifecycle.blockers.join(' '))}</p>`;
  }
  return `<div class="card stack page-section">${stepper(lifecycle.steps)}${champion || `<div class="next-step">${next}</div>`}</div>`;
}

// ---- Summary ------------------------------------------------------------------------------------

function summaryTab(t) {
  const league = t.type === 'league';
  const rules = +t.extra_time_periods
    ? `${plural(+t.extra_time_periods, 'período')} extra de ${t.extra_time_period_minutes} min; después, penales`
    : 'Un empate va directo a penales';
  const info = [
    ['Tipo', TOURNAMENT_TYPES[t.type]?.label],
    ['Fechas', `${date(t.start_date)} – ${date(t.end_date)}`],
    ['Equipos inscritos', String(t.participants.length)],
    ['Duración de cada partido', `${t.match_duration_minutes} minutos`],
    ...(league ? [
      ['Vueltas', +t.league_rounds === 2 ? 'Ida y vuelta' : 'Solo ida'],
      ['Jornadas', t.matchdays?.length ? String(t.matchdays.length) : `${t.matchday_count} (se calculan al cerrar la inscripción)`],
      ['Puntos', `Ganar ${t.points_win} · Empatar ${t.points_draw} · Perder ${t.points_loss}`],
    ] : [
      ['Si hay empate', rules],
      ['Tanda de penales', `${t.penalty_attempts_per_team} tiros por equipo, ${+t.penalty_sudden_death ? 'con' : 'sin'} muerte súbita`],
    ]),
    ['Orden de los equipos', t.registrations_closed_at ? (t.draw_method === 'random' ? 'Sorteo aleatorio' : 'Orden manual') : 'Se define al cerrar la inscripción'],
  ];
  const upcoming = t.matches.filter(m => ['scheduled', 'in_progress', 'awaiting_confirmation'].includes(m.workflow_status))
    .sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
  const pending = t.matches.filter(m => m.workflow_status === 'unprogrammed' && m.home_team_id && m.away_team_id);
  return `<div class="grid grid--sidebar">
    <div class="card"><div class="card__header"><div><h2>Partidos activos y próximos</h2><p class="card__subtitle">Programados, en juego o con resultado por confirmar.</p></div></div>
      ${upcoming.length ? matchList(upcoming) : emptyState('No hay partidos programados', pending.length ? `${plural(pending.length, 'partido')} listo(s) para programar en la pestaña ${league ? 'Calendario' : 'Cuadro'}.` : 'Aparecerán aquí cuando se programen.', 'calendar')}
    </div>
    <div class="card"><div class="card__header"><h2>Configuración</h2></div>
      <dl class="definition-list">${info.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>`).join('')}</dl>
    </div>
  </div>`;
}

export function matchList(matches) {
  return `<ul class="list">${matches.map(m => {
    const state = matchStatus(m);
    return `<li class="list__row"><div class="list__main"><span class="list__title">${esc(teamName(m.home_team_id))} vs. ${esc(teamName(m.away_team_id))}</span>
      <span class="list__meta">${icon('clock')} ${esc(dateTime(m.scheduled_at))} · ${icon('pin')} ${esc(m.venue ?? 'Sin cancha')}</span></div>
      ${badge(state.label, state.tone)}${button('Abrir', { action: 'open-match', data: { id: m.id }, small: true })}</li>`;
  }).join('')}</ul>`;
}

// ---- Teams --------------------------------------------------------------------------------------

function teamsTab(t) {
  const open = t.status === 'registration' && !t.registrations_closed_at;
  const editable = open && isAdmin();
  const enrolled = new Set(t.participants.map(p => +p.team_id));
  const available = store.data.teams.filter(candidate => +candidate.active && !enrolled.has(+candidate.id));
  const rows = t.participants.map((p, index) => `<div class="participant">
      <span class="rank" aria-label="Posición ${index + 1}">${index + 1}</span>
      <span class="participant__name">${esc(teamName(p.team_id))}</span>
      ${editable ? `${iconButton('up', 'Subir en el orden', { action: 'move-participant', data: { team: p.team_id, direction: -1 } })}${iconButton('down', 'Bajar en el orden', { action: 'move-participant', data: { team: p.team_id, direction: 1 } })}${iconButton('delete', `Retirar a ${teamName(p.team_id)}`, { action: 'remove-participant', data: { team: p.team_id }, danger: true })}` : ''}
    </div>`).join('');

  if (t.status === 'draft') {
    return `<div class="card">${emptyState('La inscripción aún no está abierta', isAdmin() ? 'Use «Abrir inscripciones» en la parte superior para empezar a inscribir equipos.' : 'El administrador abrirá la inscripción pronto.', 'teams')}</div>`;
  }
  const enrollForm = editable ? `<form id="enroll-form" class="cluster cluster--end" novalidate>
      ${field({ name: 'team_id', label: 'Equipo a inscribir', type: 'select', required: true, placeholder: available.length ? 'Seleccione un equipo' : 'No quedan equipos activos disponibles', options: available.map(item => ({ value: item.id, label: item.name })), hint: 'Solo aparecen equipos activos que aún no están inscritos.' })}
      ${button('Inscribir', { type: 'submit', tone: 'primary', iconName: 'plus' })}
      <p class="form-error" role="alert"></p>
    </form>` : '';
  const closeForm = editable ? `<form id="close-registration-form" class="stack" novalidate>
      <h3>Cerrar inscripción</h3>
      <p class="muted">${t.type === 'league'
        ? 'El orden de los equipos define el calendario: qué equipos se enfrentan en cada jornada y quién descansa si son impares. Se necesitan al menos 3 equipos.'
        : 'El orden de los equipos define los cruces del cuadro: el 1 enfrenta al último, el 2 al penúltimo, etc. Si el número de equipos no es potencia de 2, los primeros del orden pasan directo a la siguiente ronda.'}</p>
      ${field({ name: 'draw_method', label: '¿Cómo ordenar a los equipos?', type: 'select', required: true, value: 'random', options: [{ value: 'random', label: 'Sorteo aleatorio' }, { value: 'manual', label: 'Usar el orden de la lista (manual)' }], hint: 'Con orden manual puede usar las flechas para ubicar a cada equipo.' })}
      <div>${button('Cerrar inscripción', { type: 'submit', tone: 'primary', iconName: 'check' })}</div>
      <p class="form-error" role="alert"></p>
    </form>` : '';
  return `<div class="grid grid--sidebar">
    <div class="card"><div class="card__header"><div><h2>Equipos inscritos</h2><p class="card__subtitle">${esc(plural(t.participants.length, 'equipo'))}${open ? ' · inscripción abierta' : ' · inscripción cerrada'}</p></div></div>
      ${enrollForm}${rows || emptyState('Aún no hay equipos inscritos', editable ? 'Inscriba al menos 2 equipos.' : '', 'teams')}
    </div>
    <div class="card">${closeForm || alertBox('info', open ? 'Inscripción abierta' : 'Inscripción cerrada', open ? 'El administrador puede inscribir equipos.' : `Orden definido por ${t.draw_method === 'random' ? 'sorteo aleatorio' : 'el administrador'}.`)}</div>
  </div>`;
}

// ---- Bracket ------------------------------------------------------------------------------------

function bracketTab(t) {
  if (!t.matches.length) {
    return `<div class="card">${emptyState('El cuadro aún no se ha generado', t.registrations_closed_at ? 'Use «Generar cuadro» en la parte superior.' : 'Primero inscriba a los equipos y cierre la inscripción.', 'trophy')}</div>`;
  }
  const rounds = Math.max(...t.matches.map(m => +m.round_number));
  const columns = Array.from({ length: rounds }, (_, index) => {
    const round = index + 1;
    const matches = t.matches.filter(m => +m.round_number === round);
    return `<section class="bracket__round" aria-label="${esc(roundLabel(round, rounds))}"><h3 class="bracket__round-title">${esc(roundLabel(round, rounds))}</h3>${matches.map(matchCard).join('')}</section>`;
  }).join('');
  return `<div class="card"><div class="card__header"><div><h2>Cuadro de eliminación directa</h2><p class="card__subtitle">Seleccione un partido para programarlo, registrarlo o ver su detalle.</p></div></div><div class="bracket">${columns}</div></div>`;
}

function matchCard(m) {
  const state = matchStatus(m);
  const hasScore = m.home_score !== null && m.home_score !== undefined;
  const side = (teamId, score, penalties) => {
    const winner = m.winner_team_id && +m.winner_team_id === +teamId && ['confirmed', 'bye'].includes(m.workflow_status);
    return `<span class="match-card__team${winner ? ' match-card__team--winner' : ''}${teamId ? '' : ' match-card__team--tbd'}"><span>${winner ? icon('check') : ''} ${esc(teamId ? teamName(teamId) : 'Por definir')}</span>${hasScore ? `<span class="match-card__score">${score}${penalties !== null && penalties !== undefined ? ` (${penalties})` : ''}</span>` : ''}</span>`;
  };
  if (m.workflow_status === 'bye') {
    return `<div class="match-card match-card--bye">${side(m.home_team_id ?? m.away_team_id)}<span class="match-card__footer">${badge(state.label, state.tone)}</span></div>`;
  }
  const footer = m.scheduled_at ? `${icon('clock')} ${esc(dateTime(m.scheduled_at))}` : '';
  return `<button class="match-card" type="button" data-action="open-match" data-id="${m.id}" aria-label="Partido: ${esc(teamName(m.home_team_id))} contra ${esc(teamName(m.away_team_id))}. ${esc(state.label)}">
    ${side(m.home_team_id, m.home_score, m.home_penalties)}${side(m.away_team_id, m.away_score, m.away_penalties)}
    <span class="match-card__footer">${badge(state.label, state.tone)}${footer}${m.decided_by && m.decided_by !== 'regulation' ? ` · ${esc(DECIDED_BY[m.decided_by])}` : ''}</span>
  </button>`;
}

// ---- Scorers ------------------------------------------------------------------------------------

function scorersTab(t) {
  if (!t.scorers.length) return `<div class="card">${emptyState('Todavía no hay goles', 'La tabla se llena con los goles de los partidos de este torneo. Los autogoles no cuentan.', 'ball')}</div>`;
  return `<div class="card card--flush"><div class="card__header"><div><h2>Goleadores del torneo</h2><p class="card__subtitle">Incluye resultados por confirmar. Los autogoles no se atribuyen a nadie.</p></div></div>`
    + table([{ label: 'Pos.' }, { label: 'Jugador' }, { label: 'Equipo' }, { label: 'Goles', numeric: true }], t.scorers.map((row, index) => {
      const scorer = player(row.player_id);
      return [`<span class="rank">${index + 1}</span>`, `<span class="person">${avatar(scorer)}<strong>${esc(fullName(scorer))}</strong></span>`, esc(team(row.team_id)?.name), `<strong>${row.goals}</strong>`];
    }), { caption: 'Goleadores del torneo' }) + '</div>';
}

// ---- Actions ------------------------------------------------------------------------------------

/** Runs a tournament change and redraws the page with the refreshed detail. */
export async function tournamentChange(path, { method = 'POST', body = {}, message }) {
  const { record } = await api(`/tournaments/${store.tournament.id}${path}`, { method, body: method === 'DELETE' ? undefined : body });
  applyTournament(record);
  rerender();
  if (message) toast(message);
  return record;
}

onAction('open-registration', () => tournamentChange('/registration', { message: 'Inscripción abierta. Ahora inscriba a los equipos.' }));

onAction('generate-bracket', async () => {
  const ok = await confirmDialog({ title: '¿Generar el cuadro?', text: 'Se crearán todos los partidos con el orden actual de los equipos. Después ya no se podrá cambiar la inscripción.', confirmLabel: 'Generar cuadro' });
  if (ok) await tournamentChange('/bracket', { message: 'Cuadro generado. Programe los partidos de la primera ronda.' }).then(record => { location.hash = `#torneos/${record.id}/cuadro`; });
});

onSubmit('enroll-form', async form => {
  const select = form.elements.namedItem('team_id');
  if (!select.value) { select.focus(); return; }
  await tournamentChange('/participants', { body: { team_id: select.value }, message: 'Equipo inscrito.' });
});

onSubmit('close-registration-form', async form => {
  const method = form.elements.namedItem('draw_method').value;
  const ok = await confirmDialog({ title: '¿Cerrar la inscripción?', text: `Ya no se podrán agregar ni retirar equipos. Orden: ${method === 'random' ? 'sorteo aleatorio' : 'el de la lista'}.`, confirmLabel: 'Cerrar inscripción' });
  if (ok) await tournamentChange('/registration/close', { body: { draw_method: method }, message: 'Inscripción cerrada. Ya puede generar el cuadro.' });
});

onAction('remove-participant', async trigger => {
  const name = teamName(trigger.dataset.team);
  if (await confirmDialog({ title: `¿Retirar a ${name}?`, text: 'El equipo dejará de estar inscrito en este torneo.', confirmLabel: 'Retirar', danger: true })) {
    await tournamentChange(`/participants/${trigger.dataset.team}`, { method: 'DELETE', message: `${name} fue retirado.` });
  }
});

onAction('move-participant', async trigger => {
  const order = store.tournament.participants.map(p => +p.team_id);
  const from = order.indexOf(+trigger.dataset.team);
  const to = from + +trigger.dataset.direction;
  if (to < 0 || to >= order.length) return;
  [order[from], order[to]] = [order[to], order[from]];
  await tournamentChange('/participants/order', { body: { team_ids: order } });
});

onAction('open-match', trigger => openMatch(+trigger.dataset.id));
