// Penalty shoot-out progress (mirror of App\Services\Match\PenaltyShootout), used to guide the referee
// while entering kicks. The server validates again when the result is submitted.

/**
 * @param {Array<0|1|null>} results kick outcomes in order (null = not entered yet)
 * @returns {{winner: number|null, home: number, away: number, decidedAt: number, suddenDeath: boolean,
 *            tiedWithoutSuddenDeath: boolean, kicksNeeded: number, kickerOf: (index: number) => number}}
 */
export function evaluateShootout(results, { first, home, away, attempts, suddenDeath }) {
  const second = +first === +home ? +away : +home;
  const kickerOf = index => (index % 2 === 0 ? +first : second);
  const taken = { [home]: 0, [away]: 0 };
  const goals = { [home]: 0, [away]: 0 };
  let inSuddenDeath = false;
  let winner = null;
  let decidedAt = -1;
  let tiedWithoutSuddenDeath = false;
  for (const [index, scored] of results.entries()) {
    if (scored === null || scored === '' || scored === undefined) break;
    const team = kickerOf(index);
    taken[team]++;
    goals[team] += +scored;
    if (!inSuddenDeath) {
      const homeLeft = Math.max(0, attempts - taken[home]);
      const awayLeft = Math.max(0, attempts - taken[away]);
      if (goals[home] > goals[away] + awayLeft) winner = +home;
      else if (goals[away] > goals[home] + homeLeft) winner = +away;
      else if (!homeLeft && !awayLeft) {
        if (suddenDeath) inSuddenDeath = true;
        else { tiedWithoutSuddenDeath = true; decidedAt = index; break; }
      }
    } else if (taken[home] === taken[away] && goals[home] !== goals[away]) {
      winner = goals[home] > goals[away] ? +home : +away;
    }
    if (winner) { decidedAt = index; break; }
  }
  const entered = decidedAt >= 0 ? decidedAt + 1 : results.filter(r => r === 0 || r === 1 || r === '0' || r === '1').length;
  const minimum = attempts * 2;
  const kicksNeeded = winner || tiedWithoutSuddenDeath ? entered : Math.max(minimum, entered + (entered % 2 === 0 ? 2 : 1));
  return { winner, home: goals[home], away: goals[away], decidedAt, suddenDeath: inSuddenDeath, tiedWithoutSuddenDeath, kicksNeeded, kickerOf };
}
