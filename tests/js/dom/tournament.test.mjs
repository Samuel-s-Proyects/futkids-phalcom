import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, until, wait } from './harness.mjs';
import { knockoutDetail } from './fixtures.mjs';

const detail = knockoutDetail();
const app = await boot({
  user: { id: 1, name: 'Administrador', role: 'admin' },
  hash: '#torneos/1/cuadro',
  routes: {
    'GET /api/tournaments/1': () => detail,
    'POST /api/tournaments/1/matches/11/result': () => ({ record: detail }),
  },
});
await until(() => app.$('.bracket'));

test('el detalle muestra las etapas y marca la actual', () => {
  const steps = app.$$('.stepper__step');
  assert.equal(steps.length, 5);
  assert.equal(app.$('.stepper__step--current').textContent.replace(/\(.*\)/, '').trim(), 'Partidos');
  assert.match(app.text('.next-step'), /Programe cada partido/);
});

test('las pestañas son enlaces con dirección propia', () => {
  const tabs = app.$$('.tabs a');
  assert.deepEqual(tabs.map(tab => tab.getAttribute('href')), ['#torneos/1/resumen', '#torneos/1/equipos', '#torneos/1/cuadro', '#torneos/1/goleadores']);
  assert.equal(app.$('.tabs a[aria-current="page"]').textContent, 'Cuadro');
  assert.match(app.text('.page-header'), /Copa Apertura/);
});

test('el cuadro muestra rondas con nombre y partidos sin rival como "Por definir"', () => {
  assert.deepEqual(app.$$('.bracket__round-title').map(title => title.textContent), ['Semifinales', 'Final']);
  assert.match(app.text('.bracket__round:last-child'), /Por definir/);
});

test('el diálogo del partido en juego ofrece registrar tarjeta, gol y finalizar', async () => {
  app.click('.match-card[data-id="11"]');
  await until(() => app.$('#wide-dialog').open);
  const actions = app.$$('#wide-dialog .dialog__footer button').map(button => button.textContent.trim());
  assert.deepEqual(actions, ['Cerrar', 'Registrar tarjeta', 'Registrar gol', 'Finalizar y enviar resultado']);
  assert.match(app.text('#wide-dialog .scoreboard'), /0 – 0/);
});

test('el formulario de programación explica la cancha y limita las fechas', async () => {
  app.click('#wide-dialog [data-action="close-dialog"]');
  app.click('.match-card[data-id="12"]');
  await until(() => app.$('#wide-dialog [data-action="schedule-match"]'));
  app.click('#wide-dialog [data-action="schedule-match"]');
  const form = await until(() => app.$('#schedule-form'));
  assert.equal(form.elements.scheduled_at.min, '2026-10-16T09:00', 'no se puede elegir antes de ahora');
  assert.equal(form.elements.scheduled_at.max, '2026-12-31T23:59', 'ni después del torneo');
  const venueHint = app.document.getElementById(form.elements.venue.getAttribute('aria-describedby').split(' ')[0]).textContent;
  assert.match(venueHint, /Nombre del campo donde se juega/);
  assert.ok(app.$(`#${form.elements.venue.getAttribute('list')} option[value="Cancha 2 Las Palmas"]`), 'sugiere canchas ya usadas');
  assert.deepEqual([...form.elements.referee_user_id.options].map(option => option.textContent), ['Seleccione un árbitro', 'Árbitro'], 'muestra nombres, no correos');
});

test('la hoja de partido calcula el marcador con los goles', async () => {
  const match = detail.matches[0];
  app.click('[data-action="close-dialog"]');
  app.click('.match-card[data-id="11"]');
  await until(() => app.$('#wide-dialog [data-action="open-sheet"]'));
  app.click('#wide-dialog [data-action="open-sheet"]');
  const form = await until(() => app.$('#sheet-form'));
  assert.equal(app.text('#sheet-score'), '0 – 0');
  app.click('[data-action="sheet-add-goal"][data-side="home"]');
  app.click('[data-action="sheet-add-goal"][data-side="home"]');
  await wait(5);
  const rows = app.$$('.goal-row[data-side="home"]');
  assert.equal(rows.length, 2);
  app.change(rows[0].querySelector('select'), rows[0].querySelector('select').options[1].value);
  assert.equal(app.text('#sheet-score'), '2 – 0');
  assert.equal(app.text(`[data-goals="home"]`), '2 goles');

  // Own goal: the list switches to the rival's players.
  const own = rows[1].querySelector('input[type="checkbox"]');
  own.checked = true;
  own.dispatchEvent(new app.window.Event('change', { bubbles: true }));
  const names = [...rows[1].querySelector('select').options].slice(1).map(option => option.textContent);
  const rivalNames = app.state.data.players.filter(p => +p.team_id === +match.away_team_id).map(p => `${p.first_name} ${p.last_name}`);
  assert.ok(names.length > 0);
  assert.ok(names.every(name => rivalNames.some(rival => name.startsWith(rival))), 'en un autogol se elige a un jugador del rival');
  assert.match(app.text(`label[for="${rows[1].querySelector('select').id}"]`), /rival/);
  assert.equal(app.text('#sheet-score'), '2 – 0', 'el autogol en la columna local sigue contando para el local');
});

test('con empate aparece la tanda de penales y exige ganador', async () => {
  const form = app.$('#sheet-form');
  app.click(app.$$('.goal-row[data-side="home"] [data-action="sheet-remove-goal"]')[1]);
  app.click('[data-action="sheet-add-goal"][data-side="away"]');
  await wait(5);
  assert.equal(app.text('#sheet-score'), '1 – 1');
  const shootout = app.$('#sheet-shootout');
  assert.equal(shootout.hidden, false);
  app.change(form.elements.first_kicker_team_id, '1');
  assert.equal(app.$$('#sheet-kicks select').length, 10, 'cinco tiros por equipo');
  assert.match(app.text('#sheet-kicks label'), /Tiro 1 · Leones de Petapa/);
  const kicks = app.$$('#sheet-kicks select');
  for (const [index, scored] of [1, 0, 1, 0, 1, 0].entries()) app.change(app.$$('#sheet-kicks select')[index], String(scored));
  assert.match(app.text('#sheet-shootout-status'), /Gana Leones de Petapa 3–0 en penales/);
  assert.ok(kicks.length >= 6);
});

test('al enviar se mandan los goles y la tanda en el formato del servidor', async () => {
  const form = app.$('#sheet-form');
  for (const row of app.$$('.goal-row')) {
    const select = row.querySelector('select');
    if (!select.value) app.change(select, select.options[1].value);
    row.querySelector('input[inputmode="numeric"]').value = '10';
  }
  form.requestSubmit();
  await until(() => app.calls.some(call => call.path === '/api/tournaments/1/matches/11/result'));
  const { body } = app.calls.find(call => call.path === '/api/tournaments/1/matches/11/result');
  assert.equal(body.goal_events.length, 2);
  assert.deepEqual(Object.keys(body.goal_events[0]).sort(), ['minute', 'own_goal', 'period_number', 'player_id']);
  assert.equal(body.first_kicker_team_id, 1);
  assert.deepEqual(body.penalty_kicks.map(kick => kick.team_id), [1, 4, 1, 4, 1, 4]);
});
