import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until } from './harness.mjs';

const accounts = [
  { id: 1, name: 'Administrador', email: 'admin@cantera.local', role: 'admin', active: 1, must_change_password: 0, last_login_at: '2026-10-16 08:00:00', pending_matches: [] },
  { id: 2, name: 'Árbitro', email: 'arbitro@cantera.local', role: 'referee', active: 1, must_change_password: 0, last_login_at: null, pending_matches: [{ id: 11 }] },
];

const app = await boot({
  user: { id: 1, name: 'Administrador', role: 'admin', must_change_password: 0 },
  hash: '#usuarios',
  routes: {
    'GET /api/users': () => ({ items: accounts }),
    'POST /api/users': body => [201, { record: { id: 3, ...body, active: 1, must_change_password: 1, pending_matches: [] } }],
    'POST /api/users/2/status': () => [409, { error: 'El árbitro tiene partidos pendientes (20/10/2026 10:00). Asigne otro árbitro antes.', fields: { active: 'Reasigne sus partidos pendientes primero.' } }],
  },
});
await until(() => app.text('main h1') === 'Usuarios');

test('el administrador ve la sección Usuarios en el menú', () => {
  assert.ok(app.$('#navigation a[href="#usuarios"]'));
  assert.match(app.text('#navigation'), /Administración/);
});

test('la lista muestra rol, estado y partidos pendientes, sin acciones sobre uno mismo', () => {
  const rows = app.$$('main tbody tr');
  assert.equal(rows.length, 2);
  assert.match(rows[1].textContent, /Árbitro.*Activa.*1 partido pendiente/s);
  assert.equal(rows[0].querySelector('[data-action="toggle-user"]'), null, 'no puede desactivarse a sí mismo');
  assert.ok(rows[1].querySelector('[data-action="toggle-user"]'));
});

test('crear usuario sugiere una contraseña temporal válida', async () => {
  app.click('[data-action="new-user"]');
  const form = await until(() => app.$('#user-form'));
  const password = form.elements.password.value;
  assert.ok(password.length >= 10 && /[a-z]/i.test(password) && /[0-9]/.test(password));
  assert.match(app.text('#user-form'), /deberá cambiarla al ingresar/);
  form.elements.name.value = 'Ana Méndez';
  form.elements.email.value = 'ana@cantera.local';
  form.requestSubmit();
  await until(() => app.calls.some(call => call.method === 'POST' && call.path === '/api/users'));
  const { body } = app.calls.find(call => call.method === 'POST' && call.path === '/api/users');
  assert.deepEqual(Object.keys(body).sort(), ['email', 'name', 'password', 'role']);
});

test('el correo se valida antes de enviar', async () => {
  app.click('[data-action="new-user"]');
  const form = await until(() => app.$('#user-form'));
  form.elements.name.value = 'Ana';
  form.elements.email.value = 'no-es-correo';
  form.requestSubmit();
  assert.match(app.document.getElementById(`${form.elements.email.id}-error`).textContent, /correo válido/);
  app.click('[data-action="close-dialog"]');
});

test('desactivar un árbitro con partidos muestra el motivo del rechazo', async () => {
  app.click('main tbody tr:nth-child(2) [data-action="toggle-user"]');
  await until(() => app.$('#confirm-dialog').open);
  app.click('[data-confirm="yes"]');
  await until(() => /partidos pendientes/.test(app.text('#toast')));
});
