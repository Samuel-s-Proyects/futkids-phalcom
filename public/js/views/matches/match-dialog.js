// Match detail: score, goals, cards and the next actions allowed for the signed-in person.
import { esc, icon } from '../../core/dom.js';
import { onAction } from '../../core/events.js';
import { dateTime, fullName } from '../../core/format.js';
import { isAdmin, player, referee, store, teamName } from '../../core/store.js';
import { DECIDED_BY, matchStatus, roundLabel } from '../../domain/labels.js';
import { periodLabel } from '../../domain/score.js';
import { alertBox, badge, button, iconButton } from '../../ui/components.js';
import { confirmDialog, dialogHeader, openDialog } from '../../ui/dialog.js';
import { toast } from '../../ui/toast.js';
import { canOperate, currentMatch, ensureTournamentFor, isAssignedReferee, matchChange, matchEvents, matchIncidents } from './common.js';
import { openGoalForm } from './goal-form.js';
import { openIncidentForm } from './incident-form.js';
import { matchdayName, matchdayOf, openMoveForm, openPostponeForm } from './league-actions.js';
import { openScheduleForm } from './schedule-form.js';
import { openCorrection, openMatchSheet } from './sheet.js';

function scoreboard(match, events) {
  const hasResult = match.home_score !== null && match.home_score !== undefined;
  const live = !hasResult && match.workflow_status === 'in_progress';
  const count = side => events.filter(e => +e.credited_team_id === +match[`${side}_team_id`]).length;
  const score = hasResult ? `${match.home_score} – ${match.away_score}` : live ? `${count('home')} – ${count('away')}` : 'vs.';
  const detail = hasResult
    ? (match.decided_by === 'penalties' ? `Definido en penales: ${match.home_penalties}–${match.away_penalties}` : `Definido en ${DECIDED_BY[match.decided_by]?.toLowerCase() ?? 'el partido'}`)
    : live ? 'Marcador en vivo según los goles registrados' : 'Aún sin resultado';
  return `<div class="scoreboard" aria-label="Marcador"><span class="scoreboard__team">${esc(teamName(match.home_team_id))}</span><span class="scoreboard__score">${score}</span><span class="scoreboard__team">${esc(teamName(match.away_team_id))}</span><span class="scoreboard__detail">${esc(detail)}</span></div>`;
}

function goalsList(events, extraMinutes) {
  if (!events.length) return '<p class="muted">Todavía no hay goles registrados.</p>';
  return `<ol class="timeline">${events.map(e => `<li><span class="timeline__minute">${e.minute}′</span>${icon('ball')}<span><strong>${esc(fullName(player(e.player_id)))}</strong> · ${esc(teamName(e.team_id))}${+e.own_goal ? ` · <span class="badge badge--warning">Autogol, cuenta para ${esc(teamName(e.credited_team_id))}</span>` : ''}${+e.period_number ? ` · ${esc(periodLabel(e.period_number, extraMinutes))}` : ''}</span></li>`).join('')}</ol>`;
}

function incidentsList(match, incidents) {
  if (!incidents.length) return '<p class="muted">Sin tarjetas registradas.</p>';
  const editable = isAssignedReferee(match) && match.workflow_status === 'in_progress';
  return `<ol class="timeline">${incidents.sort((a, b) => a.minute - b.minute).map(i => `<li><span class="timeline__minute">${i.minute}′</span>${badge(i.card_type === 'red' ? 'Roja' : 'Amarilla', i.card_type === 'red' ? 'danger' : 'warning')}<span><strong>${esc(fullName(player(i.player_id)))}</strong> · ${esc(i.description)}${i.suspension_date ? ` · Suspendido el ${esc(i.suspension_date.split('-').reverse().join('/'))}` : ''}</span>${editable ? `${iconButton('edit', 'Editar tarjeta', { action: 'edit-incident', data: { id: i.id, match: match.id } })}${iconButton('delete', 'Eliminar tarjeta', { action: 'delete-incident', data: { id: i.id, match: match.id }, danger: true })}` : ''}</li>`).join('')}</ol>`;
}

