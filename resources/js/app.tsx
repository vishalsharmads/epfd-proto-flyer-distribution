import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

// Browser error reporting, only when VITE_SENTRY_DSN is set at build time (loaded lazily to keep the bundle small).
if (import.meta.env.VITE_SENTRY_DSN) {
    import('@sentry/react').then((Sentry) =>
        Sentry.init({
            dsn: import.meta.env.VITE_SENTRY_DSN,
            environment: import.meta.env.VITE_APP_ENV ?? import.meta.env.MODE,
        }),
    );
}

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
