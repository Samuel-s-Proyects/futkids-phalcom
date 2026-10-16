// Yellow or red card shown during the match (assigned referee only).
import { onChange, onSubmit } from '../../core/events.js';
import { date, fullName } from '../../core/format.js';
import { playersOf, store, teamName } from '../../core/store.js';
import { button } from '../../ui/components.js';
import { dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { currentMatch, matchChange, matchDate, matchIncidents } from './common.js';
import { openMatch } from './match-dialog.js';

export function openIncidentForm(match, incidentId = 0) {
  const incident = matchIncidents(match).find(i => +i.id === +incidentId) ?? {};
  const red = incident.card_type === 'red';
  const players = [match.home_team_id, match.away_team_id].flatMap(teamId => playersOf(teamId).map(p => ({ value: p.id, label: `${fullName(p)} · ${teamName(teamId)}` })));
  openDialog(`${dialogHeader({ eyebrow: 'Disciplina', title: incidentId ? 'Editar tarjeta' : 'Registrar tarjeta', subtitle: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)} · ${date(matchDate(match))}` })}
    <form id="incident-form" novalidate data-match="${match.id}" data-incident="${incidentId}"><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'player_id', label: 'Jugador', type: 'select', value: incident.player_id ?? '', required: true, placeholder: 'Seleccione el jugador', options: players, full: true })}
        ${field({ name: 'minute', label: 'Minuto', type: 'number', value: incident.minute ?? '', required: true, min: 0, max: 150, hint: 'De 0 a 150.' })}
        ${field({ name: 'card_type', label: 'Tarjeta', type: 'select', value: incident.card_type ?? 'yellow', required: true, change: 'card-type', options: [{ value: 'yellow', label: 'Amarilla' }, { value: 'red', label: 'Roja (expulsión)' }] })}
        <div class="field--full" id="suspension-wrapper" ${red ? '' : 'hidden'}>
          ${field({ name: 'suspension_date', label: 'Fecha de suspensión', type: 'date', value: incident.suspension_date ?? '', required: red, min: matchDate(match), max: store.tournament?.end_date ?? '', hint: 'Día en que el jugador no podrá jugar. Igual o posterior a la fecha de este partido.' })}
        </div>
        ${field({ name: 'description', label: 'Descripción', type: 'textarea', value: incident.description ?? '', required: true, rule: 'description', maxlength: 500, full: true, placeholder: 'Ej.: Falta por detrás en la mitad de la cancha.', hint: 'Breve y clara. Se permiten signos de puntuación comunes; no use < > { } [ ] @ # $ %.' })}
      </div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${button('Guardar tarjeta', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
}

onChange('card-type', select => {
  const red = select.value === 'red';
  const wrapper = select.form.querySelector('#suspension-wrapper');
  const input = select.form.elements.namedItem('suspension_date');
  wrapper.hidden = !red;
  input.required = red;
  if (!red) input.value = '';
});

onSubmit('incident-form', async form => {
  const match = currentMatch(form.dataset.match);
  const id = +form.dataset.incident;
  const saved = await submitForm(form, values => matchChange(match, `/incidents${id ? '/' + id : ''}`, { body: values }));
  if (!saved) return;
  toast(id ? 'Tarjeta actualizada.' : 'Tarjeta registrada.');
  openMatch(match.id);
});
