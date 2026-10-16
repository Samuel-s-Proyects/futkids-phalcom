// Form fields with label, hint and error message wired for accessibility, plus live validation.
//
//   field({name: 'venue', label: 'Cancha', rule: 'title', required: true, maxlength: 150,
//          hint: 'Escriba el nombre del campo…'})
//
// Numbers use a text input with inputmode="numeric" so letters, signs and exponents can't be typed.
import { esc, $$ } from '../core/dom.js';
import { rulePattern, validateValue } from '../domain/validation.js';

let sequence = 0;

/**
 * @param {object} spec
 * @param {string} spec.name
 * @param {string} spec.label
 * @param {'text'|'number'|'date'|'datetime-local'|'email'|'password'|'select'|'textarea'|'file'} [spec.type]
 * @param {*} [spec.value]
 * @param {boolean} [spec.required]
 * @param {string} [spec.hint] help shown under the control
 * @param {string} [spec.rule] shared pattern (person_name, title, description)
 * @param {number|string} [spec.min] [spec.max] [spec.maxlength]
 * @param {Array<{value: *, label: string}>} [spec.options] for selects
 * @param {string} [spec.placeholder] [spec.change] [spec.autocomplete] [spec.accept]
 * @param {boolean} [spec.full] spans both grid columns
 * @param {boolean} [spec.disabled]
 */
export function field(spec) {
  const id = `field-${++sequence}`;
  const { name, label, type = 'text', value = '', required = false, hint = '', rule = '', full = false } = spec;
  const described = `${hint ? `${id}-hint ` : ''}${id}-error`;
  const common = [
    `id="${id}"`, `name="${esc(name)}"`, `aria-describedby="${described}"`,
    required ? 'required aria-required="true"' : '',
    rule ? `data-rule="${esc(rule)}"` : '',
    spec.change ? `data-change="${esc(spec.change)}"` : '',
    spec.live ? 'data-live="input"' : '',
    spec.disabled ? 'disabled' : '',
  ].filter(Boolean).join(' ');

  let control;
  if (type === 'select') {
    const options = (spec.options ?? []).map(option => `<option value="${esc(option.value)}" ${String(option.value) === String(value ?? '') ? 'selected' : ''}>${esc(option.label)}</option>`).join('');
    control = `<select ${common}>${spec.placeholder !== undefined ? `<option value="">${esc(spec.placeholder)}</option>` : ''}${options}</select>`;
  } else if (type === 'textarea') {
    control = `<textarea ${common} data-multiline="1" ${spec.maxlength ? `maxlength="${spec.maxlength}" data-counter="1"` : ''} rows="4" ${spec.placeholder ? `placeholder="${esc(spec.placeholder)}"` : ''}>${esc(value)}</textarea>`
      + (spec.maxlength ? `<span class="field__counter" data-counter-for="${id}">${String(value ?? '').length}/${spec.maxlength}</span>` : '');
  } else {
    const numeric = type === 'number';
    const attributes = [
      `type="${numeric ? 'text' : type}"`,
      numeric ? `inputmode="numeric" data-rule="digits" pattern="[0-9]*" autocomplete="off"` : '',
      type !== 'file' ? `value="${esc(value)}"` : '',
      spec.min !== undefined && spec.min !== '' ? `${numeric ? 'data-min' : 'min'}="${esc(spec.min)}"` : '',
      spec.max !== undefined && spec.max !== '' ? `${numeric ? 'data-max' : 'max'}="${esc(spec.max)}"` : '',
      spec.maxlength ? `maxlength="${spec.maxlength}"` : '',
      spec.placeholder ? `placeholder="${esc(spec.placeholder)}"` : '',
      spec.autocomplete ? `autocomplete="${esc(spec.autocomplete)}"` : '',
      spec.accept ? `accept="${esc(spec.accept)}"` : '',
      rule && rulePattern(rule) ? `pattern="${esc(rulePattern(rule).replace(/^\^|\$$/g, ''))}"` : '',
      spec.suggestions?.length ? `list="${id}-suggestions"` : '',
    ].filter(Boolean).join(' ');
    control = `<input ${common} ${attributes}>`
      + (spec.suggestions?.length ? `<datalist id="${id}-suggestions">${spec.suggestions.map(option => `<option value="${esc(option)}"></option>`).join('')}</datalist>` : '');
  }
  const marker = required ? '<span class="field__required" aria-hidden="true">*</span>' : spec.hideOptional ? '' : '<span class="field__optional"> (opcional)</span>';
  return `<div class="field${full ? ' field--full' : ''}${spec.className ? ' ' + spec.className : ''}">`
    + `<label class="field__label" for="${id}">${esc(label)}${marker}</label>${control}`
    + (hint ? `<span class="field__hint" id="${id}-hint">${esc(hint)}</span>` : '')
    + `<span class="field__error" id="${id}-error"></span></div>`;
}

