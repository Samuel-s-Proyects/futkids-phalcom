// Tournament wizard: 1. type · 2. data · 3. rules (tie-break for knockout, rounds and points for a league)
// · 4. review. Editing starts at step 2.
import { api } from '../../core/api.js';
import { today } from '../../core/clock.js';
import { $, $$, esc, icon } from '../../core/dom.js';
import { onAction, onChange, onSubmit } from '../../core/events.js';
import { date } from '../../core/format.js';
import { go } from '../../core/router.js';
import { applyTournament, isAdmin, store } from '../../core/store.js';
import { TOURNAMENT_TYPES } from '../../domain/labels.js';
import { closeDialogs, dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, showServerErrors, validateControl, clearErrors, formValues } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';

const STEPS = ['Tipo de torneo', 'Datos generales', 'Reglas', 'Revisión'];

function typeStep(current) {
  const choice = (type, enabled, note = '') => `<label class="choice-card">
      <input type="radio" name="type" value="${type}" ${current === type ? 'checked' : ''} ${enabled ? '' : 'disabled'} required data-change="tournament-type">
      <span class="choice-card__title">${icon(type === 'knockout' ? 'trophy' : 'calendar')} ${esc(TOURNAMENT_TYPES[type].label)}</span>
      <span class="choice-card__text">${esc(TOURNAMENT_TYPES[type].description)}</span>
      ${note ? `<span class="badge">${esc(note)}</span>` : ''}
    </label>`;
  return `<fieldset class="stack" data-step="0"><legend class="visually-hidden">${STEPS[0]}</legend>
    <p>¿Cómo se definirá al ganador?</p>
    <div class="choice-cards">${choice('knockout', true)}${choice('league', true)}</div>
  </fieldset>`;
}

function dataStep(t) {
  return `<fieldset class="form-grid" data-step="1" hidden><legend class="visually-hidden">${STEPS[1]}</legend>
    ${field({ name: 'name', label: 'Nombre del torneo', value: t.name, required: true, rule: 'title', maxlength: 150, full: true, placeholder: 'Ej.: Copa Apertura Sub-12 2026', hint: 'Nombre con el que se identifica el torneo.' })}
    ${field({ name: 'start_date', label: 'Fecha de inicio', type: 'date', value: t.start_date, required: true, min: t.id ? '' : today(), change: 'tournament-start', hint: 'Día en que comienza el torneo.' })}
    ${field({ name: 'end_date', label: 'Fecha de finalización', type: 'date', value: t.end_date, required: true, min: t.start_date || today(), hint: 'Día en que termina el torneo.' })}
    ${field({ name: 'match_duration_minutes', label: 'Duración de cada partido (minutos)', type: 'number', value: t.match_duration_minutes ?? 60, required: true, min: 10, max: 180, hint: 'Tiempo de juego de cada partido.' })}
  </fieldset>`;
}

