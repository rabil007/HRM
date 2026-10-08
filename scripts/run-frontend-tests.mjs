#!/usr/bin/env node

/**
 * Discover and run all frontend unit tests under resources/js.
 *
 * Avoids manually maintained directory globs and does not rely on shell
 * globstar expansion (fragile across macOS / Linux / npm script shells).
 */

import { spawn } from 'node:child_process';
import { readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const searchRoot = path.join(root, 'resources', 'js');

/**
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function collectTestFiles(dir) {
    const entries = await readdir(dir, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            files.push(...(await collectTestFiles(fullPath)));
            continue;
        }

        if (entry.isFile() && entry.name.endsWith('.test.ts')) {
            files.push(fullPath);
        }
    }

    return files;
}

const testFiles = (await collectTestFiles(searchRoot)).sort((a, b) =>
    a.localeCompare(b),
);

if (testFiles.length === 0) {
    console.error(`No *.test.ts files found under ${searchRoot}`);
    process.exit(1);
}

console.error(
    `Discovered ${testFiles.length} frontend test file(s) under resources/js`,
);

const child = spawn(
    process.execPath,
    ['--experimental-strip-types', '--test', ...testFiles],
    {
        cwd: root,
        stdio: 'inherit',
        env: process.env,
    },
);

child.on('error', (error) => {
    console.error(error);
    process.exit(1);
});

child.on('exit', (code, signal) => {
    if (signal) {
        process.kill(process.pid, signal);

        return;
    }

    process.exit(code ?? 1);
});
