// JSON API client. Write requests carry the CSRF token; field errors from the server are kept on the error.

export class ApiError extends Error {
  constructor(message, status = 0, fields = {}) {
    super(message);
    this.status = status;
    this.fields = fields ?? {};
  }
}

let csrfToken = '';
let unauthorizedHandler = () => {};

export const setCsrf = token => { csrfToken = token ?? ''; };
export const onUnauthorized = handler => { unauthorizedHandler = handler; };

/**
 * @param {string} path relative to /api, e.g. "/tournaments/3"
 * @param {{method?: string, body?: object|FormData}} options
 */
export async function api(path, { method = 'GET', body } = {}) {
  const headers = {};
  if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
  let payload = body;
  if (body !== undefined && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch('/api' + path, { method, headers, body: payload, credentials: 'same-origin' });
  } catch {
    throw new ApiError('No se pudo conectar con el servidor. Revise su conexión e intente de nuevo.');
  }
  const data = await response.json().catch(() => ({ error: 'Respuesta inesperada del servidor.' }));
  if (!response.ok) {
    if (response.status === 401) unauthorizedHandler();
    throw new ApiError(data.error ?? 'No se pudo completar la solicitud.', response.status, data.fields);
  }
  return data;
}
