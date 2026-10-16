// Small DOM and HTML helpers. Every value interpolated into markup must go through esc().

import { ICONS } from './icons.js';

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ENTITIES[char]);

export const cx = (...names) => names.filter(Boolean).join(' ');

export const icon = name => `<svg class="icon" aria-hidden="true" viewBox="0 0 24 24">${ICONS[name] ?? ICONS.info}</svg>`;
