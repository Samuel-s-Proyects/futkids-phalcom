import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until } from './harness.mjs';
import { knockoutDetail } from './fixtures.mjs';

const app = await boot({
  user: { id: 2, name: 'Árbitro', role: 'referee' },
  hash: '#torneos/1/cuadro',
  routes: { 'GET /api/tournaments/1': () => knockoutDetail() },
});
await until(() => app.$('.bracket'));

test('el árbitro no ve acciones de administración', async () => {
  assert.equal(app.text('#account-role'), 'Árbitro');
  assert.equal(app.$('[data-action="edit-tournament"]'), null);
  await app.go('torneos');
  await until(() => app.text('main h1') === 'Torneos');
  assert.equal(app.$('[data-action="new-tournament"]'), null);
  await app.go('jugadores');
  await until(() => app.$('#records-region table'));
  assert.equal(app.$('[data-action="new-record"]'), null);
  assert.equal(app.$('[data-action="edit-record"]'), null);
});

test('en su partido puede registrar tarjetas, goles y el resultado', async () => {
  await app.go('torneos/1/cuadro');
  await until(() => app.$('.match-card[data-id="11"]'));
  app.click('.match-card[data-id="11"]');
  await until(() => app.$('#wide-dialog').open);
  const actions = app.$$('#wide-dialog .dialog__footer button').map(button => button.textContent.trim());
  assert.deepEqual(actions, ['Cerrar', 'Registrar tarjeta', 'Registrar gol', 'Finalizar y enviar resultado']);
});

test('no puede programar partidos', async () => {
  app.click('[data-action="close-dialog"]');
  app.click('.match-card[data-id="12"]');
  await until(() => app.$('#wide-dialog').open);
  assert.equal(app.$('#wide-dialog [data-action="schedule-match"]'), null);
  assert.match(app.text('#wide-dialog .alert'), /El administrador asignará/);
});

test('la tarjeta roja pide la fecha de suspensión', async () => {
  app.click('[data-action="close-dialog"]');
  app.click('.match-card[data-id="11"]');
  await until(() => app.$('#wide-dialog [data-action="new-incident"]'));
  app.click('#wide-dialog [data-action="new-incident"]');
  const form = await until(() => app.$('#incident-form'));
  assert.equal(app.$('#suspension-wrapper').hidden, true);
  app.change(form.elements.card_type, 'red');
  assert.equal(app.$('#suspension-wrapper').hidden, false);
  assert.equal(form.elements.suspension_date.required, true);
  assert.equal(form.elements.suspension_date.min, '2026-10-20', 'no antes del partido');
  assert.equal(form.elements.description.maxLength, 500);
});
