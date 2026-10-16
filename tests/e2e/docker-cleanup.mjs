// Removes the Docker container that serves the end-to-end tests (it can outlive the test run on Windows).
import { execSync } from 'node:child_process';

export default function cleanup() {
  if (process.env.E2E_SERVER === 'native') return;
  try {
    execSync('docker rm -f cantera-e2e', { stdio: 'ignore' });
  } catch {
    // Not running: nothing to clean.
  }
}
