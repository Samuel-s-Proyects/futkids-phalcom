// Date, venue and referee of a match.
import { nowLocal } from '../../core/clock.js';
import { onSubmit } from '../../core/events.js';
import { date, toInputDateTime } from '../../core/format.js';
import { store, teamName } from '../../core/store.js';
import { button } from '../../ui/components.js';
import { dialogHeader, openDialog } from '../../ui/dialog.js';
import { field, requiredLegend, submitForm } from '../../ui/form.js';
import { toast } from '../../ui/toast.js';
import { currentMatch, matchChange } from './common.js';
import { matchdayName, matchdayOf } from './league-actions.js';
import { openMatch } from './match-dialog.js';

/** Venues already used, offered as suggestions so the same field is always written the same way. */
function knownVenues() {
  const venues = new Map();
  for (const match of [...store.data.tournament_matches, ...(store.tournament?.matches ?? [])]) {
    if (match.venue) venues.set(match.venue.toLocaleLowerCase('es'), match.venue);
  }
  return [...venues.values()].sort((a, b) => a.localeCompare(b, 'es'));
}

export function openScheduleForm(match) {
  const t = store.tournament;
  // A league match is played inside its matchday; a knockout match anywhere inside the tournament.
  const matchday = t.type === 'league' ? matchdayOf(match) : null;
  const windowStart = matchday ? matchday.game_date : t.start_date;
  const windowEnd = matchday ? (matchday.end_date || matchday.game_date) : t.end_date;
  const start = `${windowStart}T00:00`;
  const now = nowLocal();
  const current = toInputDateTime(match.scheduled_at);
  // An existing date may stay as it is even if it already passed (the server allows keeping it).
  const earliest = now > start ? now : start;
  const min = current && current < earliest ? current : earliest;
  const max = `${windowEnd}T23:59`;
  const rule = matchday
    ? `Debe jugarse dentro de ${matchdayName(matchday)} y no antes de ahora.`
    : `No puede ser antes de ahora y debe estar dentro de las fechas del torneo.${+match.round_number > 1 ? ' Además, debe ser después de los partidos que definen a estos equipos.' : ''}`;
  openDialog(`${dialogHeader({ eyebrow: 'Programar partido', title: `${teamName(match.home_team_id)} vs. ${teamName(match.away_team_id)}`, subtitle: `El torneo se juega del ${date(t.start_date)} al ${date(t.end_date)}.` })}
    <form id="schedule-form" novalidate data-match="${match.id}"><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'scheduled_at', label: 'Fecha y hora', type: 'datetime-local', value: toInputDateTime(match.scheduled_at), required: true, min, max, full: true, hint: rule })}
        ${field({ name: 'venue', label: 'Cancha', value: match.venue ?? '', required: true, rule: 'title', maxlength: 150, full: true, placeholder: 'Ej.: Cancha 2 Las Palmas', suggestions: knownVenues(), hint: 'Nombre del campo donde se juega. Use letras, números, espacios, guion o punto (sin #). Elija una sugerencia si la cancha ya existe; mayúsculas y tildes no cuentan al revisar que la cancha esté libre.' })}
        ${field({ name: 'referee_user_id', label: 'Árbitro', type: 'select', value: match.referee_user_id ?? '', required: true, full: true, placeholder: 'Seleccione un árbitro', options: store.data.referees.map(r => ({ value: r.id, label: r.name })), hint: 'Dirigirá el partido y registrará goles y tarjetas. No puede tener otro partido a la misma hora.' })}
      </div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${button('Volver al partido', { action: 'back-to-match', data: { id: match.id } })}
      ${button('Guardar programación', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
}

onSubmit('schedule-form', async form => {
  const match = currentMatch(form.dataset.match);
  const saved = await submitForm(form, values => matchChange(match, '/schedule', { body: values }));
  if (!saved) return;
  toast('Partido programado.');
  openMatch(match.id);
});
