// Automated accessibility review (axe) of every main screen, in light and dark mode, plus the phone layout.
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { login } from './helpers.mjs';

const SCREENS = ['#panel', '#torneos', '#equipos', '#jugadores', '#incidencias', '#reportes/arbitro', '#reportes/goleadores', '#jornadas', '#usuarios'];

for (const scheme of ['light', 'dark']) {
  test(`sin problemas graves de accesibilidad en modo ${scheme === 'light' ? 'claro' : 'oscuro'}`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme });
    await login(page, 'admin');
    for (const screen of SCREENS) {
      await page.goto('/' + screen);
      await expect(page.locator('main h1')).toBeVisible();
      const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      const serious = violations.filter(v => ['serious', 'critical'].includes(v.impact));
      expect(serious.map(v => `${screen}: ${v.id} → ${v.nodes.map(n => n.target.join(' ')).slice(0, 3).join(', ')}`)).toEqual([]);
    }
  });
}

test('ningún texto mide menos de 13 px', async ({ page }) => {
  await login(page, 'admin');
  for (const screen of SCREENS) {
    await page.goto('/' + screen);
    await expect(page.locator('main h1')).toBeVisible();
    const small = await page.evaluate(() => [...document.querySelectorAll('body *')]
      .filter(element => element.childNodes.length && [...element.childNodes].some(node => node.nodeType === 3 && node.textContent.trim()))
      .filter(element => element.offsetParent !== null && !element.closest('.visually-hidden'))
      .map(element => ({ text: element.textContent.trim().slice(0, 30), size: parseFloat(getComputedStyle(element).fontSize) }))
      .filter(item => item.size < 13));
    expect(small, screen).toEqual([]);
  }
});

test('en el teléfono el menú se abre con fondo y los formularios usan una columna', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await login(page, 'admin');
  const menuButton = page.getByRole('button', { name: 'Abrir menú' });
  await expect(menuButton).toBeVisible();
  await menuButton.click();
  await expect(page.locator('#sidebar-backdrop')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#sidebar-backdrop')).toBeHidden();
  await page.goto('/#jugadores');
  await page.getByRole('button', { name: 'Nuevo jugador' }).click();
  const columns = await page.locator('dialog[open] .form-grid').evaluate(grid => getComputedStyle(grid).gridTemplateColumns.split(' ').length);
  expect(columns).toBe(1);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
});
