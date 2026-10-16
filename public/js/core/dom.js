// Small DOM and HTML helpers. Every value interpolated into markup must go through esc().

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ENTITIES[char]);

/** Joins truthy class names. */
export const cx = (...names) => names.filter(Boolean).join(' ');

const ICONS = {
  dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
  trophy: 'M8 3h8v7c0 4-8 4-8 0z M8 5H3v3c0 3 3 4 5 4 M16 5h5v3c0 3-3 4-5 4 M12 13v6 M8 21h8 M9 19h6',
  teams: 'M12 3l8 4v6c0 5-8 9-8 9S4 18 4 13V7z M8 11l3 3 5-5',
  players: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M22 21v-2a4 4 0 0 0-3-3.8 M16 3.2a4 4 0 0 1 0 7.6',
  calendar: 'M4 5h16v16H4z M16 3v4 M8 3v4 M4 10h16 M8 14h2 M14 14h2 M8 17h2',
  ball: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 8l4 3-1.5 5h-5L8 11z M12 3v5 M3.5 9l4.5 2 M7 20l2.5-4 M17 20l-2.5-4 M20.5 9L16 11',
  card: 'M7 3h10v18H7z M11 8h2',
  reports: 'M6 3h9l4 4v14H6z M14 3v5h5 M9 12h7 M9 16h7',
  history: 'M3 12a9 9 0 1 0 3-6.7 M3 4v5h5 M12 7v5l3 3',
  users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M19 8v6 M22 11h-6',
  search: 'M10.5 3a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15 M16 16l5 5',
  plus: 'M12 5v14 M5 12h14',
  edit: 'M15 4l5 5 M4 20l5-1 12-12a2.1 2.1 0 0 0-3-3L5 16z',
  delete: 'M3 6h18 M9 6V3h6v3 M6 6l1 15h10l1-15 M10 10v7 M14 10v7',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7 M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
  logout: 'M9 4H4v16h5 M13 8l4 4-4 4 M8 12h12',
  menu: 'M4 6h16 M4 12h16 M4 18h16',
  key: 'M8 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M11 11l10 10 M17 17l2-2 M19 19l2-2',
  close: 'M6 6l12 12 M18 6L6 18',
  arrow: 'M5 12h14 M14 7l5 5-5 5',
  back: 'M19 12H5 M10 7l-5 5 5 5',
  up: 'M12 19V5 M7 10l5-5 5 5',
  down: 'M12 5v14 M7 14l5 5 5-5',
  print: 'M6 9V3h12v6 M6 17H3V9h18v8h-3 M6 14h12v7H6z',
  download: 'M12 3v12 M7 10l5 5 5-5 M4 16v5h16v-5',
  alert: 'M12 3L2 21h20z M12 9v5 M12 17h.01',
  info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 11v6 M12 7h.01',
  check: 'M5 12l4 4L19 6',
  clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2',
  pin: 'M12 21s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z M12 7a2 2 0 1 0 0 4 2 2 0 0 0 0-4',
  whistle: 'M3 10a5 5 0 1 0 10 0h8V6H8 M8 10h.01',
  play: 'M7 4l13 8-13 8z',
  sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v2 M12 20v2 M4.9 4.9l1.4 1.4 M17.7 17.7l1.4 1.4 M2 12h2 M20 12h2 M4.9 19.1l1.4-1.4 M17.7 6.3l1.4-1.4',
  moon: 'M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z',
};

export const icon = name => `<svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="${ICONS[name] ?? ICONS.info}"/></svg>`;
