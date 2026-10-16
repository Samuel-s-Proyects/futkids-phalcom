// Account management: a new referee must replace the temporary password; a deactivated account loses access.
import { expect, test } from '@playwright/test';
import { dialogField, login } from './helpers.mjs';

test.describe.configure({ mode: 'serial' });

const email = `arbitro.${Date.now()}@cantera.local`;
let temporaryPassword = '';

test('el administrador crea un árbitro con contraseña temporal', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/#usuarios');
  await page.getByRole('button', { name: 'Nuevo usuario' }).click();
  await dialogField(page, 'Nombre completo').fill('Ana Méndez');
  await dialogField(page, 'Correo electrónico').fill(email);
  temporaryPassword = await dialogField(page, 'Contraseña temporal').inputValue();
  expect(temporaryPassword).toMatch(/^(?=.*[a-zA-Z])(?=.*\d).{10,}$/);
  await page.locator('dialog[open]').getByRole('button', { name: 'Crear usuario' }).click();
  await expect(page.locator('main tbody')).toContainText(email);
  await expect(page.locator('main tbody tr', { hasText: email })).toContainText('Contraseña temporal');
});

test('al primer ingreso debe crear su propia contraseña', async ({ browser }) => {
  const page = await (await browser.newContext()).newPage();
  await page.goto('/');
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill(temporaryPassword);
  await page.getByRole('button', { name: 'Ingresar' }).click();
  const dialog = page.locator('dialog[open]');
  await expect(dialog.getByRole('heading', { name: 'Cree su contraseña' })).toBeVisible();
  await dialog.getByLabel('Contraseña temporal').fill(temporaryPassword);
  await dialog.getByLabel(/^Nueva contraseña/).fill('Definitiva2026');
  await dialog.getByLabel('Repita la nueva contraseña').fill('Definitiva2026');
  await dialog.getByRole('button', { name: 'Guardar contraseña' }).click();
  await expect(page.locator('dialog[open]')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Panel general', level: 1 })).toBeVisible();
  await page.context().close();
});

test('al desactivar la cuenta su sesión termina', async ({ browser }) => {
  const referee = await (await browser.newContext()).newPage();
  await login(referee, 'referee', { email, password: 'Definitiva2026' });

  const admin = await (await browser.newContext()).newPage();
  await login(admin, 'admin');
  await admin.goto('/#usuarios');
  await admin.locator('main tbody tr', { hasText: email }).getByRole('button', { name: 'Desactivar' }).click();
  await admin.locator('dialog[open]').getByRole('button', { name: 'Desactivar' }).click();
  await expect(admin.locator('main tbody tr', { hasText: email })).toContainText('Inactiva');

  await referee.goto('/#jugadores');
  await referee.reload();
  await expect(referee.getByRole('heading', { name: 'Iniciar sesión' })).toBeVisible();
  await referee.context().close();
  await admin.context().close();
});
