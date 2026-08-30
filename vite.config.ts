import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    server: {
        // Bind locally; expose assets via the Herd hostname so the TLS cert CN matches.
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
        // Herd cert SANs are oms-hrm.test / *.oms-hrm.test — not 127.0.0.1.
        // Using 127.0.0.1 in public/hot causes ERR_CERT_COMMON_NAME_INVALID.
        hmr: {
            host: 'oms-hrm.test',
            port: 5173,
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            detectTls: 'oms-hrm.test',
        }),
        inertia({
            // Keep in sync with config/inertia.php — see SSR note there.
            ssr: false,
        }),
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
            /**
             * App registers /sw.js (Laravel-served with Service-Worker-Allowed)
             * so the worker can control the whole origin, not only /build/.
             */
            injectRegister: false,
            scope: '/',
            /**
             * We manage /public/manifest.json ourselves so Vite doesn't
             * generate a second one or inject its own link tag.
             */
            manifest: false,
            workbox: {
                /**
                 * Attach announcement push handlers without replacing the
                 * VitePWA-generated worker or adding offline caching here.
                 */
                importScripts: ['/service-worker.js'],
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
