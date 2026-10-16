// Entry point: session, shell (navigation, theme, mobile menu) and routing to the views.
import { api, onUnauthorized, setCsrf } from './core/api.js';
import { syncClock } from './core/clock.js';
import { $, esc, icon } from './core/dom.js';
import { listen, onAction, onSubmit } from './core/events.js';
import { currentRoute, go, onRouteChange } from './core/router.js';
import { isAdmin, loadState, loadTournament, refreshIfStale, store } from './core/store.js';
import { setRules } from './domain/validation.js';
import { emptyState } from './ui/components.js';
import { closeDialogs } from './ui/dialog.js';
import { installLiveValidation } from './ui/form.js';
import { toast } from './ui/toast.js';
import './views/catalog/forms.js';
import './views/catalog/player-file.js';
import { renderRecords } from './views/catalog/records.js';
import { renderTeams } from './views/catalog/teams.js';
import { renderDashboard } from './views/dashboard.js';
import { renderReports } from './views/reports.js';
import './views/tournaments/form.js';
import { renderTournamentDetail } from './views/tournaments/detail.js';
import { renderTournamentList } from './views/tournaments/list.js';
import { loadAccounts, openOwnPassword, renderUsers } from './views/users.js';

/** Sections in menu order. `records` sections reuse the generic list. */
const SECTIONS = [
  { key: 'panel', label: 'Panel general', icon: 'dashboard', render: main => renderDashboard(main) },
  {
    key: 'torneos', label: 'Torneos', icon: 'trophy',
    load: ([id]) => (id && +store.tournament?.id !== +id ? loadTournament(+id) : null),
    render: (main, [id, tab]) => (id ? renderTournamentDetail(main, tab) : renderTournamentList(main)),
  },
  { key: 'equipos', label: 'Equipos', icon: 'teams', render: main => renderTeams(main) },
  { key: 'jugadores', label: 'Jugadores', icon: 'players', entity: 'players' },
  { key: 'incidencias', label: 'Incidencias', icon: 'card', entity: 'incidents' },
  { key: 'reportes', label: 'Reportes', icon: 'reports', render: (main, [kind]) => renderReports(main, kind) },
  { key: 'jornadas', label: 'Jornadas', icon: 'calendar', entity: 'matchdays', group: 'Histórico' },
  { key: 'goles', label: 'Goles', icon: 'ball', entity: 'goals', group: 'Histórico' },
  { key: 'usuarios', label: 'Usuarios', icon: 'users', group: 'Administración', adminOnly: true, load: () => loadAccounts(), render: main => renderUsers(main) },
];
/** Sections the signed-in person may open. */
const visibleSections = () => SECTIONS.filter(section => !section.adminOnly || isAdmin());
const sectionFor = key => visibleSections().find(section => section.key === key);

// ---- Shell --------------------------------------------------------------------------------------

function renderNavigation(active) {
  let group = '';
  $('#navigation').innerHTML = visibleSections().map(section => {
    const heading = section.group && section.group !== group ? `<p class="nav__group-label">${esc(section.group)}</p>` : '';
    group = section.group ?? group;
    return `${heading}<a class="nav__item" href="#${section.key}" ${section.key === active ? 'aria-current="page"' : ''}>${icon(section.icon)}${esc(section.label)}</a>`;
  }).join('');
}

function renderBreadcrumb(section, params) {
  if (section.key === 'panel') {
    $('#breadcrumb').innerHTML = `<strong>${esc(section.label)}</strong>`;
    return;
  }
  const parts = [`<a href="#panel">Inicio</a>`];
  parts.push(params.length ? `<a href="#${section.key}">${esc(section.label)}</a>` : `<strong>${esc(section.label)}</strong>`);
  if (section.key === 'torneos' && params[0] && store.tournament && +store.tournament.id === +params[0]) parts.push(`<strong>${esc(store.tournament.name)}</strong>`);
  $('#breadcrumb').innerHTML = parts.join('<span aria-hidden="true">/</span>');
}

function setMenu(open) {
  $('#sidebar').classList.toggle('is-open', open);
  $('#sidebar-backdrop').classList.toggle('is-visible', open);
  $('#menu-icon').setAttribute('aria-expanded', String(open));
  if (open) $('#navigation a')?.focus();
}

