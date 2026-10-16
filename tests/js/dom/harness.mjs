// Loads the real page (Volt shell + ES modules) in jsdom with a fake API.
// Each test file gets one window; call boot() once, then drive the interface.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';

export const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const json = path => JSON.parse(readFileSync(resolve(root, path), 'utf8'));

export const seed = json('database/seed.json');
export const rules = json('config/validation-rules.json');

export const wait = (ms = 0) => new Promise(resolveWait => setTimeout(resolveWait, ms));

/** Waits until `check()` is truthy (rendering is asynchronous). */
export async function until(check, timeout = 2000) {
  const start = Date.now();
  for (;;) {
    const value = check();
    if (value) return value;
    if (Date.now() - start > timeout) throw new Error('Tiempo de espera agotado: ' + check.toString());
    await wait(10);
  }
}

/**
 * @param {object} options
 * @param {null|{id:number,name:string,role:string}} options.user signed-in user (null shows the login screen)
 * @param {Record<string, Function>} options.routes extra API handlers keyed by "METHOD /path"
 */
export async function boot({ user = null, routes = {}, hash = '' } = {}) {
  const html = readFileSync(resolve(root, 'app/views/index/index.volt'), 'utf8')
    .replace(/<script type="module"[^>]*><\/script>/, '');
  const dom = new JSDOM(html, { url: `https://futkids.test/${hash}`, pretendToBeVisual: true });
  const { window } = dom;

  window.HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
  window.HTMLDialogElement.prototype.close = function close() {
    if (!this.open) return;
    this.open = false;
    this.dispatchEvent(new window.Event('close'));
  };
  window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  window.print = () => {};
  window.URL.createObjectURL = () => 'blob:test';
  window.URL.revokeObjectURL = () => {};
  window.__FUTKIDS_TEST__ = true;

  const calls = [];
  const state = { user, data: structuredClone({ ...seed, tournaments: [], tournament_teams: [], tournament_matches: [], tournament_match_goal_events: [], tournament_match_goals: [], referees: [{ id: 2, name: 'Árbitro', email: 'arbitro@futkids.local' }] }) };
  const settings = {
    age_min: 5, age_max: 14, age_reference_date: '2026-10-03', demo_accounts: true,
    server_today: '2026-10-16', server_now: '2026-10-16T09:00', validation: rules,
    positions: ['Portero', 'Defensa', 'Mediocampista', 'Delantero'],
  };
  const handlers = {
    'GET /api/session': () => ({ user: state.user, csrf: 'token', settings }),
    'POST /api/login': body => {
      if (body.password !== 'FutKids2026!') return [401, { error: 'Correo o contraseña incorrectos.' }];
      state.user = { id: 1, name: 'Administrador', email: body.email, role: 'admin' };
      return { user: state.user, csrf: 'token2' };
    },
    'POST /api/logout': () => { state.user = null; return { user: null, csrf: 'token3' }; },
    'GET /api/state': () => state.data,
    ...routes,
  };

  window.fetch = async (url, init = {}) => {
    const { pathname, search } = new URL(url, 'https://futkids.test');
    const method = init.method ?? 'GET';
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : init.body;
    calls.push({ method, path: pathname, search, body, headers: init.headers ?? {} });
    const handler = handlers[`${method} ${pathname}`] ?? Object.entries(handlers).find(([key]) => {
      const [m, pattern] = key.split(' ');
      return m === method && new RegExp('^' + pattern.replace(/\{\w+\}/g, '[^/]+') + '$').test(pathname);
    })?.[1];
    if (!handler) return { ok: false, status: 404, json: async () => ({ error: `Sin simular: ${method} ${pathname}` }) };
    const result = await handler(body, pathname, search);
    const [status, payload] = Array.isArray(result) ? result : [200, result];
    return { ok: status < 400, status, json: async () => structuredClone(payload) };
  };

  for (const key of ['window', 'document', 'location', 'localStorage', 'navigator', 'HTMLElement', 'Event', 'FormData', 'File', 'Blob', 'Node', 'CSS', 'URL', 'matchMedia', 'fetch']) {
    Object.defineProperty(globalThis, key, { value: window[key], configurable: true, writable: true });
  }
  globalThis.window = window;

  // Modules are cached per process: boot() once per test file (node --test runs each file in its own process).
  const main = await import(pathToFileURL(resolve(root, 'public/js/main.js')).href);
  await main.start();
  const document = window.document;
  return {
    window,
    document,
    state,
    calls,
    $: selector => document.querySelector(selector),
    $$: selector => [...document.querySelectorAll(selector)],
    text: selector => document.querySelector(selector)?.textContent.replace(/\s+/g, ' ').trim() ?? '',
    click(selector) {
      const element = typeof selector === 'string' ? document.querySelector(selector) : selector;
      if (!element) throw new Error(`No existe ${selector}`);
      element.click();
    },
    async go(hash) {
      window.location.hash = hash;
      await wait(20);
    },
    change(element, value) {
      element.value = value;
      element.dispatchEvent(new window.Event('change', { bubbles: true }));
    },
  };
}
