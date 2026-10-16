import { expect, test } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.mjs';

test('una contraseña incorrecta muestra un error sin revelar si la cuenta existe', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Correo electrónico').fill('nadie@cantera.local');
  await page.getByLabel('Contraseña', { exact: true }).fill('incorrecta');
  await page.getByRole('button', { name: 'Ingresar' }).click();
  await expect(page.getByRole('alert')).toHaveText('Correo o contraseña incorrectos.');
});

test('el administrador ingresa, ve todas las secciones y cierra sesión', async ({ page }) => {
  await login(page, 'admin');
  const menu = page.getByRole('navigation', { name: 'Secciones' });
  for (const section of ['Panel general', 'Torneos', 'Equipos', 'Jugadores', 'Incidencias', 'Reportes', 'Jornadas', 'Goles', 'Usuarios']) {
    await expect(menu.getByRole('link', { name: section })).toBeVisible();
  }
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await expect(page.getByRole('heading', { name: 'Iniciar sesión' })).toBeVisible();
});

test('el árbitro no ve la administración de usuarios ni puede crear registros', async ({ page }) => {
  await login(page, 'referee', ACCOUNTS.referee);
  await expect(page.getByRole('navigation', { name: 'Secciones' }).getByRole('link', { name: 'Usuarios' })).toHaveCount(0);
  await page.goto('/#jugadores');
  await expect(page.getByRole('heading', { name: 'Jugadores', level: 1 })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Nuevo jugador' })).toHaveCount(0);
});

test('la sesión se mantiene al recargar y cada pantalla tiene su dirección', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/#reportes/goleadores');
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Reportes del torneo' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Goleadores' })).toHaveAttribute('aria-current', 'page');
});
