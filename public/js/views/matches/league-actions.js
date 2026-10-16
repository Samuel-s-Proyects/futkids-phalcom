// League matches that cannot be played on their date: postpone them and move them to another matchday.
import { today } from '../../core/clock.js';
import { onSubmit } from '../../core/events.js';
import { date } from '../../core/format.js';
import { store, teamName } from '../../core/store.js';
import { alertBox, button } from '../../ui/components.js';
import { dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { currentMatch, matchChange } from './common.js';
import { openMatch } from './match-dialog.js';

export const matchdayOf = match => store.tournament?.matchdays?.find(m => +m.id === +match.matchday_id);

export const matchdayName = matchday => (matchday
  ? `${matchday.kind === 'extraordinary' ? (matchday.label || 'Jornada extraordinaria') : `Jornada ${matchday.number}`} (${date(matchday.game_date)}${matchday.end_date && matchday.end_date !== matchday.game_date ? ` – ${date(matchday.end_date)}` : ''})`
  : 'Sin jornada');

/** Matchdays where neither team already plays and that have not finished yet. */
function availableMatchdays(match) {
  const t = store.tournament;
  const teams = [+match.home_team_id, +match.away_team_id];
  return t.matchdays.filter(day => +day.id !== +match.matchday_id
    && (day.end_date || day.game_date) >= today()
    && !t.matches.some(other => +other.id !== +match.id && +other.matchday_id === +day.id && other.workflow_status !== 'cancelled'
      && (teams.includes(+other.home_team_id) || teams.includes(+other.away_team_id))));
}

export function openPostponeForm(match) {
  openDialog(`${dialogHeader({ eyebrow: 'Aplazar partido', title: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}`, subtitle: matchdayName(matchdayOf(match)) })}
    <form id="postpone-form" novalidate data-match="${match.id}"><div class="dialog__body">
      ${alertBox('info', 'Qué ocurre al aplazar', 'Se quitan la fecha, la cancha y el árbitro. El partido queda en «Pendientes de reprogramar» hasta que lo mueva a otra jornada o lo programe de nuevo.')}
      ${requiredLegend()}
      ${field({ name: 'reason', label: 'Motivo', type: 'textarea', required: true, rule: 'description', maxlength: 255, placeholder: 'Ej.: Cancha inundada por la lluvia.', hint: 'Por qué no se juega; queda en el historial del partido.' })}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${button('Aplazar partido', { type: 'submit', tone: 'danger', iconName: 'clock' })}
    </div></form>`);
}

export function openMoveForm(match) {
  const options = availableMatchdays(match);
  openDialog(`${dialogHeader({ eyebrow: 'Mover de jornada', title: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}`, subtitle: `Ahora: ${matchdayName(matchdayOf(match))}` })}
    <form id="move-form" novalidate data-match="${match.id}"><div class="dialog__body">
      ${options.length
        ? `${requiredLegend()}${field({ name: 'matchday_id', label: 'Nueva jornada', type: 'select', required: true, placeholder: 'Seleccione la jornada', options: options.map(day => ({ value: day.id, label: matchdayName(day) })), hint: 'Jornada en la que se jugará el partido.' })}`
        : alertBox('warning', 'No hay jornadas disponibles', 'Cree una jornada extraordinaria en la pestaña Calendario y vuelva a intentarlo.')}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${options.length ? button('Mover partido', { type: 'submit', tone: 'primary', iconName: 'arrow' }) : ''}
    </div></form>`);
}

onSubmit('postpone-form', async form => {
  const match = currentMatch(form.dataset.match);
  const saved = await submitForm(form, values => matchChange(match, '/postpone', { body: values }));
  if (!saved) return;
  toast('Partido aplazado. Muévalo a otra jornada cuando tenga nueva fecha.');
  openMatch(match.id);
});

onSubmit('move-form', async form => {
  const match = currentMatch(form.dataset.match);
  const saved = await submitForm(form, values => matchChange(match, '/matchday', { body: values }));
  if (!saved) return;
  toast('Partido movido. Ahora prográmelo dentro de su nueva jornada.');
  openMatch(match.id);
});
