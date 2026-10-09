import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '../../../..');
const jsRoot = join(here, '../..');

function readRepoFile(relativePath: string): string {
    return readFileSync(join(repoRoot, relativePath), 'utf8');
}

/**
 * Regression for production blank dashboard after #201:
 * AppRefreshSync calls usePage(), so it must mount inside Inertia's page tree
 * (AppLayout), never as a withApp sibling of {app}.
 */
describe('AppRefreshSync mount location', () => {
    it('is mounted from AppLayout, not from createInertiaApp withApp', () => {
        const appEntry = readRepoFile('resources/js/app.tsx');
        const appLayout = readRepoFile('resources/js/layouts/app-layout.tsx');

        assert.match(
            appLayout,
            /<AppRefreshSync\s+versionUrl=\{appVersion\.url\(\)\}/,
            'AppRefreshSync must render inside AppLayout (Inertia page tree)',
        );

        assert.doesNotMatch(
            appEntry,
            /AppRefreshSync/,
            'AppRefreshSync must not be imported or rendered from app.tsx withApp — that is outside usePage context',
        );

        assert.match(
            appEntry,
            /withApp\(app\)\s*\{[\s\S]*\{app\}/,
            'withApp must still wrap the Inertia {app} element',
        );
    });

    it('throws the Inertia usePage error when rendered outside page context', async () => {
        const vite = await createServer({
            configFile: false,
            plugins: [(await import('@vitejs/plugin-react')).default()],
            resolve: {
                alias: {
                    '@': jsRoot,
                },
            },
        });

        try {
            const { AppRefreshSync } = await vite.ssrLoadModule(
                './resources/js/components/app-refresh-sync.tsx',
            );

            assert.throws(
                () =>
                    renderToString(
                        React.createElement(AppRefreshSync, {
                            versionUrl: '/app/version',
                        }),
                    ),
                (error: unknown) =>
                    error instanceof Error &&
                    error.message.includes(
                        'usePage must be used within the Inertia component',
                    ),
            );
        } finally {
            await vite.close();
        }
    });
});
