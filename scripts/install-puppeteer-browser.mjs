import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

if (process.env.CI_SKIP_PUPPETEER_BROWSER_INSTALL === '1') {
    console.log('Skipping Puppeteer browser install (CI_SKIP_PUPPETEER_BROWSER_INSTALL=1).');
    process.exit(0);
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bin = path.join(root, 'node_modules', '.bin', process.platform === 'win32' ? 'puppeteer.cmd' : 'puppeteer');

const result = spawnSync(bin, ['browsers', 'install', 'chrome-headless-shell'], {
    cwd: root,
    env: process.env,
    stdio: 'inherit',
});

process.exit(result.status === null ? 1 : result.status);
