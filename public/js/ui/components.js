// Reusable pieces of markup.
import { esc, icon } from '../core/dom.js';

export const badge = (text, tone = '') => `<span class="badge${tone ? ' badge--' + tone : ''}">${esc(text)}</span>`;

/** @param {{eyebrow?: string, title: string, subtitle?: string, actions?: string}} options actions is trusted markup */
export const pageHeader = ({ eyebrow = '', title, subtitle = '', actions = '' }) =>
  `<header class="page-header"><div>${eyebrow ? `<span class="page-header__eyebrow">${esc(eyebrow)}</span>` : ''}<h1>${esc(title)}</h1>${subtitle ? `<p class="page-header__subtitle">${esc(subtitle)}</p>` : ''}</div>${actions ? `<div class="page-header__actions">${actions}</div>` : ''}</header>`;

export const emptyState = (title, text = '', iconName = 'info', action = '') =>
  `<div class="empty-state">${icon(iconName)}<strong>${esc(title)}</strong>${text ? `<p>${esc(text)}</p>` : ''}${action}</div>`;

/** tone: info | warning | danger | success */
export const alertBox = (tone, title, text = '') =>
  `<div class="alert alert--${tone}" role="${tone === 'danger' ? 'alert' : 'status'}">${icon(tone === 'success' ? 'check' : tone === 'info' ? 'info' : 'alert')}<div>${title ? `<strong>${esc(title)}</strong>` : ''}${text ? esc(text) : ''}</div></div>`;

/**
 * Section navigation drawn as tabs. Each tab is a link to its own hash route, so it works with
 * Back/Forward and can be bookmarked; the current one carries aria-current.
 * @param {Array<{key: string, label: string, href: string}>} items
 */
export const tabs = (items, activeKey, label) =>
  `<nav class="tabs" aria-label="${esc(label)}">${items.map(item => `<a class="tab" href="${esc(item.href)}" ${item.key === activeKey ? 'aria-current="page"' : ''}>${esc(item.label)}</a>`).join('')}</nav>`;

/** @param {Array<{label: string, state: 'done'|'current'|'pending'}>} steps */
export const stepper = (steps, label = 'Etapas del torneo') =>
  `<ol class="stepper" aria-label="${esc(label)}">${steps.map(step => `<li class="stepper__step stepper__step--${step.state}" ${step.state === 'current' ? 'aria-current="step"' : ''}>${esc(step.label)}<span class="visually-hidden">${step.state === 'done' ? ' (completada)' : step.state === 'current' ? ' (etapa actual)' : ' (pendiente)'}</span></li>`).join('')}</ol>`;

export const button = (label, { action = '', tone = '', iconName = '', data = {}, type = 'button', small = false, href = '' } = {}) => {
  const classes = `button${tone ? ' button--' + tone : ''}${small ? ' button--small' : ''}`;
  const dataAttributes = Object.entries(data).map(([key, value]) => `data-${key}="${esc(value)}"`).join(' ');
  const content = `${iconName ? icon(iconName) : ''}${esc(label)}`;
  if (href) return `<a class="${classes}" href="${esc(href)}">${content}</a>`;
  return `<button class="${classes}" type="${type}" ${action ? `data-action="${esc(action)}"` : ''} ${dataAttributes}>${content}</button>`;
};

export const iconButton = (iconName, label, { action, data = {}, danger = false, disabled = false } = {}) =>
  `<button class="icon-button${danger ? ' icon-button--danger' : ''}" type="button" ${disabled ? 'disabled' : ''} data-action="${esc(action)}" ${Object.entries(data).map(([k, v]) => `data-${k}="${esc(v)}"`).join(' ')} aria-label="${esc(label)}" title="${esc(label)}">${icon(iconName)}</button>`;

const SAFE_PHOTO = /^(images\/player(-\d+|-placeholder)?\.svg|uploads\/players\/[a-f0-9]{32}\.jpg)$/;
export const photoUrl = path => '/' + (SAFE_PHOTO.test(path ?? '') ? path : 'images/player-placeholder.svg');
export const avatar = (player, large = false) => `<img class="avatar${large ? ' avatar--large' : ''}" src="${esc(photoUrl(player?.photo_path))}" alt="" loading="lazy">`;

/**
 * Table. columns: [{label, numeric?}]; rows: arrays of trusted cell markup.
 * rowClass(index) may return a class for a row (e.g. the leader of a table).
 */
export function table(columns, rows, { caption = '', rowClass = () => '' } = {}) {
  return `<div class="table-wrap"><table class="table">${caption ? `<caption class="visually-hidden">${esc(caption)}</caption>` : ''}<thead><tr>${columns.map(column => `<th scope="col" class="${column.numeric ? 'numeric' : ''}">${column.label ? esc(column.label) : '<span class="visually-hidden">Acciones</span>'}</th>`).join('')}</tr></thead><tbody>${rows.map((cells, rowIndex) => `<tr class="${rowClass(rowIndex)}">${cells.map((cell, index) => `<td class="${columns[index]?.numeric ? 'numeric' : ''}">${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}
