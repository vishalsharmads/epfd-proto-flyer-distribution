import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

createInertiaApp({
    title: (title) => (title ? `${title} · EPFD` : 'EPFD — Smart Distribution for Smarter Marketing'),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(
            <>
                <App {...props} />
                <Toaster position="bottom-right" richColors closeButton />
            </>,
        );
    },
    progress: { color: '#e00b33', delay: 150 },
});