function actions(match) {
  const id = { id: match.id };
  const list = [];
  const operator = canOperate(match);
  const both = match.home_team_id && match.away_team_id;
  const league = store.tournament.type === 'league' && !+store.tournament.is_legacy;
  if (league && isAdmin() && ['unprogrammed', 'scheduled'].includes(match.workflow_status)) {
    list.push(button('Aplazar', { action: 'postpone-match', data: id, iconName: 'clock' }));
  }
  if (league && isAdmin() && ['unprogrammed', 'suspended'].includes(match.workflow_status)) {
    list.push(button('Mover de jornada', { action: 'move-match', data: id, iconName: 'calendar' }));
  }
  switch (match.workflow_status) {
    case 'unprogrammed':
    case 'suspended':
      if (isAdmin() && both) list.push(button('Programar partido', { action: 'schedule-match', data: id, tone: 'primary', iconName: 'calendar' }));
      break;
    case 'scheduled':
      if (isAdmin()) list.push(button('Cambiar programación', { action: 'schedule-match', data: id, iconName: 'edit' }));
      if (operator) list.push(button('Iniciar partido', { action: 'start-match', data: id, tone: 'primary', iconName: 'play' }));
      break;
    case 'in_progress':
      if (isAssignedReferee(match)) list.push(button('Registrar tarjeta', { action: 'new-incident', data: id, iconName: 'card' }));
      if (operator) {
        list.push(button('Registrar gol', { action: 'new-goal', data: id, iconName: 'ball' }));
        list.push(button('Finalizar y enviar resultado', { action: 'open-sheet', data: { ...id, mode: 'result' }, tone: 'primary', iconName: 'check' }));
      }
      break;
    case 'awaiting_confirmation':
      if (operator) {
        list.push(button('Editar resultado', { action: 'open-sheet', data: { ...id, mode: 'revision' }, iconName: 'edit' }));
        list.push(button('Confirmar resultado', { action: 'confirm-result', data: id, tone: 'primary', iconName: 'check' }));
      }
      break;
    case 'confirmed':
      if (isAdmin()) list.push(button('Corregir resultado', { action: 'open-sheet', data: { ...id, mode: 'correction' }, iconName: 'edit' }));
      break;
    default:
  }
  return list.join('');
}

function guidance(match) {
  if (!match.home_team_id || !match.away_team_id) return alertBox('info', 'Rivales por definir', 'Este partido se podrá programar cuando terminen los partidos previos.');
  const operator = canOperate(match);
  const messages = {
    unprogrammed: isAdmin() ? ['info', 'Siguiente paso: programarlo', 'Asigne fecha, hora, cancha y árbitro.'] : ['info', 'Pendiente de programar', 'El administrador asignará fecha, cancha y árbitro.'],
    scheduled: operator ? ['info', 'Listo para jugarse', 'Al empezar el partido, pulse «Iniciar partido» para registrar goles y tarjetas.'] : null,
    in_progress: operator ? ['success', 'Partido en juego', 'Registre cada gol al momento o, al terminar, use «Finalizar y enviar resultado» para revisar la hoja completa.'] : null,
    awaiting_confirmation: operator ? ['warning', 'Revise y confirme', store.tournament.type === 'league'
      ? 'Compruebe goles y marcador. Al confirmar, el resultado se suma a la tabla.'
      : 'Compruebe goles y marcador. Al confirmar, el ganador avanza en el cuadro.'] : null,
    suspended: ['danger', 'Partido aplazado', `${match.suspension_reason ?? 'Debe reprogramarse.'} Muévalo a otra jornada o prográmelo de nuevo.`],
  }[match.workflow_status];
  return messages ? alertBox(...messages) : '';
}

