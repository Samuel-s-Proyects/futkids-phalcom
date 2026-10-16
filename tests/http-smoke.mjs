// End-to-end HTTP checks against a running instance (real PHP, Phalcon and database):
//   node tests/http-smoke.mjs http://localhost:8080
// Uses the demonstration accounts. Creates temporary records and removes (or deactivates) them at the end.
import assert from 'node:assert/strict';

const origin = process.argv[2] ?? 'http://localhost:8080';
let checks = 0;
const check = (condition, name) => {
  assert.ok(condition, name);
  checks++;
  console.log('PASS: ' + name);
};

/** A browser-like client with its own cookie jar and CSRF token. */
function client() {
  const cookies = new Map();
  let csrf = '';
  return async function request(path, { method = 'GET', body, token = true } = {}) {
    const headers = { Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; ') };
    if (method !== 'GET' && token) headers['X-CSRF-Token'] = csrf;
    let payload = body;
    if (body && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const response = await fetch(origin + path, { method, headers, body: payload });
    for (const cookie of response.headers.getSetCookie()) {
      const [name, ...value] = cookie.split(';')[0].split('=');
      cookies.set(name, value.join('='));
    }
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = text; }
    if (data?.csrf) csrf = data.csrf;
    return { status: response.status, data, headers: response.headers };
  };
}

const admin = client();
const created = [];
let temporaryUserId = 0;

async function create(entity, body) {
  const result = await admin('/api/' + entity, { method: 'POST', body });
  check(result.status === 201, `Alta de ${entity} (${result.status}: ${result.data.error ?? 'correcto'})`);
  created.push([entity, result.data.record.id]);
  return result.data.record;
}

try {
  let r = await admin('/api/health');
  check(r.status === 200 && r.data.framework.startsWith('Phalcon 5.'), 'Servidor Phalcon y conexión de datos');
  r = await admin('/');
  check(r.status === 200 && r.data.includes('Cantera · Torneo infantil') && r.headers.get('content-security-policy'), 'Página Volt y cabeceras de seguridad');
  r = await admin('/js/main.js');
  check(r.status === 200 && /javascript/.test(r.headers.get('content-type')), 'Módulos JavaScript servidos con tipo correcto');
  r = await admin('/api/session');
  const today = r.data.settings.server_today;
  check(r.status === 200 && r.data.user === null && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(today) && r.data.settings.validation.title, 'Sesión inicial, fecha del servidor y reglas de validación');
  r = await admin('/api/state');
  check(r.status === 401, 'Datos protegidos sin sesión');

  // Roles
  const referee = client();
  await referee('/api/session');
  r = await referee('/api/login', { method: 'POST', body: { email: 'admin@cantera.local', password: 'incorrecta' } });
  check(r.status === 401, 'Credenciales incorrectas');
  r = await referee('/api/login', { method: 'POST', body: { email: 'arbitro@cantera.local', password: 'Arbitro2026!' } });
  check(r.status === 200 && r.data.user.role === 'referee', 'Inicio de sesión del árbitro');
  r = await referee('/api/state');
  check(r.status === 200 && r.data.players.length >= 20, 'Consulta de datos por árbitro');
  r = await referee('/api/teams', { method: 'POST', body: { name: 'No autorizado', active: 1 } });
  check(r.status === 403, 'Árbitro no puede modificar el catálogo');
  r = await referee('/api/users');
  check(r.status === 403, 'Árbitro no administra usuarios');
  r = await referee('/api/logout', { method: 'POST', body: {} });
  check(r.status === 200, 'Cierre de sesión');

  await admin('/api/session');
  r = await admin('/api/login', { method: 'POST', body: { email: 'admin@cantera.local', password: 'Cantera2026!' } });
  check(r.status === 200 && r.data.user.role === 'admin', 'Inicio de sesión del administrador');
  r = await admin('/api/teams', { method: 'POST', token: false, body: { name: 'Sin CSRF', active: 1 } });
  check(r.status === 419, 'Los cambios requieren token CSRF');

  // Catalogue and reports
  r = await admin('/api/players?page=2');
  check(r.status === 200 && r.data.items.length === 10 && r.data.page === 2, 'Paginación Phalcon');
  r = await admin('/api/players?q=Mateo&team_id=1');
  check(r.status === 200 && r.data.total === 1, 'Búsqueda y filtro en servidor');
  r = await admin('/api/reports/referee?matchday_id=3');
  check(r.status === 200 && r.data.rows.filter(p => p.suspended).length === 3, 'Reporte arbitral con suspensiones');
  r = await admin('/api/reports/scorers');
  check(r.status === 200 && r.data.rows[0].total_goals >= 10 && r.data.rows.every((row, i, rows) => !i || rows[i - 1].total_goals >= row.total_goals), 'Reporte de goleadores ordenado');
  r = await admin('/api/reports/incidents?team_id=1&card_type=red');
  check(r.status === 200 && r.data.rows.length === 1, 'Reporte filtrado de incidencias');
  r = await admin('/api/player/2');
  check(r.status === 200 && r.data.history.length >= 5 && r.data.incidents.length === 1, 'Consulta completa del jugador');
  r = await admin('/api/goals', { method: 'POST', body: { player_id: 2, matchday_id: 3, goals: 1 } });
  check(r.status === 404, 'Los goles del histórico son de solo lectura');
  r = await admin('/api/matchdays/3', { method: 'DELETE' });
  check(r.status === 404, 'Las jornadas del histórico son de solo lectura');
  r = await admin('/api/teams/1', { method: 'DELETE' });
  check(r.status === 409, 'Se protege un equipo con jugadores');
  r = await admin('/api/teams', { method: 'POST', body: { name: 'Equipo #1 <b>', active: 1 } });
  check(r.status === 422 && r.data.fields.name, 'Se rechazan caracteres especiales en el nombre');

  const team = await create('teams', { name: 'Equipo HTTP temporal', active: 1 });
  const photo = new FormData();
  for (const [key, value] of Object.entries({ first_name: 'Óscar', last_name: 'Álvarez', birth_date: '2015-04-02', team_id: team.id, shirt_number: 11, position: 'Defensa' })) photo.set(key, String(value));
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=', 'base64');
  photo.set('photo', new Blob([png], { type: 'image/png' }), 'prueba.png');
  const player = await create('players', photo);
  check(/^uploads\/players\/[a-f0-9]{32}\.jpg$/.test(player.photo_path), 'Fotografía validada y convertida a JPEG');
  r = await admin('/' + player.photo_path);
  check(r.status === 200 && r.headers.get('content-type').startsWith('image/jpeg'), 'Fotografía disponible por URL');
  await create('incidents', { player_id: player.id, description: 'Amonestación de prueba HTTP', card_type: 'yellow', incident_date: today });
  r = await admin('/api/incidents', { method: 'POST', body: { player_id: player.id, description: 'Futura', card_type: 'yellow', incident_date: '2999-01-01' } });
  check(r.status === 422 && r.data.fields.incident_date, 'No se aceptan incidencias con fecha futura');
  r = await admin('/api/player/' + player.id);
  check(r.status === 200 && r.data.total_goals === 0 && r.data.incidents.length === 1, 'Altas reflejadas en la ficha');
  const fake = new FormData();
  for (const [key, value] of Object.entries({ first_name: 'Mala', last_name: 'Imagen', birth_date: '2015-04-02', team_id: team.id })) fake.set(key, String(value));
  fake.set('photo', new Blob(['<script>malicioso</script>'], { type: 'image/png' }), 'imagen.png');
  r = await admin('/api/players', { method: 'POST', body: fake });
  check(r.status === 422, 'Se rechaza un archivo con MIME falsificado');

  // Tournaments
  r = await admin('/api/tournaments', { method: 'POST', body: { name: 'Copa', type: 'knockout', start_date: '2000-01-01', end_date: '2000-01-02', extra_time_periods: 0, penalty_attempts_per_team: 5, penalty_sudden_death: 1 } });
  check(r.status === 422 && r.data.fields.start_date, 'Un torneo no puede empezar antes de hoy');

  // Accounts
  r = await admin('/api/users', { method: 'POST', body: { name: 'Árbitro Temporal', email: `temporal.${Date.now()}@cantera.local`, role: 'referee', password: 'Temporal2026' } });
  check(r.status === 201 && r.data.record.must_change_password === 1, 'Alta de árbitro con contraseña temporal');
  temporaryUserId = r.data.record.id;
  const newcomer = client();
  await newcomer('/api/session');
  r = await newcomer('/api/login', { method: 'POST', body: { email: r.data.record.email, password: 'Temporal2026' } });
  check(r.status === 200 && r.data.user.must_change_password === 1, 'El árbitro nuevo ingresa y debe cambiar su contraseña');
  r = await newcomer('/api/account/password', { method: 'POST', body: { current_password: 'Temporal2026', new_password: 'Definitiva2026' } });
  check(r.status === 200 && r.data.user.must_change_password === 0, 'Cambio de contraseña propia');
  r = await admin(`/api/users/${temporaryUserId}/status`, { method: 'POST', body: { active: 0 } });
  check(r.status === 200 && r.data.record.active === 0, 'Desactivación de la cuenta');
  r = await newcomer('/api/state');
  check(r.status === 401, 'Una cuenta desactivada pierde la sesión de inmediato');
  r = await admin('/api/users/1/status', { method: 'POST', body: { active: 0 } });
  check(r.status === 409, 'Nadie puede desactivar su propia cuenta');

  r = await admin('/api/no-existe');
  check(r.status === 404, 'Ruta inexistente devuelve 404');
} finally {
  const order = ['incidents', 'players', 'teams'];
  for (const [entity, id] of [...created].sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]))) {
    const r = await admin(`/api/${entity}/${id}`, { method: 'DELETE' });
    check(r.status === 200, 'Limpieza de ' + entity);
  }
}
console.log(`\n${checks} verificaciones HTTP correctas.`);
