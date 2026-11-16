import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import mdiSubset from './scripts/vite-mdi-subset.js';

export default defineConfig({
    plugins: [
        mdiSubset(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@design': fileURLToPath(new URL('./resources/design', import.meta.url)),
        },
    },
    server: {
        // Origen fijo `http://localhost:5173` en public/hot: la CSP de desarrollo no admite
        // fuentes con IPv6 literal (`http://[::1]:5173`), que Chrome descarta por inválidas.
        host: 'localhost',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
