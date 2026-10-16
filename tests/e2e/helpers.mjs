import { expect } from '@playwright/test';

export const ACCOUNTS = {
  admin: { email: 'admin@cantera.local', password: 'Cantera2026!' },
  referee: { email: 'arbitro@cantera.local', password: 'Arbitro2026!' },
};

/** Signs in through the login screen and waits for the dashboard. */
export async function login(page, role = 'admin', credentials = ACCOUNTS[role]) {
  await page.goto('/#panel');
  await page.getByLabel('Correo electrónico').fill(credentials.email);
  await page.getByLabel('Contraseña', { exact: true }).fill(credentials.password);
  await page.getByRole('button', { name: 'Ingresar' }).click();
  await expect(page.getByRole('heading', { name: 'Panel general', level: 1 })).toBeVisible();
}

/**
 * Calls the JSON API with the page's session (cookies) and CSRF token, for fast test set-up.
 * Returns {status, data}.
 */
export async function api(page, path, { method = 'GET', body } = {}) {
  const session = await (await page.request.get('/api/session')).json();
  const response = await page.request.fetch('/api' + path, {
    method,
    headers: method === 'GET' ? {} : { 'X-CSRF-Token': session.csrf, 'Content-Type': 'application/json' },
    data: body === undefined ? undefined : JSON.stringify(body),
  });
  return { status: response.status(), data: await response.json() };
}

/** A unique, valid name (letters, digits and spaces only). */
export const uniqueName = prefix => `${prefix} ${Date.now().toString().slice(-6)}`;

/** Field located by its visible label inside the open dialog. */
export const dialogField = (page, label) => page.locator('dialog[open]').getByLabel(label, { exact: false });

/** The error message rendered under a control (aria-describedby). */
export async function fieldError(control) {
  const ids = (await control.getAttribute('aria-describedby')).split(' ');
  return control.page().locator(`#${ids.at(-1)}`);
}