/** Opens (or refreshes) the match dialog. */
export async function openMatch(matchId, tournamentId = 0) {
  const match = await ensureTournamentFor(matchId, tournamentId);
  if (!match) return;
  const t = store.tournament;
  const rounds = Math.max(...t.matches.map(m => +m.round_number));
  const stage = t.type === 'league' ? matchdayName(matchdayOf(match)) : roundLabel(+match.round_number, rounds);
  const state = matchStatus(match);
  const events = matchEvents(match);
  const assignedReferee = referee(match.referee_user_id);
  openDialog(`${dialogHeader({ eyebrow: `${t.name} · ${stage}`, title: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}`, size: 'wide' })}
    <div class="dialog__body">
      <div class="cluster">${badge(state.label, state.tone)}</div>
      ${guidance(match)}
      ${scoreboard(match, events)}
      <dl class="definition-list">
        <dt>Fecha y hora</dt><dd>${esc(match.scheduled_at ? dateTime(match.scheduled_at) : 'Sin programar')}</dd>
        <dt>Cancha</dt><dd>${esc(match.venue ?? '—')}</dd>
        <dt>Árbitro</dt><dd>${esc(assignedReferee?.name ?? '—')}</dd>
      </dl>
      <div class="grid grid--2">
        <section><h3>Goles</h3>${goalsList(events, t.extra_time_period_minutes)}</section>
        <section><h3>Tarjetas</h3>${incidentsList(match, matchIncidents(match))}</section>
      </div>
    </div>
    <div class="dialog__footer"><button class="button" type="button" data-action="close-dialog">Cerrar</button>${actions(match)}</div>`, 'wide');
}

const matchFrom = trigger => currentMatch(trigger.dataset.id ?? trigger.dataset.match);

onAction('schedule-match', trigger => openScheduleForm(matchFrom(trigger)));
onAction('new-goal', trigger => openGoalForm(matchFrom(trigger)));
onAction('new-incident', trigger => openIncidentForm(matchFrom(trigger)));
onAction('edit-incident', trigger => openIncidentForm(matchFrom(trigger), +trigger.dataset.id));
onAction('open-sheet', trigger => (trigger.dataset.mode === 'correction' ? openCorrection(matchFrom(trigger)) : openMatchSheet(matchFrom(trigger), trigger.dataset.mode)));
onAction('back-to-match', trigger => openMatch(+trigger.dataset.id));
onAction('postpone-match', trigger => openPostponeForm(matchFrom(trigger)));
onAction('move-match', trigger => openMoveForm(matchFrom(trigger)));

onAction('start-match', async trigger => {
  const match = matchFrom(trigger);
  if (!await confirmDialog({ title: '¿Iniciar el partido?', text: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}. A partir de ahora se pueden registrar goles y tarjetas.`, confirmLabel: 'Iniciar partido' })) return openMatch(match.id);
  await matchChange(match, '/start');
  toast('Partido iniciado.');
  openMatch(match.id);
});

onAction('confirm-result', async trigger => {
  const match = matchFrom(trigger);
  const penalties = match.decided_by === 'penalties' ? ` (penales ${match.home_penalties}–${match.away_penalties})` : '';
  const score = `${teamName(match.home_team_id)} ${match.home_score} – ${match.away_score} ${teamName(match.away_team_id)}${penalties}`;
  if (!await confirmDialog({ title: '¿Confirmar el resultado?', text: `${score}. Después de confirmar, solo un administrador podrá corregirlo.`, confirmLabel: 'Confirmar resultado' })) return openMatch(match.id);
  await matchChange(match, '/confirm');
  toast('Resultado confirmado.');
  openMatch(match.id);
});

onAction('delete-incident', async trigger => {
  const match = matchFrom(trigger);
  if (!await confirmDialog({ title: '¿Eliminar esta tarjeta?', text: 'La tarjeta y su suspensión (si la hay) se eliminarán del partido.', confirmLabel: 'Eliminar', danger: true })) return openMatch(match.id);
  await matchChange(match, `/incidents/${trigger.dataset.id}`, { method: 'DELETE' });
  toast('Tarjeta eliminada.');
  openMatch(match.id);
});
