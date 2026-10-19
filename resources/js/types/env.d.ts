/// <reference types="vite/client" />

interface ImportMetaEnv {
    /** Browser error reporting (Sentry); unset disables it. */
    readonly VITE_SENTRY_DSN?: string;
    readonly VITE_APP_ENV?: string;
}