function applyTheme(theme) {
  if (theme) document.documentElement.dataset.theme = theme;
  else delete document.documentElement.dataset.theme;
  const dark = theme ? theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  const toggle = $('#theme-toggle');
  toggle.innerHTML = icon(dark ? 'sun' : 'moon');
  toggle.setAttribute('aria-label', dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
  toggle.title = toggle.getAttribute('aria-label');
}

const storedTheme = () => { try { return localStorage.getItem('cantera-theme'); } catch { return null; } };

// ---- Routing ------------------------------------------------------------------------------------

let renderGeneration = 0;

/** Loads what the section needs, then draws it, unless a newer navigation started meanwhile. */
async function show(route, { refresh = false } = {}) {
  if (!store.user) return;
  const generation = ++renderGeneration;
  const section = sectionFor(route.section);
  if (!section) return go('panel');
  setMenu(false);
  if (!refresh) closeDialogs();
  renderNavigation(section.key);
  const main = $('#main-content');
  try {
    await refreshIfStale();
    await section.load?.(route.params);
    if (generation !== renderGeneration) return;
    if (section.entity) renderRecords(main, section.entity);
    else section.render(main, route.params);
  } catch (error) {
    if (generation !== renderGeneration) return;
    main.innerHTML = emptyState('No se pudo mostrar esta sección', error.message, 'alert');
  }
  renderBreadcrumb(section, route.params);
  document.title = `${section.label} · Cantera`;
}

// ---- Session ------------------------------------------------------------------------------------

function showLogin() {
  $('#startup').hidden = true;
  $('#app').hidden = true;
  $('#login').hidden = false;
  $('#demo-accounts').hidden = !store.settings.demo_accounts;
  $('#login-form [name=email]').focus();
}

async function showApp() {
  await loadState();
  $('#startup').hidden = true;
  $('#login').hidden = true;
  $('#app').hidden = false;
  $('#account-name').textContent = store.user.name;
  $('#account-role').textContent = isAdmin() ? 'Administrador' : 'Árbitro';
  $('#account-avatar').textContent = store.user.name.charAt(0).toUpperCase();
  if (!sectionFor(currentRoute().section)) go('panel');
  else show(currentRoute());
  if (store.user.must_change_password) openOwnPassword(true);
}

function applySession(session) {
  store.user = session.user;
  setCsrf(session.csrf);
  if (session.settings) {
    store.settings = { ...store.settings, ...session.settings };
    setRules(store.settings.validation);
    syncClock(store.settings.server_now);
  }
}

onSubmit('login-form', async form => {
  const error = $('#login-error');
  error.textContent = '';
  const email = form.elements.namedItem('email').value.trim();
  const password = form.elements.namedItem('password').value;
  if (!email || !password) { error.textContent = 'Escriba su correo y su contraseña.'; return; }
  const button = form.querySelector('[type=submit]');
  button.disabled = true;
  try {
    applySession(await api('/login', { method: 'POST', body: { email, password } }));
    form.reset();
    await showApp();
  } catch (failure) {
    error.textContent = failure.message;
  } finally {
    button.disabled = false;
  }
});

onAction('logout', async () => {
  const session = await api('/logout', { method: 'POST', body: {} });
  store.user = null;
  store.tournament = null;
  setCsrf(session.csrf);
  showLogin();
});

onAction('prefill', trigger => {
  const admin = trigger.dataset.role === 'admin';
  $('#login-form [name=email]').value = admin ? 'admin@cantera.local' : 'arbitro@cantera.local';
  $('#login-form [name=password]').value = admin ? 'Cantera2026!' : 'Arbitro2026!';
});

// ---- Shell actions ------------------------------------------------------------------------------

onAction('close-dialog', () => closeDialogs());
onAction('reload', () => location.reload());
onAction('print', () => window.print());
onAction('toggle-menu', () => setMenu(!$('#sidebar').classList.contains('is-open')));
onAction('close-menu', () => setMenu(false));
onAction('toggle-theme', () => {
  const dark = document.documentElement.dataset.theme
    ? document.documentElement.dataset.theme === 'dark'
    : matchMedia('(prefers-color-scheme: dark)').matches;
  const next = dark ? 'light' : 'dark';
  try { localStorage.setItem('cantera-theme', next); } catch { /* preference not stored */ }
  applyTheme(next);
});

document.addEventListener('keydown', event => { if (event.key === 'Escape' && $('#sidebar').classList.contains('is-open')) setMenu(false); });
document.addEventListener('change', event => {
  if (event.target.id === 'show-password') $('#login-form [name=password]').type = event.target.checked ? 'text' : 'password';
});

// ---- Start --------------------------------------------------------------------------------------

export async function start() {
  listen();
  installLiveValidation();
  applyTheme(storedTheme());
  $('#logout-icon').innerHTML = icon('logout');
  $('#password-icon').innerHTML = icon('key');
  $('#menu-icon').innerHTML = icon('menu');
  onRouteChange(show);
  onUnauthorized(() => { if (store.user) { store.user = null; toast('Su sesión terminó. Ingrese de nuevo.', { error: true }); showLogin(); } });
  try {
    applySession(await api('/session'));
    if (store.user) await showApp();
    else showLogin();
  } catch (error) {
    $('#startup').innerHTML = emptyState('No se pudo iniciar Cantera', error.message, 'alert', '<button class="button" type="button" data-action="reload">Reintentar</button>');
  }
}

if (!window.__CANTERA_TEST__) start();