/** "* Campo obligatorio" legend placed at the top of forms with required fields. */
export const requiredLegend = () => '<p class="form-legend">Los campos marcados con <span class="field__required" aria-hidden="true">*</span><span class="visually-hidden">asterisco</span> son obligatorios.</p>';

const kindOf = control => {
  if (control.tagName === 'SELECT') return 'select';
  if (control.dataset.rule === 'digits') return 'number';
  if (control.type === 'date') return 'date';
  if (control.type === 'datetime-local') return 'datetime';
  return 'text';
};

/** Validates one control and shows (or clears) its message. */
export function validateControl(control) {
  if (control.disabled || control.type === 'file' || control.type === 'checkbox' || control.type === 'hidden' || control.closest('[hidden]')) return true;
  let message = validateValue(control.value, {
    required: control.required,
    rule: control.dataset.rule === 'digits' ? '' : control.dataset.rule,
    min: control.dataset.min ?? control.min ?? '',
    max: control.dataset.max ?? control.max ?? '',
    maxlength: control.maxLength > 0 ? control.maxLength : 0,
    kind: kindOf(control),
    multiline: control.dataset.multiline === '1',
  });
  if (!message && control.type === 'email' && control.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(control.value.trim())) message = 'Ingrese un correo válido, por ejemplo nombre@dominio.com.';
  setControlError(control, message);
  return message === '';
}

export function setControlError(control, message) {
  const error = control.id ? document.getElementById(`${control.id}-error`) : null;
  if (error) error.textContent = message;
  if (message) control.setAttribute('aria-invalid', 'true');
  else control.removeAttribute('aria-invalid');
}

/** Validates every control; focuses the first invalid one. */
export function validateForm(form) {
  const controls = [...form.elements].filter(element => ['INPUT', 'SELECT', 'TEXTAREA'].includes(element.tagName));
  const invalid = controls.filter(control => !validateControl(control));
  invalid[0]?.focus();
  return invalid.length === 0;
}

export function clearErrors(form) {
  $$('.field__error', form).forEach(element => { element.textContent = ''; });
  $$('[aria-invalid]', form).forEach(element => element.removeAttribute('aria-invalid'));
  const general = form.querySelector('.form-error');
  if (general) general.textContent = '';
}

/**
 * Shows a server error: messages for known fields next to them, the summary at the top of the form.
 * Field names that are not controls (e.g. goal_events) are listed in the summary.
 */
export function showServerErrors(form, error) {
  const general = form.querySelector('.form-error');
  const unmatched = [];
  let first = null;
  for (const [name, message] of Object.entries(error.fields ?? {})) {
    const control = form.elements.namedItem(name);
    if (control?.id) {
      setControlError(control, message);
      first ??= control;
    } else unmatched.push(message);
  }
  if (general) general.textContent = [error.message, ...unmatched].join(' ');
  first?.focus();
}

/** Plain object with the form values (text trimmed). */
export function formValues(form) {
  const values = {};
  for (const [key, value] of new FormData(form)) values[key] = typeof value === 'string' ? value.trim() : value;
  return values;
}

/**
 * Submits a form through `send`, disabling the submit button meanwhile and showing errors in place.
 * Returns the result of `send`, or null when validation or the request failed.
 */
export async function submitForm(form, send) {
  clearErrors(form);
  if (!validateForm(form)) {
    const general = form.querySelector('.form-error');
    if (general) general.textContent = 'Revise los campos marcados.';
    return null;
  }
  const button = form.querySelector('[type="submit"]');
  if (button) button.disabled = true;
  try {
    return await send(formValues(form));
  } catch (error) {
    if (error.status) {
      showServerErrors(form, error);
      return null;
    }
    throw error;
  } finally {
    if (button) button.disabled = false;
  }
}

/** Live validation for every form in the page (installed once). */
export function installLiveValidation(root = document) {
  root.addEventListener('focusout', event => {
    const control = event.target;
    if (control.form && ['INPUT', 'SELECT', 'TEXTAREA'].includes(control.tagName) && control.value !== '') validateControl(control);
  });
  root.addEventListener('input', event => {
    const control = event.target;
    if (control.dataset?.counter) {
      const counter = document.querySelector(`[data-counter-for="${control.id}"]`);
      if (counter) counter.textContent = `${control.value.length}/${control.maxLength}`;
    }
    if (control.getAttribute?.('aria-invalid') === 'true') validateControl(control);
  });
  root.addEventListener('beforeinput', event => {
    if (event.target.dataset?.rule === 'digits' && event.data && /[^0-9]/.test(event.data)) event.preventDefault();
  });
}
