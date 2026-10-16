// Mirrors tests/php/Unit/PenaltyShootoutTest.php and ScoreboardTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { evaluateShootout } from '../../../public/js/domain/penalties.js';
import { creditedTeam, tally } from '../../../public/js/domain/score.js';

const HOME = 10;
const AWAY = 20;
const shootout = (results, options = {}) => evaluateShootout(results, { first: HOME, home: HOME, away: AWAY, attempts: 5, suddenDeath: true, ...options });

test('penales: decidido dentro de los tiros iniciales', () => {
  const outcome = shootout([1, 1, 1, 1, 1, 1, 1, 0, 0, 0]);
  assert.deepEqual([outcome.winner, outcome.home, outcome.away, outcome.decidedAt], [HOME, 4, 3, 9]);
});

test('penales: decisión anticipada cuando el rival ya no alcanza', () => {
  const outcome = shootout([1, 0, 1, 0, 1, 0, 1]);
  assert.equal(outcome.winner, HOME);
  assert.equal(outcome.decidedAt, 5);
});

test('penales: muerte súbita por parejas', () => {
  const outcome = shootout([1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 1, 0]);
  assert.deepEqual([outcome.winner, outcome.suddenDeath], [HOME, true]);
});

test('penales: empate sin muerte súbita', () => {
  const outcome = shootout([1, 1, 1, 1, 1, 1, 0, 0, 0, 0], { suddenDeath: false });
  assert.equal(outcome.winner, null);
  assert.equal(outcome.tiedWithoutSuddenDeath, true);
});

test('penales: cuántos tiros mostrar', () => {
  assert.equal(shootout([]).kicksNeeded, 10);
  assert.equal(shootout([1, 1, 1, 1, 1, 1, 0, 0, 0, 0]).kicksNeeded, 12, 'empate tras 10: se agrega una pareja');
  assert.equal(shootout([1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 1]).kicksNeeded, 12);
});

test('penales: alterna pateadores desde el primero elegido', () => {
  const outcome = shootout([], { first: AWAY });
  assert.deepEqual([outcome.kickerOf(0), outcome.kickerOf(1), outcome.kickerOf(2)], [AWAY, HOME, AWAY]);
});

test('marcador: los autogoles cuentan para el rival', () => {
  assert.equal(creditedTeam(HOME, true, HOME, AWAY), AWAY);
  assert.equal(creditedTeam(AWAY, false, HOME, AWAY), AWAY);
  const events = [
    { credited_team_id: HOME, period_number: 0 },
    { credited_team_id: AWAY, period_number: 0 },
    { credited_team_id: HOME, period_number: 1 },
  ];
  assert.deepEqual(tally(events, HOME, AWAY), { home: 2, away: 1, regulation: { home: 1, away: 1 } });
});
