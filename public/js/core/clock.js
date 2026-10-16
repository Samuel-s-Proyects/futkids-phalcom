// "Now" and "today" follow the server clock (America/Guatemala), not the device clock,
// so date limits in forms match the rules the server applies.

let offsetMs = 0;
let timeZone = 'America/Guatemala';

/** @param {string} serverNow local server time as YYYY-MM-DDTHH:mm */
export function syncClock(serverNow, zone = timeZone) {
  timeZone = zone;
  if (!serverNow) return;
  const deviceLocal = Date.parse(localParts(new Date()).iso + ':00Z');
  const serverLocal = Date.parse(serverNow + ':00Z');
  if (!Number.isNaN(serverLocal)) offsetMs = serverLocal - deviceLocal;
}

function localParts(date) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(date).map(part => [part.type, part.value]));
  return { iso: `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}` };
}

/** Current server-local date and time as YYYY-MM-DDTHH:mm. */
export const nowLocal = () => localParts(new Date(Date.now() + offsetMs)).iso;

/** Current server-local date as YYYY-MM-DD. */
export const today = () => nowLocal().slice(0, 10);
