// A points tournament: calendar generated from the interface, a draw in the table, a postponed match
// moved to an extraordinary matchday.
import { expect, test } from '@playwright/test';
import { api, dialogField, login, uniqueName } from './helpers.mjs';

test.describe.configure({ mode: 'serial' });

let id = 0;

test.beforeEach(async ({ page }) => login(page, 'admin'));

test('preparar la liga y generar el calendario desde la vista previa', async ({ page }) => {
  const created = await api(page, '/tournaments', { method: 'POST', body: { name: uniqueName('Liga Sub 12'), type: 'league', start_date: '2026-10-16', end_date: '2027-03-31', league_rounds: 1, points_win: 3, points_draw: 1, points_loss: 0 } });
  expect(created.status).toBe(201);
  id = created.data.record.id;
  await api(page, `/tournaments/${id}/registration`, { method: 'POST', body: {} });
  for (const team of [1, 2, 3]) await api(page, `/tournaments/${id}/participants`, { method: 'POST', body: { team_id: team } });
  await api(page, `/tournaments/${id}/registration/close`, { method: 'POST', body: { draw_method: 'manual' } });

  await page.goto(`/#torneos/${id}`);
  await expect(page.getByRole('link', { name: 'Calendario' })).toBeVisible();
  await page.getByRole('button', { name: 'Generar calendario' }).click();
  const dialog = page.locator('dialog[open]');
  await expect(dialog).toContainText('3 jornadas · solo ida');
  await dialogField(page, 'Fecha de la primera jornada').fill('2026-10-17');
  await dialog.getByRole('button', { name: 'Ver propuesta' }).click();
  await expect(dialog.getByText('Descansa:')).toHaveCount(3);
  await dialog.getByLabel('Jornada 3').fill('2026-11-07');
  await dialog.getByRole('button', { name: 'Generar calendario' }).click();
  await expect(page.locator('main details')).toHaveCount(3);
  await expect(page.locator('main details').first()).toContainText('Descansa:');
});

test('un empate suma un punto a cada equipo en la tabla', async ({ page }) => {
  const detail = (await api(page, `/tournaments/${id}`)).data;
  const match = detail.matches.find(m => +m.matchday_id === +detail.matchdays[0].id);
  const base = `/tournaments/${id}/matches/${match.id}`;
  expect((await api(page, base + '/schedule', { method: 'POST', body: { scheduled_at: '2026-10-17T10:00', venue: 'Cancha Liga', referee_user_id: 2 } })).status).toBe(200);
  await api(page, base + '/start', { method: 'POST', body: {} });
  const players = (await api(page, '/state')).data.players;
  const scorer = teamId => players.find(p => +p.team_id === +teamId).id;
  const result = await api(page, base + '/result', { method: 'POST', body: { goal_events: [{ player_id: scorer(match.home_team_id), minute: 10 }, { player_id: scorer(match.away_team_id), minute: 30 }] } });
  expect(result.status).toBe(200);
  await api(page, base + '/confirm', { method: 'POST', body: {} });

  await page.goto(`/#torneos/${id}/tabla`);
  const table = page.getByRole('table', { name: 'Tabla de posiciones' });
  await expect(table.locator('tbody tr')).toHaveCount(3);
  await expect(page.getByText('Desempate: 1) puntos')).toBeVisible();
  const pointsColumn = await table.locator('tbody tr td:nth-child(10)').allTextContents();
  expect(pointsColumn.map(Number).sort()).toEqual([0, 1, 1]);
});

test('aplazar un partido, crear una jornada extraordinaria y moverlo allí', async ({ page }) => {
  await page.goto(`/#torneos/${id}/calendario`);
  const second = page.locator('main details').nth(1);
  await second.locator('summary').click();
  await second.getByRole('button', { name: 'Abrir' }).first().click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Aplazar' }).click();
  await dialogField(page, 'Motivo').fill('Cancha inundada por la lluvia.');
  await page.getByRole('button', { name: 'Aplazar partido' }).click();
  await expect(page.locator('dialog[open]')).toContainText('Partido aplazado');
  await page.locator('dialog[open]').getByRole('button', { name: 'Cerrar', exact: true }).first().click();
  await expect(page.getByRole('heading', { name: 'Pendientes de reprogramar' })).toBeVisible();

  await page.getByRole('button', { name: 'Agregar jornada extraordinaria' }).click();
  await dialogField(page, 'Primer día').fill('2026-11-21');
  await page.locator('dialog[open]').getByRole('button', { name: 'Guardar' }).click();
  await expect(page.locator('main details').last()).toContainText('Extraordinaria');

  await page.locator('section.card', { has: page.getByRole('heading', { name: 'Pendientes de reprogramar' }) }).getByRole('button', { name: 'Abrir' }).click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Mover de jornada' }).click();
  await dialogField(page, 'Nueva jornada').selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Mover partido' }).click();
  await expect(page.locator('dialog[open]')).toContainText('Jornada extraordinaria');
  await expect(page.locator('dialog[open]')).toContainText('Por programar');
});
