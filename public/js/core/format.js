// Spanish (Guatemala) display formats.

const dateFormat = new Intl.DateTimeFormat('es-GT', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
const longDateFormat = new Intl.DateTimeFormat('es-GT', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
const timeFormat = new Intl.DateTimeFormat('es-GT', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' });

const asUtc = value => new Date(String(value).replace(' ', 'T').slice(0, 16) + (String(value).length > 10 ? ':00Z' : 'T12:00:00Z'));

/** 2026-10-16 → "16 oct 2026" */
export const date = value => (value ? dateFormat.format(asUtc(String(value).slice(0, 10))) : '—');

/** 2026-10-16 → "viernes, 16 de octubre" */
export const longDate = value => (value ? longDateFormat.format(asUtc(String(value).slice(0, 10))) : '—');

/** 2026-10-16 14:30:00 → "16 oct 2026, 14:30" */
export const dateTime = value => (value ? `${date(value)}, ${timeFormat.format(asUtc(value))}` : '—');

/** 2026-10-16 14:30:00 → "2026-10-16T14:30" (for datetime-local inputs) */
export const toInputDateTime = value => (value ? String(value).replace(' ', 'T').slice(0, 16) : '');

/** plural(3, 'equipo') → "3 equipos" */
export const plural = (count, singular, pluralForm = singular + 's') => `${count} ${count === 1 ? singular : pluralForm}`;

export const fullName = player => (player ? `${player.first_name} ${player.last_name}` : 'Jugador');
