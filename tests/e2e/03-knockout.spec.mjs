// A knockout tournament from creation to champion, entirely through the interface.
import { expect, test } from '@playwright/test';
import { dialogField, fieldError, login, uniqueName } from './helpers.mjs';

test.describe.configure({ mode: 'serial' });

const name = uniqueName('Copa Apertura');
let url = '';

test.beforeEach(async ({ page }) => login(page, 'admin'));

test('crear el torneo con el asistente', async ({ page }) => {
  await page.goto('/#torneos');
  await page.getByRole('button', { name: 'Nuevo torneo' }).click();
  const dialog = page.locator('dialog[open]');
  await expect(dialog.getByText('Paso 1 de 4')).toBeVisible();
  await dialog.getByText('Clasificación', { exact: true }).click();
  await dialog.getByRole('button', { name: 'Siguiente' }).click();
  await dialogField(page, 'Nombre del torneo').fill(name);
  await dialogField(page, 'Fecha de inicio').fill('2026-10-16');
  await dialogField(page, 'Fecha de finalización').fill('2026-12-31');
  await dialog.getByRole('button', { name: 'Siguiente' }).click();
  await expect(dialog.getByText('Paso 3 de 4 · Reglas')).toBeVisible();
  await dialog.getByRole('button', { name: 'Siguiente' }).click();
  await expect(dialog.locator('#tournament-review')).toContainText('2 período(s) de 10 min');
  await dialog.getByRole('button', { name: 'Crear torneo' }).click();
  await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
  url = page.url();
});

test('inscribir cuatro equipos, ordenarlos y cerrar la inscripción', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('button', { name: 'Abrir inscripciones' }).click();
  await expect(page.getByText('Inscriba al menos 2 equipos (hay 0).')).toBeVisible();
  await page.getByRole('navigation', { name: 'Secciones del torneo' }).getByRole('link', { name: 'Equipos' }).click();
  for (const team of ['Leones de Petapa', 'Halcones Azules', 'Guerreros del Sur', 'Estrellas de Villa Nueva']) {
    await page.getByLabel('Equipo a inscribir').selectOption({ label: team });
    await page.getByRole('button', { name: 'Inscribir' }).click();
    await expect(page.locator('.participant__name', { hasText: team })).toBeVisible();
  }
  await page.getByLabel('¿Cómo ordenar a los equipos?').selectOption('manual');
  await page.getByRole('button', { name: 'Cerrar inscripción' }).click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Cerrar inscripción' }).click();
  await expect(page.getByRole('button', { name: 'Generar cuadro' })).toBeVisible();
});

test('generar el cuadro', async ({ page }) => {
  await page.goto(url);
  await page.getByRole('button', { name: 'Generar cuadro' }).click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Generar cuadro' }).click();
  await expect(page.getByRole('heading', { name: 'Semifinales', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Final', exact: true })).toBeVisible();
  await expect(page.locator('.match-card')).toHaveCount(3);
});

test('programar: no antes de ahora, y la cancha se compara sin tildes ni mayúsculas', async ({ page }) => {
  await page.goto(url.replace(/\/?$/, '') + '/cuadro');
  await page.getByRole('button', { name: /Partido: Leones de Petapa contra/ }).click();
  await page.getByRole('button', { name: 'Programar partido' }).click();
  const when = dialogField(page, 'Fecha y hora');
  await expect(page.locator('dialog[open]')).toContainText('Nombre del campo donde se juega');
  await when.fill('2026-10-15T10:00');
  await dialogField(page, 'Cancha').fill('Cancha Central');
  await dialogField(page, 'Árbitro').selectOption({ label: 'Árbitro' });
  await page.getByRole('button', { name: 'Guardar programación' }).click();
  await expect(await fieldError(when)).toContainText('Elija un momento a partir del 16/10/2026 09:00');
  await when.fill('2026-10-20T10:00');
  await page.getByRole('button', { name: 'Guardar programación' }).click();
  await expect(page.locator('dialog[open]')).toContainText('Listo para jugarse');

  await page.locator('dialog[open]').getByRole('button', { name: 'Cerrar', exact: true }).first().click();
  await page.getByRole('button', { name: /Partido: Halcones Azules contra/ }).click();
  await page.getByRole('button', { name: 'Programar partido' }).click();
  await dialogField(page, 'Fecha y hora').fill('2026-10-20T10:30');
  await dialogField(page, 'Cancha').fill('CÁNCHA central.');
  await dialogField(page, 'Árbitro').selectOption({ label: 'Árbitro' });
  await page.getByRole('button', { name: 'Guardar programación' }).click();
  await expect(await fieldError(dialogField(page, 'Cancha'))).toContainText('La cancha está ocupada a esa hora');
  await dialogField(page, 'Fecha y hora').fill('2026-10-20T12:00');
  await page.getByRole('button', { name: 'Guardar programación' }).click();
  await expect(page.locator('dialog[open]')).toContainText('Listo para jugarse');
});

test('jugar las semifinales con la hoja de partido (una por penales)', async ({ page }) => {
  await page.goto(url.replace(/\/?$/, '') + '/cuadro');
  const play = async (cardName, homeGoals, awayGoals, kicks = []) => {
    await page.getByRole('button', { name: cardName }).click();
    const dialog = page.locator('dialog[open]');
    await dialog.getByRole('button', { name: 'Iniciar partido' }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Iniciar partido' }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Finalizar y enviar resultado' }).click();
    const sheet = page.locator('#sheet-form');
    for (const [side, count] of [['home', homeGoals], ['away', awayGoals]]) {
      for (let goal = 0; goal < count; goal++) {
        await sheet.locator(`[data-action="sheet-add-goal"][data-side="${side}"]`).click();
        const row = sheet.locator(`.goal-row[data-side="${side}"]`).last();
        await row.locator('select').first().selectOption({ index: 1 });
        await row.getByLabel('Minuto').fill(String(10 + goal));
      }
    }
    await expect(sheet.locator('#sheet-score')).toHaveText(`${homeGoals} – ${awayGoals}`);
    if (kicks.length) {
      await expect(sheet.getByText('Tanda de penales')).toBeVisible();
      await sheet.getByLabel('Equipo que patea primero').selectOption({ index: 1 });
      for (const [index, scored] of kicks.entries()) await sheet.locator(`#kick-${index}`).selectOption(scored ? '1' : '0');
      await expect(sheet.locator('#sheet-shootout-status')).toContainText('en penales');
    }
    await sheet.getByRole('button', { name: 'Enviar resultado' }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Confirmar resultado' }).click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Confirmar resultado' }).click();
    await expect(page.locator('dialog[open]')).toContainText('Finalizado');
    await page.locator('dialog[open]').getByRole('button', { name: 'Cerrar', exact: true }).first().click();
  };
  await play(/Partido: Leones de Petapa contra/, 2, 0);
  await play(/Partido: Halcones Azules contra/, 1, 1, [1, 0, 1, 0, 1, 0]);
  const final = page.getByRole('heading', { name: 'Final', exact: true }).locator('..');
  await expect(final).toContainText('Leones de Petapa');
  await expect(final).toContainText('Halcones Azules');
});

test('los goleadores del torneo no cuentan autogoles', async ({ page }) => {
  await page.goto(url.replace(/\/?$/, '') + '/goleadores');
  await expect(page.getByRole('table', { name: 'Goleadores del torneo' })).toBeVisible();
  await expect(page.locator('tbody tr').first()).toContainText('2');
});
