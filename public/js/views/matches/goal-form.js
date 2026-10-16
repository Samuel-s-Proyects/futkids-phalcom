// Quick entry of one goal while the match is being played.
import { esc } from '../../core/dom.js';
import { onChange, onSubmit } from '../../core/events.js';
import { fullName } from '../../core/format.js';
import { store, teamName } from '../../core/store.js';
import { periodLabel } from '../../domain/score.js';
import { button } from '../../ui/components.js';
import { dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { currentMatch, eligiblePlayers, matchChange } from './common.js';
import { openMatch } from './match-dialog.js';

/** Players listed for a goal counted for `teamId`: its own players, or the rival's on an own goal. */
export function scorerOptions(match, teamId, ownGoal) {
  const source = ownGoal ? (+teamId === +match.home_team_id ? match.away_team_id : match.home_team_id) : teamId;
  return eligiblePlayers(match, source).map(p => ({ value: p.id, label: `${fullName(p)}${p.shirt_number ? ` (#${p.shirt_number})` : ''}` }));
}

export const periodOptions = tournament => Array.from({ length: +tournament.extra_time_periods + 1 }, (_, period) => ({ value: period, label: periodLabel(period, tournament.extra_time_period_minutes) }));

const optionsHtml = (options, placeholder) => `<option value="">${esc(placeholder)}</option>` + options.map(o => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join('');

export function openGoalForm(match) {
  const t = store.tournament;
  openDialog(`${dialogHeader({ eyebrow: 'Partido en juego', title: 'Registrar gol', subtitle: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}` })}
    <form id="goal-form" novalidate data-match="${match.id}"><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'credited_team', label: '¿Para qué equipo cuenta el gol?', type: 'select', required: true, placeholder: 'Seleccione el equipo', change: 'goal-form', full: true, options: [match.home_team_id, match.away_team_id].map(id => ({ value: id, label: teamName(id) })) })}
        <label class="check field--full"><input type="checkbox" name="own_goal" value="1" data-change="goal-form"> Fue autogol (lo marcó un jugador del equipo rival)</label>
        ${field({ name: 'player_id', label: 'Jugador que anotó', type: 'select', required: true, placeholder: 'Primero elija el equipo', disabled: true, hint: 'Jugador que anotó el gol.' })}
        ${field({ name: 'minute', label: 'Minuto', type: 'number', required: true, min: 0, max: 150, hint: 'Minuto en que se anotó.' })}
        ${+t.extra_time_periods ? field({ name: 'period_number', label: 'Momento del partido', type: 'select', value: 0, required: true, options: periodOptions(t), full: true, hint: 'Parte del partido en que se anotó.' }) : ''}
      </div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${button('Guardar gol', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
}

onChange('goal-form', control => {
  const form = control.form;
  const match = currentMatch(form.dataset.match);
  const teamId = form.elements.namedItem('credited_team').value;
  const ownGoal = form.elements.namedItem('own_goal').checked;
  const players = form.elements.namedItem('player_id');
  players.disabled = !teamId;
  players.innerHTML = teamId ? optionsHtml(scorerOptions(match, teamId, ownGoal), ownGoal ? 'Jugador rival que hizo el autogol' : 'Seleccione el jugador') : optionsHtml([], 'Primero elija el equipo');
  form.querySelector(`label[for="${players.id}"]`).firstChild.textContent = ownGoal ? 'Jugador que hizo el autogol' : 'Jugador que anotó';
});

onSubmit('goal-form', async form => {
  const match = currentMatch(form.dataset.match);
  const saved = await submitForm(form, values => matchChange(match, '/goals', {
    body: { player_id: values.player_id, minute: values.minute, period_number: values.period_number ?? 0, own_goal: values.own_goal ? 1 : 0 },
  }));
  if (!saved) return;
  toast('Gol registrado.');
  openMatch(match.id);
});
