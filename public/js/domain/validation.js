// Field rules shared with the server: the patterns come from config/validation-rules.json via /api/session,
// so the browser accepts and rejects exactly what PHP does.

const REQUIRED = 'Este campo es obligatorio.';
let rules = {};

/** @param {Record<string, {pattern: string, message: string}>} definitions */
export function setRules(definitions) {
  rules = Object.fromEntries(Object.entries(definitions ?? {}).map(([name, { pattern, message }]) => [name, { pattern, message, regex: new RegExp(pattern, 'u') }]));
}

export const rulePattern = name => rules[name]?.pattern ?? null;
export const ruleMessage = name => rules[name]?.message ?? '';
export const matchesRule = (name, value) => !rules[name] || rules[name].regex.test(value);

/** Same canonical form the server stores: trimmed, NFC, single spaces. */
export const cleanText = value => String(value ?? '').normalize('NFC').replace(/\s+/gu, ' ').trim();

/**
 * Validates one value. Returns an error message or ''.
 * @param {string} value
 * @param {{required?: boolean, rule?: string, min?: string, max?: string, maxlength?: number, kind?: string, multiline?: boolean}} constraints
 *        kind: 'text' | 'number' | 'date' | 'datetime' | 'select'
 */
export function validateValue(value, { required = false, rule = '', min = '', max = '', maxlength = 0, kind = 'text', multiline = false } = {}) {
  const text = multiline ? String(value ?? '').trim() : cleanText(value);
  if (text === '') return required ? (kind === 'select' ? 'Seleccione una opción.' : REQUIRED) : '';
  if (maxlength && text.length > maxlength) return `Máximo ${maxlength} caracteres.`;
  if (rule && !matchesRule(rule, text)) return ruleMessage(rule);
  if (kind === 'number') {
    if (!/^[0-9]+$/.test(text)) return ruleMessage('digits') || 'Escriba solo números.';
    const number = Number(text);
    if ((min !== '' && number < Number(min)) || (max !== '' && number > Number(max))) return `Ingrese un número entre ${min} y ${max}.`;
  }
  if (kind === 'date' || kind === 'datetime') {
    const pattern = kind === 'date' ? /^\d{4}-\d{2}-\d{2}$/ : /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
    if (!pattern.test(text) || Number.isNaN(Date.parse(text.slice(0, 10) + 'T00:00:00Z'))) return kind === 'date' ? 'Ingrese una fecha válida.' : 'Seleccione una fecha y hora válidas.';
    if (min && text < min) return kind === 'date' ? `La fecha no puede ser anterior al ${displayLimit(min)}.` : `Elija un momento a partir del ${displayLimit(min)}.`;
    if (max && text > max) return kind === 'date' ? `La fecha no puede ser posterior al ${displayLimit(max)}.` : `Elija un momento hasta el ${displayLimit(max)}.`;
  }
  return '';
}

function displayLimit(value) {
  const [day, time] = value.split('T');
  const [y, m, d] = day.split('-');
  return `${d}/${m}/${y}${time ? ' ' + time : ''}`;
}
