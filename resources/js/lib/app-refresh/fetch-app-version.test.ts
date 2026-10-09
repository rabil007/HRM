import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import { fetchAppVersion } from './fetch-app-version.ts';

describe('fetchAppVersion', () => {
    const originalFetch = globalThis.fetch;

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    it('requests the version endpoint with the client version query', async () => {
        let requestedUrl = '';

        globalThis.fetch = async (input) => {
            requestedUrl = String(input);

            return new Response(
                JSON.stringify({
                    version: 'deploy-2',
                    update_available: true,
                    authorization_revision: '1.1',
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            );
        };

        const payload = await fetchAppVersion('deploy-1', '/app/version');

        assert.equal(payload.version, 'deploy-2');
        assert.equal(payload.update_available, true);
        assert.equal(requestedUrl, '/app/version?client_version=deploy-1');
    });

    it('throws when the version endpoint fails', async () => {
        globalThis.fetch = async () =>
            new Response('nope', {
                status: 500,
            });

        await assert.rejects(
            () => fetchAppVersion('deploy-1', '/app/version'),
            /Version check failed \(500\)/,
        );
    });
});
