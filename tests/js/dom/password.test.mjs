import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until } from './harness.mjs';

const app = await boot({
  user: { id: 2, name: 'Árbitro', role: 'referee', must_change_password: 1 },
  hash: '#panel',
  routes: {
    'POST /api/account/password': body => (body.current_password === 'Temporal2026'
      ? { user: { id: 2, name: 'Árbitro', role: 'referee', must_change_password: 0 }, record: {} }
      : [422, { error: 'Revise los campos marcados del formulario.', fields: { current_password: 'La contraseña actual no es correcta.' } }]),
  },
});

test('una contraseña temporal obliga a crear una nueva al ingresar', async () => {
  const form = await until(() => app.$('#own-password-form'));
  assert.equal(form.dataset.forced, '1');
  assert.equal(app.$('#form-dialog [data-action="close-dialog"]'), null, 'no se puede cerrar sin cambiarla');
  assert.ok(app.$('#form-dialog [data-action="logout"]'));
  assert.match(app.text('#own-password-form'), /Clave para ingresar/);
});

test('las contraseñas nuevas deben coincidir', () => {
  const form = app.$('#own-password-form');
  form.elements.current_password.value = 'Temporal2026';
  form.elements.new_password.value = 'Definitiva2026';
  form.elements.confirm_password.value = 'Otra2026xxxx';
  form.requestSubmit();
  assert.equal(app.document.getElementById(`${form.elements.confirm_password.id}-error`).textContent, 'Las contraseñas no coinciden.');
});

test('el error del servidor aparece en la contraseña actual', async () => {
  const form = app.$('#own-password-form');
  form.elements.current_password.value = 'equivocada';
  form.elements.confirm_password.value = 'Definitiva2026';
  form.requestSubmit();
  await until(() => app.document.getElementById(`${form.elements.current_password.id}-error`).textContent);
  assert.equal(app.document.getElementById(`${form.elements.current_password.id}-error`).textContent, 'La contraseña actual no es correcta.');
});

test('al cambiarla se cierra el diálogo', async () => {
  const form = app.$('#own-password-form');
  form.elements.current_password.value = 'Temporal2026';
  form.requestSubmit();
  await until(() => !app.$('#form-dialog').open);
  assert.match(app.text('#toast'), /Contraseña actualizada/);
});

test('un árbitro no ve la sección Usuarios', () => {
  assert.equal(app.$('#navigation a[href="#usuarios"]'), null);
});
