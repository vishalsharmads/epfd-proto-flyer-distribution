import { Logo, SiteFooter, ThemeToggle } from '@/components/brand';
import { Dropdown, DropdownContent, DropdownItem, DropdownLabel, DropdownSeparator, DropdownTrigger } from '@/components/ui/overlay';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Briefcase, ChevronDown, CreditCard, LayoutDashboard, LifeBuoy, LogOut, Palette, Printer, Send, Settings, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

const nav = [
    { href: '/app', label: 'Dashboard', icon: LayoutDashboard, match: /^\/app\/?$/ },
    { href: '/app/print', label: 'Print Store', icon: Printer, match: /^\/app\/print/ },
    { href: '/app/design', label: 'Design Studio', icon: Palette, match: /^\/app\/design/ },
    { href: '/app/distribution', label: 'Letterbox Distribution', icon: Send, match: /^\/app\/(distribution|campaigns)/ },
    { href: '/app/jobs', label: 'My Jobs', icon: Briefcase, match: /^\/app\/jobs/ },
];

export function useFlashToasts() {
    const { flash } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);
}

export default function AppLayout({ title, children, fullBleed = false, hideFooter = false }: { title?: string; children: ReactNode; fullBleed?: boolean; hideFooter?: boolean }) {
    const { auth, url } = { ...usePage<SharedProps>().props, url: usePage().url };
    const [bannerHidden, setBannerHidden] = useState(false);
    useFlashToasts();
    const user = auth.user;
    const business = auth.business;
    const initials = (user?.name ?? '?')
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <div className="flex min-h-dvh flex-col">
            {title && <Head title={title} />}
            <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:rounded-md focus:bg-surface focus:px-4 focus:py-2 focus:shadow-lg">
                Skip to content
            </a>

            {business && business.status !== 'approved' && !bannerHidden && (
                <div className={cn('flex items-center gap-3 px-4 py-2.5 text-sm sm:px-6', business.status === 'suspended' ? 'bg-danger-soft text-danger' : 'bg-accent-50 text-accent-800 dark:bg-accent-900/30 dark:text-accent-200')} role="status">
                    <AlertTriangle className="size-4 shrink-0" aria-hidden />
                    <p className="flex-1">
                        {business.status === 'suspended' ? (
                            <>
                                <strong>Account suspended.</strong> Contact support to restore payments.
                            </>
                        ) : (
                            <>
                                <strong>Awaiting approval.</strong> You can build drafts now{business.can_pay ? '' : '; payment unlocks once an EPFD admin approves your business'}.
                            </>
                        )}
                    </p>
                    <button type="button" onClick={() => setBannerHidden(true)} aria-label="Dismiss" className="rounded-full p-1 hover:bg-black/5">
                        <X className="size-4" />
                    </button>
                </div>
            )}

            <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
                <div className="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
                    <Link href="/app" className="shrink-0" aria-label="EPFD home">
                        <Logo />
                    </Link>
                    <nav aria-label="Main" className="hidden flex-1 items-center gap-1 md:flex">
                        {nav.map((n) => {
                            const active = n.match.test(url.split('?')[0]);
                            return (
                                <Link key={n.href} href={n.href} aria-current={active ? 'page' : undefined} className={cn('inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium transition-colors', active ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg')}>
                                    <n.icon className="size-4" aria-hidden />
                                    {n.label}
                                </Link>
                            );
                        })}
                    </nav>
                    <div className="ml-auto flex items-center gap-1">
                        <ThemeToggle />
                        {user && (
                            <Dropdown>
                                <DropdownTrigger className="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 hover:bg-surface-2" aria-label="Account menu">
                                    <span className="grid size-8 place-items-center rounded-full bg-accent-400 text-xs font-bold text-ink-900">{initials}</span>
                                    <span className="hidden text-sm font-medium sm:inline">{user.name.split(' ')[0]}</span>
                                    <ChevronDown className="size-4 text-muted" />
                                </DropdownTrigger>
                                <DropdownContent>
                                    <DropdownLabel>
                                        <span className="block font-medium text-fg">{business?.name}</span>
                                        {user.email}
                                    </DropdownLabel>
                                    <DropdownSeparator />
                                    <DropdownItem onSelect={() => router.visit('/app/account')}>
                                        <Settings /> Account
                                    </DropdownItem>
                                    <DropdownItem onSelect={() => router.visit('/app/account?tab=payments')}>
                                        <CreditCard /> Payments & invoices
                                    </DropdownItem>
                                    <DropdownItem onSelect={() => router.visit('/app/support')}>
                                        <LifeBuoy /> Support
                                    </DropdownItem>
                                    <DropdownSeparator />
                                    <DropdownItem onSelect={() => router.post('/logout')}>
                                        <LogOut /> Log out
                                    </DropdownItem>
                                </DropdownContent>
                            </Dropdown>
                        )}
                    </div>
                </div>
                {/* Mobile nav */}
                <nav aria-label="Main (mobile)" className="flex gap-1 overflow-x-auto border-t border-border px-2 py-1.5 md:hidden">
                    {nav.map((n) => {
                        const active = n.match.test(url.split('?')[0]);
                        return (
                            <Link key={n.href} href={n.href} aria-current={active ? 'page' : undefined} className={cn('inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium', active ? 'bg-surface-2 text-fg' : 'text-muted')}>
                                <n.icon className="size-3.5" aria-hidden />
                                {n.label.replace('Letterbox ', '')}
                            </Link>
                        );
                    })}
                </nav>
            </header>

            <main id="main" className={cn('flex-1', !fullBleed && 'mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10')}>
                {children}
            </main>
            {!hideFooter && <SiteFooter />}
        </div>
    );
}
