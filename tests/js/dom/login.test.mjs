import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until } from './harness.mjs';

const app = await boot();

test('sin sesión se muestra el acceso con cuentas de demostración', () => {
  assert.equal(app.$('#login').hidden, false);
  assert.equal(app.$('#app').hidden, true);
  assert.equal(app.$('#demo-accounts').hidden, false);
});

test('una contraseña incorrecta muestra el error del servidor', async () => {
  app.$('#login-form [name=email]').value = 'admin@cantera.local';
  app.$('#login-form [name=password]').value = 'mala';
  app.$('#login-form').requestSubmit();
  await until(() => app.text('#login-error'));
  assert.equal(app.text('#login-error'), 'Correo o contraseña incorrectos.');
});

test('mostrar contraseña cambia el tipo del campo', () => {
  const toggle = app.$('#show-password');
  toggle.checked = true;
  toggle.dispatchEvent(new app.window.Event('change', { bubbles: true }));
  assert.equal(app.$('#login-form [name=password]').type, 'text');
});

test('las cuentas de demostración completan el formulario e ingresan al panel', async () => {
  app.click('[data-action="prefill"][data-role="admin"]');
  app.$('#login-form').requestSubmit();
  await until(() => !app.$('#app').hidden && app.text('main h1') === 'Panel general');
  const login = app.calls.find(call => call.path === '/api/login');
  assert.equal(login.headers['X-CSRF-Token'], 'token', 'el ingreso envía el token CSRF');
  assert.equal(app.text('#account-role'), 'Administrador');
});

test('cerrar sesión vuelve al acceso', async () => {
  app.click('[data-action="logout"]');
  await until(() => !app.$('#login').hidden);
  assert.equal(app.$('#app').hidden, true);
});
