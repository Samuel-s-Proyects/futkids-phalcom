import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until, wait } from './harness.mjs';
import { leagueDetail } from './fixtures.mjs';

const detail = leagueDetail();
const app = await boot({
  user: { id: 1, name: 'Administrador', role: 'admin', must_change_password: 0 },
  hash: '#torneos/2/calendario',
  routes: {
    'GET /api/tournaments/2': () => detail,
    'POST /api/tournaments/2/matches/32/postpone': () => ({ record: detail }),
    'POST /api/tournaments/2/matches/33/matchday': () => ({ record: detail }),
  },
});
await until(() => app.$('details'));

test('la liga tiene pestañas de calendario y tabla en lugar de cuadro', () => {
  assert.deepEqual(app.$$('.tabs a').map(tab => tab.textContent), ['Resumen', 'Equipos', 'Calendario', 'Tabla', 'Goleadores']);
  assert.match(app.text('.stepper'), /Calendario de jornadas/);
});

test('el calendario agrupa por jornada, indica quién descansa y los aplazados', () => {
  const days = app.$$('main details');
  assert.equal(days.length, 4);
  assert.match(days[0].textContent, /Jornada 1/);
  assert.match(days[0].textContent, /Descansa: Guerreros del Sur/);
  assert.match(days[3].textContent, /Reposición.*Extraordinaria/s);
  assert.match(app.text('main'), /Pendientes de reprogramar/);
  assert.ok(app.$('[data-action="new-matchday"]'));
  assert.ok(app.$('[data-action="extend-end-date"]'));
  assert.ok(days[3].querySelector('[data-action="delete-matchday"]'), 'una jornada extraordinaria vacía se puede eliminar');
  assert.equal(days[0].querySelector('[data-action="delete-matchday"]'), null);
});

test('la tabla muestra posiciones, líder resaltado y criterios de desempate', async () => {
  await app.go('torneos/2/tabla');
  await until(() => app.$('main table'));
  const rows = app.$$('main tbody tr');
  assert.equal(rows.length, 3);
  assert.ok(rows[0].classList.contains('is-highlighted'));
  assert.match(rows[0].textContent, /Leones de Petapa/);
  assert.match(app.text('main'), /Desempate: 1\) puntos, 2\) diferencia de goles/);
  assert.match(app.text('main'), /Ganar 3 · Empatar 1 · Perder 0/);
});

test('un partido sin programar se puede aplazar o mover', async () => {
  await app.go('torneos/2/calendario');
  await until(() => app.$('[data-action="open-match"][data-id="32"]'));
  app.click('[data-action="open-match"][data-id="32"]');
  await until(() => app.$('#wide-dialog').open);
  const actions = app.$$('#wide-dialog .dialog__footer button').map(button => button.textContent.trim());
  assert.deepEqual(actions, ['Cerrar', 'Aplazar', 'Mover de jornada', 'Programar partido']);
  assert.match(app.text('#wide-dialog .dialog__eyebrow'), /Jornada 2/);
});

test('programar limita la fecha a la jornada', async () => {
  app.click('#wide-dialog [data-action="schedule-match"]');
  const form = await until(() => app.$('#schedule-form'));
  assert.equal(form.elements.scheduled_at.min, '2026-10-24T00:00');
  assert.equal(form.elements.scheduled_at.max, '2026-10-24T23:59');
  assert.match(app.text('#schedule-form'), /Día y hora en que se juega el partido/);
});

test('aplazar pide un motivo sin caracteres especiales y lo envía', async () => {
  app.click('[data-action="back-to-match"]');
  await until(() => app.$('#wide-dialog [data-action="postpone-match"]'));
  app.click('#wide-dialog [data-action="postpone-match"]');
  const form = await until(() => app.$('#postpone-form'));
  form.elements.reason.value = 'Lluvia <fuerte>';
  form.requestSubmit();
  await wait(5);
  assert.match(app.document.getElementById(`${form.elements.reason.id}-error`).textContent, /signos de puntuación/);
  form.elements.reason.value = 'Cancha inundada por la lluvia.';
  form.requestSubmit();
  await until(() => app.calls.some(call => call.path === '/api/tournaments/2/matches/32/postpone'));
  assert.deepEqual(app.calls.find(call => call.path === '/api/tournaments/2/matches/32/postpone').body, { reason: 'Cancha inundada por la lluvia.' });
});

test('mover ofrece solo jornadas futuras donde ningún equipo juega', async () => {
  app.click('[data-action="close-dialog"]');
  app.click('[data-action="open-match"][data-id="33"]');
  await until(() => app.$('#wide-dialog [data-action="move-match"]'));
  assert.match(app.text('#wide-dialog .alert'), /Partido aplazado.*Cancha inundada/s);
  app.click('#wide-dialog [data-action="move-match"]');
  const form = await until(() => app.$('#move-form'));
  const options = [...form.elements.matchday_id.options].slice(1).map(option => option.textContent);
  assert.deepEqual(options, ['Reposición (07 nov 2026)']);
  app.change(form.elements.matchday_id, '24');
  form.requestSubmit();
  await until(() => app.calls.some(call => call.path === '/api/tournaments/2/matches/33/matchday'));
  assert.deepEqual(app.calls.find(call => call.path === '/api/tournaments/2/matches/33/matchday').body, { matchday_id: '24' });
});

test('el asistente permite elegir liga con vueltas y puntos', async () => {
  app.click('[data-action="close-dialog"]');
  await app.go('torneos');
  await until(() => app.$('[data-action="new-tournament"]'));
  app.click('[data-action="new-tournament"]');
  const form = await until(() => app.$('#tournament-form'));
  const league = form.querySelector('input[name="type"][value="league"]');
  assert.equal(league.disabled, false);
  league.checked = true;
  league.dispatchEvent(new app.window.Event('change', { bubbles: true }));
  assert.equal(form.querySelector('[data-rules="league"]').hidden, false);
  assert.equal(form.querySelector('[data-rules="knockout"]').hidden, true);
  assert.equal(form.elements.penalty_attempts_per_team.disabled, true, 'las reglas de eliminación no se envían');
  assert.equal(form.elements.points_win.disabled, false);
  assert.match(app.text('[data-rules="league"]'), /Veces que cada equipo se enfrenta/);
});
