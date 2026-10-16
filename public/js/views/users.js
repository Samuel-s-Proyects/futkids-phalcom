// Administrator and referee accounts (administrators only), and the person's own password.
import { api } from '../core/api.js';
import { $, esc } from '../core/dom.js';
import { onAction, onSubmit } from '../core/events.js';
import { dateTime, plural } from '../core/format.js';
import { rerender } from '../core/router.js';
import { store } from '../core/store.js';
import { alertBox, badge, button, emptyState, iconButton, pageHeader, table } from '../ui/components.js';
import { closeDialogs, confirmDialog, dialogHeader, openDialog } from '../ui/dialog.js';
import { field, requiredLegend, submitForm } from '../ui/form.js';
import { toast } from '../ui/toast.js';

let accounts = [];

const ROLE_LABELS = { admin: 'Administrador', referee: 'Árbitro' };
const minLength = () => store.settings.password_min_length ?? 10;
const passwordHint = () => 'Clave para ingresar a la cuenta.';

/** Random temporary password that satisfies the policy (letters and digits, no ambiguous characters). */
export function suggestPassword(length = 12) {
  const letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
  const digits = '23456789';
  const pool = letters + digits;
  const random = new Uint32Array(length);
  crypto.getRandomValues(random);
  const chars = [...random].map((value, index) => (index === 0 ? letters : index === 1 ? digits : pool)[value % (index === 0 ? letters.length : index === 1 ? digits.length : pool.length)]);
  return chars.join('');
}

export async function loadAccounts() {
  accounts = (await api('/users')).items;
}

export function renderUsers(main) {
  const rows = accounts.map(user => [
    `<span class="person"><span><strong>${esc(user.name)}</strong><small>${esc(user.email)}</small></span></span>`,
    badge(ROLE_LABELS[user.role], user.role === 'admin' ? 'primary' : 'info'),
    user.active ? badge('Activa', 'success') : badge('Inactiva'),
    user.must_change_password ? badge('Contraseña temporal', 'warning') : '',
    esc(user.last_login_at ? dateTime(user.last_login_at) : 'Nunca'),
    user.role === 'referee' ? esc(plural(user.pending_matches.length, 'partido pendiente', 'partidos pendientes')) : '—',
    `<div class="row-actions">
      ${iconButton('edit', `Editar a ${user.name}`, { action: 'edit-user', data: { id: user.id } })}
      ${+user.id !== +store.user.id ? iconButton('key', `Restablecer contraseña de ${user.name}`, { action: 'reset-password', data: { id: user.id } }) : ''}
      ${+user.id !== +store.user.id ? button(user.active ? 'Desactivar' : 'Activar', { action: 'toggle-user', data: { id: user.id }, small: true }) : ''}
    </div>`,
  ]);
  main.innerHTML = pageHeader({
    eyebrow: 'Administración',
    title: 'Usuarios',
    subtitle: 'Cuentas de administradores y árbitros. Las cuentas no se borran: se desactivan para conservar el historial.',
    actions: button('Nuevo usuario', { action: 'new-user', tone: 'primary', iconName: 'plus' }),
  }) + `<div class="page-section">${alertBox('info', 'Roles', 'Administrador: configura torneos, equipos, jugadores y usuarios. Árbitro: consulta todo y registra goles, tarjetas y resultados de los partidos que tiene asignados.')}</div>`
    + `<section class="card card--flush" aria-label="Usuarios">${accounts.length
      ? table([{ label: 'Nombre' }, { label: 'Rol' }, { label: 'Estado' }, { label: '' }, { label: 'Último ingreso' }, { label: 'Partidos' }, { label: '' }], rows, { caption: 'Usuarios' })
      : emptyState('No hay usuarios', '', 'users')}</section>`;
}

function userForm(user = {}) {
  const editing = Boolean(user.id);
  const self = editing && +user.id === +store.user.id;
  return `${dialogHeader({ eyebrow: editing ? 'Editar usuario' : 'Nuevo usuario', title: editing ? user.name : 'Crear cuenta' })}
    <form id="user-form" novalidate data-id="${user.id ?? ''}"><div class="dialog__body">
      ${requiredLegend()}
      <div class="form-grid">
        ${field({ name: 'name', label: 'Nombre completo', value: user.name, required: true, rule: 'person_name', maxlength: 100, full: true, hint: 'Nombre completo de la persona.' })}
        ${field({ name: 'email', label: 'Correo electrónico', type: 'email', value: user.email, required: true, maxlength: 200, full: true, autocomplete: 'off', hint: 'Correo con el que ingresará a FutKids.' })}
        ${field({ name: 'role', label: 'Rol', type: 'select', value: user.role ?? 'referee', required: true, disabled: self, full: true, options: [{ value: 'referee', label: 'Árbitro' }, { value: 'admin', label: 'Administrador' }], hint: self ? 'Su propio rol no se puede cambiar.' : 'Define lo que la cuenta puede hacer.' })}
        ${editing ? '' : `${field({ name: 'password', label: 'Contraseña temporal', value: suggestPassword(), required: true, maxlength: 200, full: true, autocomplete: 'new-password', hint: 'Compártala con la persona: deberá cambiarla al ingresar.' })}`}
      </div>
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      <button class="button" type="button" data-action="close-dialog">Cancelar</button>
      ${button(editing ? 'Guardar cambios' : 'Crear usuario', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`;
}

