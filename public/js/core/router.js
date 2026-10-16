// Hash routes, e.g. #torneos/12/cuadro. Every screen has its own address, so reload and Back work.

const listeners = new Set();

/** @returns {{section: string, params: string[]}} */
export function currentRoute() {
  const [section = '', ...params] = decodeURIComponent(location.hash.replace(/^#\/?/, '')).split('/').filter(Boolean);
  return { section, params };
}

export function go(path) {
  const hash = '#' + path.replace(/^#/, '');
  if (location.hash === hash) notify();
  else location.hash = hash;
}

/** Draws the current screen again (after data changed). */
export const rerender = () => notify({ refresh: true });

export function onRouteChange(listener) {
  listeners.add(listener);
}

function notify(options = { refresh: false }) {
  const route = currentRoute();
  listeners.forEach(listener => listener(route, options));
}

window.addEventListener('hashchange', () => notify());
