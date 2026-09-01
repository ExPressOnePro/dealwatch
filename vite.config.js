import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
            // Avoid binding Vite to dealwatch.test — Node often can't resolve Herd's .test DNS.
            detectTls: false,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    server: {
        host: '127.0.0.1',
        port: 5188,
        strictPort: false,
        origin: 'http://127.0.0.1:5188',
        cors: {
            origin: ['https://dealwatch.test', 'http://dealwatch.test', 'http://127.0.0.1:8000', 'http://127.0.0.1:8001'],
        },
        hmr: {
            host: '127.0.0.1',
            protocol: 'ws',
        },
    },
});
