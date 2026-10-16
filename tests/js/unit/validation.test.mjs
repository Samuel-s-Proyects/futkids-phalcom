// Same cases as tests/php/Unit/ValidationRulesTest.php: browser and server must agree.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import rules from '../../../config/validation-rules.json' with { type: 'json' };
import cases from '../../fixtures/validation-cases.json' with { type: 'json' };
import { cleanText, matchesRule, setRules, validateValue } from '../../../public/js/domain/validation.js';

setRules(rules);

for (const [rule, { valid, invalid }] of Object.entries(cases)) {
  test(`${rule}: acepta los valores válidos`, () => {
    for (const value of valid) assert.equal(matchesRule(rule, value), true, JSON.stringify(value));
  });
  test(`${rule}: rechaza los valores inválidos`, () => {
    for (const value of invalid) assert.equal(matchesRule(rule, value), false, JSON.stringify(value));
  });
}

test('cleanText recorta, compone tildes y deja un solo espacio', () => {
  assert.equal(cleanText('  Cancha \t 2  '), 'Cancha 2');
  assert.equal(cleanText('José'), 'José');
});

test('campos obligatorios y opcionales', () => {
  assert.equal(validateValue('', { required: true }), 'Este campo es obligatorio.');
  assert.equal(validateValue('', { required: true, kind: 'select' }), 'Seleccione una opción.');
  assert.equal(validateValue('   ', { required: false }), '');
});

test('números: solo dígitos y dentro del rango', () => {
  assert.equal(validateValue('7', { kind: 'number', min: 1, max: 99 }), '');
  assert.match(validateValue('+7', { kind: 'number', min: 1, max: 99 }), /solo números/);
  assert.match(validateValue('120', { kind: 'number', min: 1, max: 99 }), /entre 1 y 99/);
});

test('fechas: límites mínimo y máximo', () => {
  assert.equal(validateValue('2026-10-16', { kind: 'date', min: '2026-10-16' }), '');
  assert.match(validateValue('2026-10-15', { kind: 'date', min: '2026-10-16' }), /anterior al 16\/10\/2026/);
  assert.match(validateValue('2026-10-20T10:00', { kind: 'datetime', max: '2026-10-19T23:59' }), /hasta el 19\/10\/2026 23:59/);
  assert.match(validateValue('20/10/2026', { kind: 'date' }), /fecha válida/);
});

test('longitud máxima y regla de texto', () => {
  assert.match(validateValue('a'.repeat(151), { maxlength: 150 }), /Máximo 150/);
  assert.notEqual(validateValue('Copa #1', { rule: 'title' }), '');
});
