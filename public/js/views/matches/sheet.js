// Match sheet: the complete list of goals, one column per team. The score is never typed: it is counted
// from the goals. A knockout match still level at the end opens the penalty shoot-out panel.
import { $, $$, esc, icon } from '../../core/dom.js';
import { onAction, onChange, onSubmit } from '../../core/events.js';
import { store, teamName } from '../../core/store.js';
import { evaluateShootout } from '../../domain/penalties.js';
import { tally } from '../../domain/score.js';
import { alertBox, badge, button } from '../../ui/components.js';
import { confirmDialog, dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { currentMatch, matchChange, matchEvents } from './common.js';
import { periodOptions, scorerOptions } from './goal-form.js';
import { openMatch } from './match-dialog.js';

const MODES = {
  result: { path: '/result', title: 'Finalizar partido', submit: 'Enviar resultado', done: 'Resultado enviado. Falta confirmarlo.' },
  revision: { path: '/revision', title: 'Editar resultado', submit: 'Guardar cambios', done: 'Resultado actualizado. Falta confirmarlo.' },
  correction: { path: '/correction', title: 'Corregir resultado confirmado', submit: 'Guardar corrección', done: 'Resultado corregido. Confírmelo de nuevo.' },
};

let rowSequence = 0;

const sideTeam = (match, side) => (side === 'home' ? match.home_team_id : match.away_team_id);
const extraPeriods = () => (store.tournament.type === 'knockout' ? +store.tournament.extra_time_periods : 0);

function goalRow(match, side, event = {}) {
  const n = ++rowSequence;
  const ownGoal = Boolean(+event.own_goal);
  return `<div class="goal-row" data-side="${side}" data-row="${n}">
    ${field({ name: `player_${n}`, label: ownGoal ? 'Jugador rival (autogol)' : 'Jugador', type: 'select', value: event.player_id ?? '', required: true, placeholder: 'Seleccione', options: scorerOptions(match, sideTeam(match, side), ownGoal), change: 'sheet' })}
    ${field({ name: `minute_${n}`, label: 'Minuto', type: 'number', value: event.minute ?? '', required: true, min: 0, max: 150 })}
    <button class="icon-button icon-button--danger" type="button" data-action="sheet-remove-goal" aria-label="Quitar este gol">${icon('delete')}</button>
    <label class="check"><input type="checkbox" name="own_${n}" value="1" ${ownGoal ? 'checked' : ''} data-change="sheet-own"> Autogol de un jugador rival</label>
    ${extraPeriods() ? field({ name: `period_${n}`, label: 'Momento', type: 'select', value: event.period_number ?? 0, required: true, options: periodOptions(store.tournament), change: 'sheet', className: 'field--period' }) : ''}
  </div>`;
}

function teamColumn(match, side, events) {
  const teamId = sideTeam(match, side);
  const rows = events.filter(e => +e.credited_team_id === +teamId).map(e => goalRow(match, side, e)).join('');
  return `<section class="sheet-team" data-side="${side}" aria-labelledby="sheet-${side}-title">
    <div class="sheet-team__header"><h3 id="sheet-${side}-title">${esc(teamName(teamId))}</h3><span class="badge badge--primary" data-goals="${side}">0 goles</span></div>
    <div class="stack" data-rows="${side}">${rows}</div>
    ${button(`Agregar gol de ${teamName(teamId)}`, { action: 'sheet-add-goal', data: { side }, iconName: 'plus' })}
  </section>`;
}

/** Corrections of a confirmed result are exceptional: ask first, then open the sheet. */
export async function openCorrection(match) {
  const ok = await confirmDialog({
    title: '¿Corregir un resultado confirmado?',
    text: 'Úselo solo para errores. El resultado deberá confirmarse de nuevo y, si cambia el ganador, también cambia el clasificado de la siguiente ronda. Queda registrado en el historial.',
    confirmLabel: 'Corregir',
    danger: true,
  });
  if (ok) openMatchSheet(match, 'correction');
  else openMatch(match.id);
}

export function openMatchSheet(match, mode = 'result') {
  const config = MODES[mode];
  rowSequence = 0;
  const events = matchEvents(match);
  const kicks = (store.tournament.penalty_kicks ?? []).filter(k => +k.tournament_match_id === +match.id).sort((a, b) => a.kick_number - b.kick_number);
  const knockout = store.tournament.type === 'knockout';
  openDialog(`${dialogHeader({ eyebrow: 'Hoja del partido', title: config.title, subtitle: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}`, size: 'wide' })}
    <form id="sheet-form" novalidate data-match="${match.id}" data-mode="${mode}" data-first-kicker="${match.first_kicker_team_id ?? ''}" data-kicks="${esc(JSON.stringify(kicks.map(k => +k.scored)))}"><div class="dialog__body">
      ${alertBox('info', 'El marcador se calcula con los goles', `Agregue cada gol en la columna del equipo al que le cuenta. Si un jugador marcó en su propia portería, regístrelo en la columna del rival y marque «Autogol».${extraPeriods() ? ' Use «Tiempo extra» solo si el tiempo reglamentario terminó empatado.' : ''}`)}
      <div class="scoreboard" aria-live="polite"><span class="scoreboard__team">${esc(teamName(match.home_team_id))}</span><span class="scoreboard__score" id="sheet-score">0 – 0</span><span class="scoreboard__team">${esc(teamName(match.away_team_id))}</span><span class="scoreboard__detail" id="sheet-score-detail"></span></div>
      <div class="sheet-columns">${teamColumn(match, 'home', events)}${teamColumn(match, 'away', events)}</div>
      <div id="sheet-warning"></div>
      ${knockout ? `<fieldset class="fieldset shootout" id="sheet-shootout" hidden><legend>Tanda de penales</legend>
        <p class="muted">El partido terminó empatado: registre los tiros en el orden en que se patearon. Cada equipo patea ${store.tournament.penalty_attempts_per_team} tiros${+store.tournament.penalty_sudden_death ? '; si siguen empatados, se agrega un tiro por equipo hasta que haya ganador' : ''}.</p>
        ${field({ name: 'first_kicker_team_id', label: 'Equipo que patea primero', type: 'select', value: match.first_kicker_team_id ?? '', required: true, placeholder: 'Seleccione', options: [match.home_team_id, match.away_team_id].map(id => ({ value: id, label: teamName(id) })), change: 'sheet' })}
        <div class="shootout__kicks" id="sheet-kicks"></div>
        <p class="shootout__status" id="sheet-shootout-status" aria-live="polite"></p>
      </fieldset>` : ''}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${button(config.submit, { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`, 'wide');
  recalculate($('#sheet-form'));
}

/** Goals as entered in the sheet. */
function sheetEvents(form, match) {
  return $$('.goal-row', form).map(row => {
    const n = row.dataset.row;
    const value = name => form.elements.namedItem(`${name}_${n}`)?.value ?? '';
    return {
      player_id: value('player'),
      minute: value('minute'),
      period_number: +(value('period') || 0),
      own_goal: form.elements.namedItem(`own_${n}`).checked ? 1 : 0,
      credited_team_id: sideTeam(match, row.dataset.side),
    };
  });
}

function shootoutInput(form, match) {
  const first = +form.elements.namedItem('first_kicker_team_id')?.value || 0;
  const results = $$('#sheet-kicks select', form).map(select => (select.value === '' ? null : +select.value));
  return { first, results };
}

function renderKicks(form, match, outcome, first, results) {
  const container = $('#sheet-kicks', form);
  const count = first ? outcome.kicksNeeded : 0;
  container.innerHTML = Array.from({ length: count }, (_, index) => {
    const value = results[index];
    const disabled = outcome.decidedAt >= 0 && index > outcome.decidedAt;
    return `<div class="field"><label class="field__label" for="kick-${index}">Tiro ${index + 1} · ${esc(teamName(outcome.kickerOf(index)))}</label>
      <select id="kick-${index}" name="kick_${index}" data-change="sheet" ${disabled ? 'disabled' : ''}><option value="">—</option><option value="1" ${value === 1 ? 'selected' : ''}>Gol</option><option value="0" ${value === 0 ? 'selected' : ''}>Falló</option></select></div>`;
  }).join('');
}

function recalculate(form) {
  const match = currentMatch(form.dataset.match);
  const events = sheetEvents(form, match);
  const score = tally(events, match.home_team_id, match.away_team_id);
  $('#sheet-score', form).textContent = `${score.home} – ${score.away}`;
  for (const side of ['home', 'away']) $(`[data-goals="${side}"]`, form).textContent = `${score[side]} ${score[side] === 1 ? 'gol' : 'goles'}`;
  const extraGoals = events.some(e => e.period_number > 0);
  const regulationTied = score.regulation.home === score.regulation.away;
  $('#sheet-score-detail', form).textContent = extraGoals ? `Tiempo reglamentario: ${score.regulation.home} – ${score.regulation.away}` : '';
  $('#sheet-warning', form).innerHTML = extraGoals && !regulationTied
    ? alertBox('warning', 'Revise los goles de tiempo extra', 'Hay goles en tiempo extra, pero el tiempo reglamentario no terminó empatado.')
    : '';

  const shootout = $('#sheet-shootout', form);
  if (!shootout) return;
  const needed = score.home === score.away;
  shootout.hidden = !needed;
  if (!needed) return;
  const storedResults = JSON.parse(form.dataset.kicks || '[]');
  form.dataset.kicks = '[]';
  const firstControl = form.elements.namedItem('first_kicker_team_id');
  if (!firstControl.value && form.dataset.firstKicker) firstControl.value = form.dataset.firstKicker;
  const { first, results } = shootoutInput(form, match);
  const entered = results.length ? results : storedResults;
  const outcome = evaluateShootout(entered, { first, home: +match.home_team_id, away: +match.away_team_id, attempts: +store.tournament.penalty_attempts_per_team, suddenDeath: Boolean(+store.tournament.penalty_sudden_death) });
  renderKicks(form, match, outcome, first, entered);
  const status = $('#sheet-shootout-status', form);
  status.innerHTML = !first ? 'Elija qué equipo patea primero.'
    : outcome.winner ? `${badge('Definido', 'success')} Gana ${esc(teamName(outcome.winner))} ${Math.max(outcome.home, outcome.away)}–${Math.min(outcome.home, outcome.away)} en penales.`
      : outcome.tiedWithoutSuddenDeath ? `${badge('Empate', 'danger')} La tanda terminó empatada y el torneo no usa muerte súbita: revise los tiros.`
        : `Penales: ${esc(teamName(match.home_team_id))} ${outcome.home} – ${outcome.away} ${esc(teamName(match.away_team_id))}${outcome.suddenDeath ? ' · muerte súbita' : ''}.`;
}

onAction('sheet-add-goal', trigger => {
  const form = trigger.closest('form');
  const match = currentMatch(form.dataset.match);
  const container = $(`[data-rows="${trigger.dataset.side}"]`, form);
  container.insertAdjacentHTML('beforeend', goalRow(match, trigger.dataset.side));
  container.lastElementChild.querySelector('select').focus();
  recalculate(form);
});

onAction('sheet-remove-goal', trigger => {
  const form = trigger.closest('form');
  trigger.closest('.goal-row').remove();
  recalculate(form);
});

onChange('sheet', control => recalculate(control.form));

onChange('sheet-own', checkbox => {
  const form = checkbox.form;
  const match = currentMatch(form.dataset.match);
  const row = checkbox.closest('.goal-row');
  const select = form.elements.namedItem(`player_${row.dataset.row}`);
  const options = scorerOptions(match, sideTeam(match, row.dataset.side), checkbox.checked);
  select.innerHTML = `<option value="">Seleccione</option>${options.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join('')}`;
  form.querySelector(`label[for="${select.id}"]`).firstChild.textContent = checkbox.checked ? 'Jugador rival (autogol)' : 'Jugador';
  recalculate(form);
});

onSubmit('sheet-form', async form => {
  const match = currentMatch(form.dataset.match);
  const mode = MODES[form.dataset.mode];
  const events = sheetEvents(form, match);
  const body = { goal_events: events.map(({ credited_team_id, ...event }) => event) };
  const shootout = $('#sheet-shootout', form);
  if (shootout && !shootout.hidden) {
    const { first, results } = shootoutInput(form, match);
    const outcome = evaluateShootout(results, { first, home: +match.home_team_id, away: +match.away_team_id, attempts: +store.tournament.penalty_attempts_per_team, suddenDeath: Boolean(+store.tournament.penalty_sudden_death) });
    if (first && !outcome.winner) {
      form.querySelector('.form-error').textContent = 'Complete la tanda de penales hasta que haya un ganador.';
      return;
    }
    body.first_kicker_team_id = first;
    body.penalty_kicks = results.slice(0, outcome.decidedAt + 1).map((scored, index) => ({ team_id: outcome.kickerOf(index), scored }));
  }
  const saved = await submitForm(form, () => matchChange(match, mode.path, { body }));
  if (!saved) return;
  toast(mode.done);
  openMatch(match.id);
});
