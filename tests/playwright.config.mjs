// End-to-end tests in a real browser against the real PHP application.
// The server runs on an isolated SQLite database with the clock frozen at 2026-10-16 09:00 (America/Guatemala).
//   npm --prefix tests run e2e
// Locally the server runs in Docker (image cantera-base, see Dockerfile target "base"); in CI it runs with the
// PHP installed by setup-php (E2E_SERVER=native).
import { defineConfig, devices } from '@playwright/test';
import cleanup from './e2e/docker-cleanup.mjs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const port = Number(process.env.E2E_PORT ?? 8091);
const environment = 'APP_ENV=testing APP_FIXED_NOW=2026-10-16T09:00:00 DB_DRIVER=sqlite DB_NAME=/tmp/cantera-e2e.sqlite';
const serve = `rm -f /tmp/cantera-e2e.sqlite && mkdir -p var/session var/cache && php scripts/migrate.php && php scripts/seed.php && php -S 0.0.0.0:${port} -t public public/router.php`;

// A server left over from an interrupted run would keep the port busy. Workers load this file too:
// only the main process (no TEST_WORKER_INDEX) may clean up, before it starts the server.
if (process.env.TEST_WORKER_INDEX === undefined) cleanup();
const command = process.env.E2E_SERVER === 'native'
  ? `cd ${JSON.stringify(root)} && env ${environment} sh -c ${JSON.stringify(serve)}`
  : `docker run --rm --name cantera-e2e -p ${port}:${port} -v ${JSON.stringify(root + ':/var/www/html')} -w /var/www/html `
    + environment.split(' ').map(pair => `-e ${pair}`).join(' ')
    + ` cantera-base sh -c ${JSON.stringify(serve)}`;

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.mjs',
  globalTeardown: './e2e/docker-cleanup.mjs',
  // The specs share one database and build on each other's data in a fixed order.
  workers: 1,
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: `http://localhost:${port}`,
    locale: 'es-GT',
    timezoneId: 'America/Guatemala',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command,
    url: `http://localhost:${port}/api/health`,
    timeout: 180_000,
    reuseExistingServer: false,
    stdout: 'ignore',
    stderr: 'pipe',
  },
});
