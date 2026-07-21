import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { cpSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

const pdfWorkerSource = resolve('node_modules/pdfjs-dist/build/pdf.worker.min.mjs');
const pdfWorkerDest = resolve('public/pdf.worker.min.js');

function copyPdfWorker(): Plugin {
    const copy = (): void => {
        cpSync(pdfWorkerSource, pdfWorkerDest);
    };

    return {
        name: 'copy-pdf-worker',
        buildStart() {
            copy();
        },
        configureServer() {
            copy();
        },
    };
}

export default defineConfig({
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        // Allow Herd/Valet .test origins to load assets from the dev server (port 5173).
        cors: {
            origin: [
                /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                /^https?:\/\/.*\.test(:\d+)?$/,
            ],
        },
        hmr: {
            host: '127.0.0.1',
            port: 5173,
            protocol: 'ws',
        },
    },
    plugins: [
        copyPdfWorker(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        VitePWA({
            strategies: 'generateSW',
            registerType: 'autoUpdate',
            injectRegister: 'script',
            /**
             * We manage /public/manifest.json ourselves so Vite doesn't
             * generate a second one or inject its own link tag.
             */
            manifest: false,
            workbox: {
                /**
                 * Only cache compiled static assets — never cache HTML or
                 * Inertia JSON responses (they must always come from the server).
                 */
                globPatterns: ['**/*.{js,css,woff,woff2,ttf,otf,eot,png,svg,ico,webp}'],
                /**
                 * CRITICAL for Inertia: do NOT intercept navigation requests.
                 * Setting navigateFallback to null means the SW passes all
                 * page navigations straight through to the Laravel server.
                 */
                navigateFallback: null,
                /**
                 * Exclude /offline.html from being treated as a navigation
                 * fallback URL (we serve it as a static file directly).
                 */
                navigateFallbackDenylist: [/^\/offline\.html/],
                runtimeCaching: [
                    {
                        /**
                         * Cache images with StaleWhileRevalidate so they load
                         * instantly from cache while being refreshed in background.
                         */
                        urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp|ico)$/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'oms-hrm-images',
                            expiration: {
                                maxEntries: 60,
                                maxAgeSeconds: 30 * 24 * 60 * 60, // 30 days
                            },
                        },
                    },
                    {
                        /**
                         * Cache fonts with CacheFirst — fonts never change
                         * once loaded, so serve from cache indefinitely.
                         */
                        urlPattern: /\.(?:woff|woff2|ttf|otf|eot)$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'oms-hrm-fonts',
                            expiration: {
                                maxEntries: 10,
                                maxAgeSeconds: 365 * 24 * 60 * 60, // 1 year
                            },
                        },
                    },
                ],
            },
        }),
    ],
});
