import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

export function Logo({ className, withText = false }: { className?: string; withText?: boolean }) {
    return (
        <span className={cn('inline-flex items-center gap-2.5', className)}>
            <img src="/brand/logo.png" alt="Easy Peasy Flyer Distribution" className="h-8 w-auto dark:brightness-110" width={54} height={32} />
            {withText && <span className="hidden text-sm font-semibold tracking-tight text-fg sm:inline">Flyer Distribution</span>}
        </span>
    );
}

export function ThemeToggle({ className }: { className?: string }) {
    const [dark, setDark] = useState(false);
    useEffect(() => setDark(document.documentElement.classList.contains('dark')), []);
    const toggle = () => {
        const next = !dark;
        setDark(next);
        document.documentElement.classList.toggle('dark', next);
        try {
            localStorage.setItem('epfd-theme', next ? 'dark' : 'light');
        } catch {
            /* storage unavailable */
        }
    };
    return (
        <button type="button" onClick={toggle} className={cn('grid size-9 place-items-center rounded-full text-muted hover:bg-surface-2 hover:text-fg', className)} aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}>
            {dark ? <Sun className="size-4" /> : <Moon className="size-4" />}
        </button>
    );
}

/** G-NAF licence + attribution (shown in map footer, API docs and admin data page). */
export function GnafAttribution({ className }: { className?: string }) {
    const { gnaf } = usePage<SharedProps>().props;
    return (
        <p className={cn('text-[11px] leading-snug text-subtle', className)}>
            {gnaf.attribution}{' '}
            <a href={gnaf.licence_url} target="_blank" rel="noreferrer" className="underline underline-offset-2 hover:text-fg">
                Licence
            </a>
            {gnaf.release && <span> · Release {gnaf.release}</span>}
        </p>
    );
}

export function SiteFooter() {
    const { brand } = usePage<SharedProps>().props;
    return (
        <footer className="mt-16 border-t border-border bg-surface">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:justify-between lg:px-8">
                <div className="max-w-sm">
                    <Logo />
                    <p className="mt-3 text-sm font-medium text-fg">{brand.tagline}</p>
                    <p className="mt-1 text-sm text-muted">Serving {brand.cities.join(', ')}.</p>
                </div>
                <address className="text-sm text-muted not-italic">
                    <p className="font-semibold text-fg">Contact</p>
                    <p className="mt-2">
                        <a href={`tel:${brand.phone.replace(/\s/g, '')}`} className="hover:text-fg">
                            {brand.phone}
                        </a>
                    </p>
                    <p>
                        <a href={`mailto:${brand.email}`} className="hover:text-fg">
                            {brand.email}
                        </a>
                    </p>
                    <p>{brand.address}</p>
                </address>
            </div>
            <div className="border-t border-border">
                <p className="mx-auto max-w-7xl px-4 py-4 text-xs text-subtle sm:px-6 lg:px-8">
                    © {new Date().getFullYear()} {brand.name}. All prices in AUD and include GST unless stated.
                </p>
            </div>
        </footer>
    );
}
