import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { App } from '@inertiajs/react';
import { createElement } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { build } from 'vite';

const root = fileURLToPath(new URL('../../../../../../', import.meta.url));
const temporaryDirectory = mkdtempSync(
    path.join(tmpdir(), 'documents-workspace-'),
);
let Layout: ComponentType<{ children?: ReactNode }>;
let Library: ComponentType<typeof libraryProps>;

const libraryProps = {
    summary: {
        total_documents: 1,
        expired: 0,
        expiring_30: 0,
        expiring_15: 0,
        expiring_7: 0,
    },
    expiry: 'all',
    search: '',
    employees: [
        {
            employee_id: 12,
            employee_name: 'Library Employee',
            employee_no: 'EMP-012',
            document_count: 1,
        },
    ],
    searchDocuments: null,
    complianceDocuments: null,
    document_types: [],
    countries: [],
    can: {
        download: false,
        share: false,
        upload: false,
        delete: false,
        whatsapp_template: false,
        whatsapp_templates: [],
        email_templates: [],
    },
};

before(async () => {
    const entry = path.join(temporaryDirectory, 'workspace.ts');
    writeFileSync(
        entry,
        [
            `export { default as Layout } from ${JSON.stringify(path.join(root, 'resources/js/layouts/documents-layout.tsx'))};`,
            `export { default as Library } from ${JSON.stringify(path.join(root, 'resources/js/pages/organization/documents/index.tsx'))};`,
        ].join('\n'),
    );
    await build({
        root,
        configFile: false,
        publicDir: false,
        logLevel: 'silent',
        resolve: { alias: { '@': path.join(root, 'resources/js') } },
        build: {
            ssr: entry,
            outDir: path.join(temporaryDirectory, 'dist'),
            minify: false,
        },
    });
    symlinkSync(
        path.join(root, 'node_modules'),
        path.join(temporaryDirectory, 'node_modules'),
        'dir',
    );
    ({ Layout, Library } = await import(
        pathToFileURL(path.join(temporaryDirectory, 'dist/workspace.js')).href
    ));
});

after(() => rmSync(temporaryDirectory, { recursive: true, force: true }));

function renderWorkspace(
    permissions: string[],
    url = '/organization/documents/library',
    props?: typeof libraryProps,
): string {
    return renderToStaticMarkup(
        createElement(App, {
            initialPage: {
                component: 'organization/documents/index',
                url,
                version: null,
                clearHistory: false,
                encryptHistory: false,
                props: { errors: {}, auth: { permissions } },
            },
            initialComponent: () =>
                createElement(
                    Layout,
                    null,
                    props ? createElement(Library, props) : null,
                ),
        }),
    );
}

describe('rendered Documents workspace', () => {
    it('shows only permitted destinations and marks the library active on file pages', () => {
        const html = renderWorkspace(
            ['documents.view'],
            '/organization/documents/employees/12/files/3',
        );
        assert.match(html, /aria-label="Documents workspace"/);
        assert.match(html, /href="\/organization\/documents"/);
        assert.match(
            html,
            /<a[^>]*aria-current="page"[^>]*href="\/organization\/documents\/library"/,
        );
        assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
        assert.doesNotMatch(
            html,
            /href="\/organization\/documents\/(generate|requests|templates|configuration|activity)"/,
        );
    });

    it('lets respond-only users navigate to their tasks without exposing other sections', () => {
        const html = renderWorkspace(
            ['documents.recipient-requests.respond'],
            '/organization/documents/recipient-requests/8/respond',
        );
        assert.match(
            html,
            /aria-current="page"[^>]*href="\/organization\/documents\/requests"/,
        );
        assert.equal((html.match(/<a /g) ?? []).length, 1);
        assert.doesNotMatch(renderWorkspace([]), /<a /);
    });

    it('renders employee folders, labelled search, saved views and all summary filters', () => {
        const html = renderWorkspace(
            ['documents.view'],
            undefined,
            libraryProps,
        );
        assert.match(html, /Document Library/);
        assert.match(html, /aria-label="Search documents and employees"/);
        assert.match(html, /Saved views/i);
        assert.match(html, /href="\/organization\/documents\/employees\/12"/);
        assert.match(html, /aria-label="Select Library Employee"/);
        assert.match(html, /EMP-012/);
        assert.match(html, /1 file/);
        assert.equal(
            (html.match(/aria-pressed="(?:true|false)"/g) ?? []).length,
            6,
        );
        assert.doesNotMatch(
            html,
            /Export|Download ZIP|Share links|view or upload/,
        );
    });

    it('keeps upload guidance and export controls permission-aware', () => {
        const html = renderWorkspace(['documents.view'], undefined, {
            ...libraryProps,
            can: { ...libraryProps.can, upload: true, download: true },
        });
        assert.match(html, /view or upload documents/);
        assert.match(html, /Export/);
    });

    it('renders filtered empty results without showing unrelated employee folders', () => {
        const html = renderWorkspace(['documents.view'], undefined, {
            ...libraryProps,
            expiry: 'expired',
            search: 'passport',
        });
        assert.match(html, /aria-label="Clear expiry filter"/);
        assert.match(html, /aria-label="Clear search"/);
        assert.doesNotMatch(
            html,
            /href="\/organization\/documents\/employees\/12"/,
        );
    });
});
