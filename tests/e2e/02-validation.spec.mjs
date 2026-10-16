import { expect, test } from '@playwright/test';
import { api, dialogField, fieldError, login } from './helpers.mjs';

test.beforeEach(async ({ page }) => login(page, 'admin'));

test('el formulario de equipo rechaza caracteres especiales antes de enviar', async ({ page }) => {
  await page.goto('/#equipos');
  await page.getByRole('button', { name: 'Nuevo equipo' }).click();
  const name = dialogField(page, 'Nombre del equipo');
  await expect(page.locator('dialog[open]')).toContainText('Sin símbolos como # @ / & %');
  await name.fill('Equipo #1 <b>');
  await page.locator('dialog[open]').getByRole('button', { name: 'Guardar' }).click();
  await expect(await fieldError(name)).toContainText('Use letras y números');
  await expect(name).toHaveAttribute('aria-invalid', 'true');
});

test('el servidor aplica las mismas reglas aunque se salte el navegador', async ({ page }) => {
  const result = await api(page, '/teams', { method: 'POST', body: { name: 'Equipo #1 <b>', active: 1 } });
  expect(result.status).toBe(422);
  expect(result.data.fields.name).toContain('Use letras y números');
  const future = await api(page, '/incidents', { method: 'POST', body: { player_id: 1, description: 'Prueba', card_type: 'yellow', incident_date: '2026-10-17' } });
  expect(future.status).toBe(422);
  expect(future.data.fields.incident_date).toBeTruthy();
});

test('los campos numéricos solo aceptan dígitos', async ({ page }) => {
  await page.goto('/#jugadores');
  await page.getByRole('button', { name: 'Nuevo jugador' }).click();
  const shirt = dialogField(page, 'Número de camiseta');
  await shirt.pressSequentially('1e+2a');
  await expect(shirt).toHaveValue('12');
  await shirt.fill('120');
  await shirt.blur();
  await expect(await fieldError(shirt)).toHaveText('Ingrese un número entre 1 y 99.');
});

test('un torneo no puede empezar antes de hoy', async ({ page }) => {
  await page.goto('/#torneos');
  await page.getByRole('button', { name: 'Nuevo torneo' }).click();
  await page.getByRole('button', { name: 'Siguiente' }).click();
  const start = dialogField(page, 'Fecha de inicio');
  await expect(start).toHaveAttribute('min', '2026-10-16');
  await start.fill('2026-10-15');
  await page.getByRole('button', { name: 'Siguiente' }).click();
  await expect(await fieldError(start)).toContainText('no puede ser anterior al 16/10/2026');
});
