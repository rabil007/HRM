import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

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
    ],
});
