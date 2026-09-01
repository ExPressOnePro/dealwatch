import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

const herdCertDir = path.join(
    os.homedir(),
    'Library/Application Support/Herd/config/valet/Certificates',
);

const keyPath = path.join(herdCertDir, 'dealwatch.test.key');
const certPath = path.join(herdCertDir, 'dealwatch.test.crt');

if (!fs.existsSync(keyPath) || !fs.existsSync(certPath)) {
    throw new Error(
        'Herd TLS certs for dealwatch.test not found. Run: herd secure dealwatch',
    );
}

const port = 5188;
const host = 'dealwatch.test';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
            detectTls: host,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    server: {
        // Bind locally; Herd/.test DNS sometimes fails inside Node.
        host: '127.0.0.1',
        port,
        strictPort: true,
        origin: `https://${host}:${port}`,
        https: {
            key: fs.readFileSync(keyPath),
            cert: fs.readFileSync(certPath),
        },
        hmr: {
            host,
            protocol: 'wss',
            clientPort: port,
        },
        cors: {
            origin: [`https://${host}`],
        },
    },
});