function rulesStep(t) {
  const periods = t.extra_time_periods ?? 2;
  const league = t.type === 'league';
  return `<fieldset data-step="2" hidden><legend class="visually-hidden">${STEPS[2]}</legend>
    <div class="form-grid" data-rules="league" ${league ? '' : 'hidden'}>
    <p class="field--full muted">Cada partido da puntos según el resultado; el empate es un resultado válido. Gana quien termina primero en la tabla.</p>
    ${field({ name: 'league_rounds', label: 'Vueltas', type: 'select', value: t.league_rounds ?? 1, required: true, full: true, disabled: !league, options: [{ value: 1, label: 'Solo ida: cada pareja de equipos juega una vez' }, { value: 2, label: 'Ida y vuelta: cada pareja juega dos veces, una de local y otra de visitante' }], hint: 'Veces que cada equipo se enfrenta a todos los demás.' })}
    ${field({ name: 'points_win', label: 'Puntos por ganar', type: 'number', value: t.points_win ?? 3, required: true, min: 0, max: 10, disabled: !league, hint: 'Puntos por partido ganado.' })}
    ${field({ name: 'points_draw', label: 'Puntos por empatar', type: 'number', value: t.points_draw ?? 1, required: true, min: 0, max: 10, disabled: !league, hint: 'Puntos por partido empatado.' })}
    ${field({ name: 'points_loss', label: 'Puntos por perder', type: 'number', value: t.points_loss ?? 0, required: true, min: 0, max: 10, disabled: !league, hint: 'Puntos por partido perdido.' })}
    </div>
    <div class="form-grid" data-rules="knockout" ${league ? 'hidden' : ''}>
    <p class="field--full muted">Se aplican solo cuando un partido termina empatado, porque en eliminación directa siempre debe haber un ganador.</p>
    ${field({ name: 'extra_time_periods', label: 'Períodos de tiempo extra', type: 'number', value: periods, required: true, min: 0, max: 4, change: 'extra-periods', hint: 'Periodos que se juegan si el partido termina empatado.' })}
    ${field({ name: 'extra_time_period_minutes', label: 'Minutos de cada período extra', type: 'number', value: t.extra_time_period_minutes ?? 10, required: +periods > 0, min: 1, max: 30, disabled: +periods === 0, hint: 'Duración de cada periodo de tiempo extra.' })}
    ${field({ name: 'penalty_attempts_per_team', label: 'Penales iniciales por equipo', type: 'number', value: t.penalty_attempts_per_team ?? 5, required: true, min: 1, max: 10, hint: 'Tiros de cada equipo en la tanda de penales.' })}
    ${field({ name: 'penalty_sudden_death', label: 'Si la tanda sigue empatada', type: 'select', value: t.penalty_sudden_death ?? 1, required: true, options: [{ value: 1, label: 'Muerte súbita: un tiro por equipo hasta que haya ganador' }, { value: 0, label: 'Sin muerte súbita' }], hint: 'Si la tanda sigue empatada, se tira de uno en uno hasta que haya ganador.' })}
    </div>
  </fieldset>`;
}

const reviewStep = () => `<section data-step="3" hidden class="stack"><h3>Revise antes de guardar</h3><dl class="definition-list" id="tournament-review"></dl></section>`;

function render(t) {
  const editing = Boolean(t.id);
  return dialogHeader({ eyebrow: editing ? 'Editar torneo' : 'Nuevo torneo', title: editing ? t.name : 'Crear torneo' })
    + `<form id="tournament-form" novalidate data-id="${t.id ?? ''}" data-step="${editing ? 1 : 0}"><div class="dialog__body">
        <p class="wizard-progress" id="wizard-progress" aria-live="polite"></p>
        ${requiredLegend()}${typeStep(t.type ?? 'knockout')}${dataStep(t)}${rulesStep(t)}${reviewStep()}
        <p class="form-error" role="alert"></p>
      </div><div class="dialog__footer">
        <button class="button" type="button" data-action="wizard-back">${icon('back')} Atrás</button>
        <button class="button button--primary" type="button" data-action="wizard-next">Siguiente ${icon('arrow')}</button>
        <button class="button button--primary" type="submit" hidden>${icon('check')} ${editing ? 'Guardar cambios' : 'Crear torneo'}</button>
      </div></form>`;
}

function showStep(form, step) {
  form.dataset.step = String(step);
  $$('[data-step]', form).forEach(section => { if (section !== form) section.hidden = +section.dataset.step !== step; });
  const first = step === 0 || (step === 1 && form.dataset.id);
  form.querySelector('[data-action="wizard-back"]').hidden = Boolean(first);
  form.querySelector('[data-action="wizard-next"]').hidden = step === 3;
  form.querySelector('[type="submit"]').hidden = step !== 3;
  $('#wizard-progress').textContent = `Paso ${step + 1} de 4 · ${STEPS[step]}`;
  $('#wizard-progress').dataset.step = String(step + 1);
  if (step === 3) fillReview(form);
  form.querySelector(`[data-step="${step}"] input:not([disabled]), [data-step="${step}"] select`)?.focus();
}

