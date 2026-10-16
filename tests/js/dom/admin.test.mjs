import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until, wait } from './harness.mjs';

const app = await boot({
  user: { id: 1, name: 'Administrador', role: 'admin' },
  hash: '#panel',
  routes: {
    'POST /api/teams': () => [422, { error: 'Revise los campos marcados del formulario.', fields: { name: 'Ya existe un equipo con ese nombre.' } }],
  },
});
await until(() => app.text('main h1') === 'Panel general');

test('el menú lista las secciones y marca la actual', () => {
  const links = app.$$('#navigation a').map(link => link.textContent.trim());
  assert.deepEqual(links, ['Panel general', 'Torneos', 'Equipos', 'Jugadores', 'Incidencias', 'Reportes', 'Jornadas', 'Goles', 'Usuarios']);
  assert.equal(app.$('#navigation a[aria-current="page"]').textContent.trim(), 'Panel general');
});

test('cada sección se muestra sin errores', async () => {
  const titles = { torneos: 'Torneos', equipos: 'Equipos', jugadores: 'Jugadores', incidencias: 'Incidencias', 'reportes/goleadores': 'Reportes del torneo', jornadas: 'Jornadas (histórico)', goles: 'Goles (histórico)' };
  for (const [hash, title] of Object.entries(titles)) {
    await app.go(hash);
    await until(() => app.text('main h1') === title);
  }
});

test('la búsqueda de jugadores filtra en vivo y pagina de 10 en 10', async () => {
  await app.go('jugadores');
  await until(() => app.$('#records-region table'));
  assert.equal(app.$$('#records-region tbody tr').length, 10);
  assert.equal(app.text('#records-count'), '20 resultados');
  const search = app.$('[data-change="records-filter"][name="q"]');
  search.value = 'mateo';
  search.dispatchEvent(new app.window.Event('input', { bubbles: true }));
  await wait(10);
  assert.equal(app.text('#records-count'), '1 resultado');
});

test('los campos tienen etiqueta, hint y error enlazados', async () => {
  app.click('[data-action="new-record"][data-entity="players"]');
  const name = await until(() => app.$('#record-form [name="first_name"]'));
  const [hintId, errorId] = name.getAttribute('aria-describedby').split(' ');
  assert.match(app.document.getElementById(hintId).textContent, /Nombre o nombres/);
  assert.ok(app.document.getElementById(errorId));
  assert.equal(app.document.querySelector(`label[for="${name.id}"]`).textContent.replace('*', '').trim(), 'Nombres');
  const shirt = app.$('#record-form [name="shirt_number"]');
  assert.equal(shirt.getAttribute('inputmode'), 'numeric');
  assert.match(app.$('#record-form').textContent, /categoría por edad/);
});

test('la validación en vivo rechaza caracteres especiales y números fuera de rango', async () => {
  const form = app.$('#record-form');
  form.elements.first_name.value = 'Juan<1>';
  form.elements.shirt_number.value = '120';
  form.requestSubmit();
  await wait(10);
  const errors = Object.fromEntries(['first_name', 'last_name', 'shirt_number', 'team_id'].map(name => [name, app.document.getElementById(`${form.elements[name].id}-error`).textContent]));
  assert.match(errors.first_name, /Use solo letras/);
  assert.equal(errors.last_name, 'Este campo es obligatorio.');
  assert.match(errors.shirt_number, /entre 1 y 99/);
  assert.equal(errors.team_id, 'Seleccione una opción.');
  assert.equal(form.elements.first_name.getAttribute('aria-invalid'), 'true');
  assert.equal(app.calls.filter(call => call.method === 'POST' && call.path === '/api/players').length, 0, 'no se envía con errores');
});

test('los campos numéricos no aceptan letras ni signos', () => {
  const shirt = app.$('#record-form [name="shirt_number"]');
  const event = new app.window.InputEvent('beforeinput', { data: 'e', cancelable: true, bubbles: true });
  shirt.dispatchEvent(event);
  assert.equal(event.defaultPrevented, true);
  const digit = new app.window.InputEvent('beforeinput', { data: '7', cancelable: true, bubbles: true });
  shirt.dispatchEvent(digit);
  assert.equal(digit.defaultPrevented, false);
});

test('los errores del servidor aparecen junto a su campo', async () => {
  app.click('[data-action="close-dialog"]');
  await app.go('equipos');
  await until(() => app.$('[data-action="new-record"][data-entity="teams"]'));
  app.click('[data-action="new-record"][data-entity="teams"]');
  const form = await until(() => app.$('#record-form[data-entity="teams"]'));
  form.elements.name.value = 'Leones de Petapa';
  form.requestSubmit();
  await until(() => app.text('#record-form .form-error'));
  assert.equal(app.text('#record-form .form-error'), 'Revise los campos marcados del formulario.');
  assert.equal(app.document.getElementById(`${form.elements.name.id}-error`).textContent, 'Ya existe un equipo con ese nombre.');
  assert.equal(app.document.activeElement, form.elements.name, 'el foco va al primer campo con error');
});

test('el modo oscuro se puede elegir y se recuerda', () => {
  app.click('[data-action="toggle-theme"]');
  assert.equal(app.document.documentElement.dataset.theme, 'dark');
  assert.equal(app.window.localStorage.getItem('futkids-theme'), 'dark');
  assert.equal(app.$('#theme-toggle').getAttribute('aria-label'), 'Cambiar a modo claro');
});

test('el menú móvil se abre con fondo y se cierra con Escape', () => {
  app.click('[data-action="toggle-menu"]');
  assert.ok(app.$('#sidebar').classList.contains('is-open'));
  assert.ok(app.$('#sidebar-backdrop').classList.contains('is-visible'));
  assert.equal(app.$('#menu-icon').getAttribute('aria-expanded'), 'true');
  app.document.dispatchEvent(new app.window.KeyboardEvent('keydown', { key: 'Escape' }));
  assert.ok(!app.$('#sidebar').classList.contains('is-open'));
});
