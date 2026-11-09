import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const examples = fileURLToPath(new URL('../examples/', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'slim-session-e2e-'));
const env = {
  ...process.env,
  EXAMPLE_SECRET_BASE64: randomBytes(32).toString('base64'),
  EXAMPLE_SQLITE_PATH: join(directory, 'session.sqlite'),
  EXAMPLE_ALLOW_INSECURE_LOCALHOST: '1',
  EXAMPLE_API_ORIGIN: 'http://localhost:8787',
  EXAMPLE_WIDGET_ORIGIN: 'http://localhost:8788',
  PHP_CLI_SERVER_WORKERS: '4',
};
const setup = spawnSync('php', ['bin/setup.php'], { cwd: examples, env, stdio: 'inherit' });
if (setup.status !== 0) {
  rmSync(directory, { recursive: true, force: true });
  process.exit(setup.status ?? 1);
}
const children = [8787, 8788].map(port => spawn('php', ['-S', `localhost:${port}`, 'router.php'], {
  cwd: examples, env, stdio: 'inherit',
}));
let stopping = false;
function stop(code = 0) {
  if (stopping) return;
  stopping = true;
  for (const child of children) {
    child.kill('SIGTERM');
  }
  rmSync(directory, { recursive: true, force: true });
  process.exit(code);
}
process.on('SIGTERM', () => stop());
process.on('SIGINT', () => stop());
for (const child of children) {
  child.on('error', error => { console.error(error); stop(1); });
  child.on('exit', () => stop(1));
}