const accountById = id => accounts.find(user => +user.id === +id);

onAction('new-user', () => openDialog(userForm()));
onAction('edit-user', trigger => openDialog(userForm(accountById(trigger.dataset.id))));

onSubmit('user-form', async form => {
  const id = form.dataset.id;
  const saved = await submitForm(form, values => {
    const body = id ? { ...values, role: values.role ?? accountById(id).role } : values;
    return api(`/users${id ? '/' + id : ''}`, { method: 'POST', body });
  });
  if (!saved) return;
  closeDialogs();
  await loadAccounts();
  rerender();
  toast(id ? 'Usuario actualizado.' : 'Usuario creado. Comparta la contraseña temporal con la persona.');
});

onAction('toggle-user', async trigger => {
  const user = accountById(trigger.dataset.id);
  const activate = !user.active;
  const ok = await confirmDialog({
    title: activate ? `¿Activar a ${user.name}?` : `¿Desactivar a ${user.name}?`,
    text: activate ? 'Podrá volver a ingresar con su contraseña.' : 'No podrá ingresar y su sesión se cerrará. Su historial se conserva.',
    confirmLabel: activate ? 'Activar' : 'Desactivar',
    danger: !activate,
  });
  if (!ok) return;
  try {
    await api(`/users/${user.id}/status`, { method: 'POST', body: { active: activate ? 1 : 0 } });
    toast(activate ? 'Cuenta activada.' : 'Cuenta desactivada.');
  } catch (error) {
    toast(Object.values(error.fields ?? {})[0] ? `${error.message} ${Object.values(error.fields)[0]}` : error.message, { error: true });
  }
  await loadAccounts();
  rerender();
});

onAction('reset-password', trigger => {
  const user = accountById(trigger.dataset.id);
  openDialog(`${dialogHeader({ eyebrow: 'Restablecer contraseña', title: user.name, subtitle: user.email })}
    <form id="reset-password-form" novalidate data-id="${user.id}"><div class="dialog__body">
      ${alertBox('info', 'Contraseña temporal', 'La persona deberá cambiarla la próxima vez que ingrese. Cópiela ahora: no se volverá a mostrar.')}
      ${field({ name: 'password', label: 'Nueva contraseña temporal', value: suggestPassword(), required: true, maxlength: 200, autocomplete: 'new-password', hint: passwordHint() })}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      <button class="button" type="button" data-action="close-dialog">Cancelar</button>
      ${button('Restablecer', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
});

onSubmit('reset-password-form', async form => {
  const saved = await submitForm(form, values => api(`/users/${form.dataset.id}/password`, { method: 'POST', body: { password: form.elements.password.value } }));
  if (!saved) return;
  closeDialogs();
  await loadAccounts();
  rerender();
  toast('Contraseña restablecida.');
});

/** @param {boolean} forced the account has a temporary password: the dialog cannot be dismissed */
export function openOwnPassword(forced = false) {
  const header = forced
    ? `<div class="dialog__header"><div><span class="dialog__eyebrow">Primer ingreso</span><h2 id="dialog-title">Cree su contraseña</h2><p>Su cuenta tiene una contraseña temporal. Elija una nueva para continuar.</p></div></div>`
    : dialogHeader({ eyebrow: 'Mi cuenta', title: 'Cambiar contraseña' });
  openDialog(`${header}
    <form id="own-password-form" novalidate data-forced="${forced ? 1 : 0}"><div class="dialog__body">
      ${field({ name: 'current_password', label: forced ? 'Contraseña temporal' : 'Contraseña actual', type: 'password', required: true, maxlength: 200, autocomplete: 'current-password' })}
      ${field({ name: 'new_password', label: 'Nueva contraseña', type: 'password', required: true, maxlength: 200, autocomplete: 'new-password', hint: passwordHint() })}
      ${field({ name: 'confirm_password', label: 'Repita la nueva contraseña', type: 'password', required: true, maxlength: 200, autocomplete: 'new-password' })}
      <p class="form-error" role="alert"></p>
    </div><div class="dialog__footer">
      ${forced ? button('Cerrar sesión', { action: 'logout' }) : '<button class="button" type="button" data-action="close-dialog">Cancelar</button>'}
      ${button('Guardar contraseña', { type: 'submit', tone: 'primary', iconName: 'check' })}
    </div></form>`);
  if (forced) $('#form-dialog').addEventListener('cancel', event => event.preventDefault(), { once: true });
}

onAction('own-password', () => openOwnPassword(false));

onSubmit('own-password-form', async form => {
  const confirmControl = form.elements.confirm_password;
  if (confirmControl.value && confirmControl.value !== form.elements.new_password.value) {
    const error = document.getElementById(`${confirmControl.id}-error`);
    error.textContent = 'Las contraseñas no coinciden.';
    confirmControl.setAttribute('aria-invalid', 'true');
    confirmControl.focus();
    return;
  }
  const saved = await submitForm(form, () => api('/account/password', { method: 'POST', body: { current_password: form.elements.current_password.value, new_password: form.elements.new_password.value } }));
  if (!saved) return;
  store.user = saved.user;
  closeDialogs();
  toast('Contraseña actualizada.');
});
