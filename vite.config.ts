import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    build: {
        rollupOptions: {
            output: {
                // The map engine is heavy: keep it in its own lazily-loaded chunk.
                manualChunks: (id) => (id.includes('maplibre-gl') ? 'maplibre' : undefined),
            },
        },
    },
    server: {
        host: '127.0.0.1',
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