function stepIsValid(form, step) {
  const controls = $$(`[data-step="${step}"] input, [data-step="${step}"] select`, form);
  const invalid = controls.filter(control => control.type !== 'radio' && !validateControl(control));
  invalid[0]?.focus();
  return invalid.length === 0;
}

function fillReview(form) {
  const v = formValues(form);
  const rows = [
    ['Tipo', TOURNAMENT_TYPES[v.type]?.label],
    ['Nombre', v.name],
    ['Fechas', `${date(v.start_date)} – ${date(v.end_date)}`],
    ['Duración de cada partido', `${v.match_duration_minutes} minutos`],
    ...(v.type === 'league' ? [
      ['Vueltas', v.league_rounds === '2' ? 'Ida y vuelta' : 'Solo ida'],
      ['Puntos', `Ganar ${v.points_win} · Empatar ${v.points_draw} · Perder ${v.points_loss}`],
    ] : [
      ['Tiempo extra', +v.extra_time_periods ? `${v.extra_time_periods} período(s) de ${v.extra_time_period_minutes} min` : 'No; un empate va directo a penales'],
      ['Penales', `${v.penalty_attempts_per_team} por equipo · ${v.penalty_sudden_death === '1' ? 'con' : 'sin'} muerte súbita`],
    ]),
  ];
  $('#tournament-review').innerHTML = rows.map(([label, value]) => `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>`).join('');
}

export function openTournamentForm(tournament = {}) {
  if (!isAdmin()) return;
  openDialog(render(tournament));
  showStep($('#tournament-form'), tournament.id ? 1 : 0);
}

onAction('new-tournament', () => openTournamentForm());
onAction('edit-tournament', () => openTournamentForm(store.tournament));

onAction('wizard-next', button => {
  const form = button.form;
  const step = +form.dataset.step;
  if (stepIsValid(form, step)) showStep(form, step + 1);
});
onAction('wizard-back', button => showStep(button.form, Math.max(0, +button.form.dataset.step - 1)));

/** Only the rules of the chosen type are shown, validated and sent. */
onChange('tournament-type', radio => {
  const form = radio.form;
  for (const group of $$('[data-rules]', form)) {
    const active = group.dataset.rules === radio.value;
    group.hidden = !active;
    for (const control of group.querySelectorAll('input, select')) control.disabled = !active;
  }
  if (radio.value === 'knockout') {
    const periods = form.elements.namedItem('extra_time_periods');
    form.elements.namedItem('extra_time_period_minutes').disabled = periods.value === '0';
  }
});

onChange('tournament-start', input => {
  const end = input.form.elements.namedItem('end_date');
  end.min = input.value || today();
});
onChange('extra-periods', input => {
  const minutes = input.form.elements.namedItem('extra_time_period_minutes');
  const none = input.value === '0';
  minutes.disabled = none;
  minutes.required = !none;
  if (none) validateControl(minutes);
});

onSubmit('tournament-form', async form => {
  clearErrors(form);
  for (const step of [1, 2]) {
    if (!stepIsValid(form, step)) { showStep(form, step); stepIsValid(form, step); return; }
  }
  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const id = form.dataset.id;
    const { record } = await api(`/tournaments${id ? '/' + id : ''}`, { method: 'POST', body: formValues(form) });
    applyTournament(record);
    closeDialogs();
    toast(id ? 'Torneo actualizado.' : 'Torneo creado. Siguiente paso: abrir inscripciones.');
    go(`torneos/${record.id}`);
  } catch (error) {
    if (!error.status) throw error;
    const fieldStep = name => +form.elements.namedItem(name)?.closest('[data-step]')?.dataset.step;
    const firstField = Object.keys(error.fields ?? {})[0];
    if (firstField && !Number.isNaN(fieldStep(firstField))) showStep(form, fieldStep(firstField));
    showServerErrors(form, error);
  } finally {
    button.disabled = false;
  }
});
