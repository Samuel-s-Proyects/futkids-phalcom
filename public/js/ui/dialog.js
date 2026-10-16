// Native <dialog> helpers. Three shells exist in the page: default, wide and small (confirmations).
import { $, esc, icon } from '../core/dom.js';

const SHELLS = {
  default: ['#form-dialog', '#dialog-content', 'dialog-title'],
  wide: ['#wide-dialog', '#wide-dialog-content', 'wide-dialog-title'],
  small: ['#confirm-dialog', '#confirm-content', 'confirm-title'],
};

/**
 * Standard dialog header. The title id matches the dialog's aria-labelledby.
 * @param {{eyebrow?: string, title: string, subtitle?: string, size?: string}} options
 */
export function dialogHeader({ eyebrow = '', title, subtitle = '', size = 'default' }) {
  return `<div class="dialog__header"><div>${eyebrow ? `<span class="dialog__eyebrow">${esc(eyebrow)}</span>` : ''}<h2 id="${SHELLS[size][2]}">${esc(title)}</h2>${subtitle ? `<p>${esc(subtitle)}</p>` : ''}</div><button class="icon-button" type="button" data-action="close-dialog" aria-label="Cerrar">${icon('close')}</button></div>`;
}

/** Shows markup in a dialog and returns its content container. */
export function openDialog(html, size = 'default') {
  closeDialogs();
  const [dialogSelector, contentSelector] = SHELLS[size];
  const content = $(contentSelector);
  content.innerHTML = html;
  const dialog = $(dialogSelector);
  if (!dialog.open) dialog.showModal();
  content.querySelector('[autofocus], input:not([type=hidden]), select, textarea')?.focus();
  return content;
}

export function closeDialogs() {
  for (const [selector] of Object.values(SHELLS)) {
    const dialog = $(selector);
    if (dialog?.open) dialog.close();
  }
}

/**
 * Asks for confirmation. Resolves true only when the person confirms.
 * @param {{title: string, text: string, confirmLabel?: string, danger?: boolean}} options
 */
export function confirmDialog({ title, text, confirmLabel = 'Confirmar', danger = false }) {
  return new Promise(resolve => {
    const content = openDialog(
      `${dialogHeader({ title, size: 'small' })}<div class="dialog__body"><p>${esc(text)}</p></div>`
      + `<div class="dialog__footer"><button class="button" type="button" data-confirm="no">Cancelar</button>`
      + `<button class="button ${danger ? 'button--danger' : 'button--primary'}" type="button" data-confirm="yes">${esc(confirmLabel)}</button></div>`,
      'small',
    );
    const dialog = $('#confirm-dialog');
    const finish = answer => {
      content.removeEventListener('click', onClick);
      dialog.removeEventListener('close', onClose);
      if (dialog.open) dialog.close();
      resolve(answer);
    };
    const onClick = event => {
      const button = event.target.closest('[data-confirm], [data-action="close-dialog"]');
      if (button) finish(button.dataset.confirm === 'yes');
    };
    const onClose = () => finish(false);
    content.addEventListener('click', onClick);
    dialog.addEventListener('close', onClose);
    content.querySelector('[data-confirm="no"]').focus();
  });
}
