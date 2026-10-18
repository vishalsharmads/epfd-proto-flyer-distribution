import { Logo, ThemeToggle } from '@/components/brand';
import { Kbd } from '@/components/ui/surface';
import { useFlashToasts } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Command } from 'cmdk';
import {
    BarChart3,
    Building2,
    Calculator,
    Database,
    FileText,
    Gauge,
    History,
    Inbox,
    LogOut,
    MapPinned,
    Menu,
    Package,
    Palette,
    Printer,
    Receipt,
    Search,
    Send,
    ShieldCheck,
    Warehouse,
    Settings2,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

type NavItem = { href: string; label: string; icon: React.ComponentType<{ className?: string }>; permission?: string };

export const adminNav: { group: string; items: NavItem[] }[] = [
    {
        group: 'Overview',
        items: [{ href: '/admin', label: 'Dashboard', icon: Gauge }],
    },
    {
        group: 'Operations',
        items: [
            { href: '/admin/businesses', label: 'Businesses', icon: Building2, permission: 'businesses.view' },
            { href: '/admin/campaigns', label: 'Distribution campaigns', icon: Send, permission: 'campaigns.view' },
            { href: '/admin/print-orders', label: 'Print orders', icon: Printer, permission: 'print_orders.view' },
            { href: '/admin/design-orders', label: 'Design orders', icon: Palette, permission: 'design_orders.view' },
            { href: '/admin/pickup-points', label: 'Pickup points', icon: Warehouse, permission: 'pickup_points.manage' },
            { href: '/admin/service-areas', label: 'Service areas', icon: MapPinned, permission: 'service_areas.manage' },
        ],
    },
    {
        group: 'Catalogue',
        items: [
            { href: '/admin/catalogue/print', label: 'Print products', icon: Printer, permission: 'catalogue.manage' },
            { href: '/admin/rate-card?tab=design', label: 'Design services', icon: Palette, permission: 'rate_card.manage' },
        ],
    },
    {
        group: 'Finance',
        items: [
            { href: '/admin/rate-card', label: 'Rate card & promos', icon: Calculator, permission: 'rate_card.manage' },
            { href: '/admin/payments', label: 'Payments & invoices', icon: Receipt, permission: 'payments.view' },
            { href: '/admin/reports', label: 'Reports', icon: BarChart3, permission: 'reports.view' },
        ],
    },
    {
        group: 'Support',
        items: [
            { href: '/admin/support', label: 'Support inbox', icon: Inbox, permission: 'support.view' },
            { href: '/admin/content', label: 'Content (FAQs, T&Cs)', icon: FileText, permission: 'content.manage' },
        ],
    },
    {
        group: 'System',
        items: [
            { href: '/admin/settings', label: 'Settings', icon: Settings2, permission: 'settings.manage' },
            { href: '/admin/gnaf', label: 'G-NAF data status', icon: Database, permission: 'gnaf.view' },
            { href: '/admin/users', label: 'Users & roles', icon: ShieldCheck, permission: 'users.manage' },
            { href: '/admin/audit-log', label: 'Audit log', icon: History, permission: 'audit.view' },
            { href: '/docs', label: 'API docs', icon: Package },
        ],
    },
];

function useCan() {
    const { auth } = usePage<SharedProps>().props;
    return (perm?: string) => !perm || auth.user?.roles.includes('super_admin') || auth.user?.permissions.includes(perm);
}

function CommandPalette({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const can = useCan();
    const go = (href: string) => {
        onOpenChange(false);
        if (href.startsWith('/docs')) window.location.href = href;
        else router.visit(href);
    };
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-overlay data-[state=open]:animate-fade-in" />
                <DialogPrimitive.Content className="fixed top-[12vh] left-1/2 z-50 w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 overflow-hidden rounded-[16px] border border-border bg-surface shadow-lg data-[state=open]:animate-slide-up">
                    <DialogPrimitive.Title className="sr-only">Command palette</DialogPrimitive.Title>
                    <DialogPrimitive.Description className="sr-only">Search admin pages and actions</DialogPrimitive.Description>
                    <Command label="Command palette" className="[&_[cmdk-group-heading]]:px-3 [&_[cmdk-group-heading]]:py-2 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-semibold [&_[cmdk-group-heading]]:text-muted">
                        <div className="flex items-center gap-2 border-b border-border px-4">
                            <Search className="size-4 text-muted" aria-hidden />
                            <Command.Input autoFocus placeholder="Jump to… (businesses, campaigns, rate card)" className="h-12 flex-1 bg-transparent text-[15px] outline-none placeholder:text-subtle" />
                            <Kbd>Esc</Kbd>
                        </div>
                        <Command.List className="max-h-[50vh] overflow-y-auto p-2">
                            <Command.Empty className="px-3 py-6 text-center text-sm text-muted">No results.</Command.Empty>
                            {adminNav.map((g) => (
                                <Command.Group key={g.group} heading={g.group}>
                                    {g.items
                                        .filter((i) => can(i.permission))
                                        .map((i) => (
                                            <Command.Item key={i.href} value={`${g.group} ${i.label}`} onSelect={() => go(i.href)} className="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm data-[selected=true]:bg-surface-2">
                                                <i.icon className="size-4 text-muted" />
                                                {i.label}
                                            </Command.Item>
                                        ))}
                                </Command.Group>
                            ))}
                            <Command.Group heading="Actions">
                                <Command.Item onSelect={() => go('/admin/businesses?status=pending')} className="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm data-[selected=true]:bg-surface-2">
                                    <Building2 className="size-4 text-muted" /> Review pending approvals
                                </Command.Item>
                                <Command.Item onSelect={() => go('/admin/campaigns?status=submitted')} className="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm data-[selected=true]:bg-surface-2">
                                    <Send className="size-4 text-muted" /> Campaigns awaiting runs
                                </Command.Item>
                                <Command.Item onSelect={() => go('/admin/print-orders?status=awaiting_artwork_approval')} className="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm data-[selected=true]:bg-surface-2">
                                    <Printer className="size-4 text-muted" /> Artwork awaiting review
                                </Command.Item>
                                {can('design_orders.view') && (
                                    <Command.Item onSelect={() => go('/admin/design-orders?status=awaiting_proof')} className="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2.5 text-sm data-[selected=true]:bg-surface-2">
                                        <Palette className="size-4 text-muted" /> Design orders awaiting a proof
                                    </Command.Item>
                                )}
                            </Command.Group>
                        </Command.List>
                    </Command>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

export default function AdminLayout({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    const url = usePage().url.split('?')[0];
    const can = useCan();
    const [palette, setPalette] = useState(false);
    const [mobileNav, setMobileNav] = useState(false);
    useFlashToasts();

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPalette((p) => !p);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const sidebar = (
        <nav aria-label="Admin" className="flex flex-col gap-6 p-4">
            {adminNav.map((g) => {
                const items = g.items.filter((i) => can(i.permission));
                if (!items.length) return null;
                return (
                    <div key={g.group}>
                        <p className="px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-subtle uppercase">{g.group}</p>
                        <ul className="space-y-0.5">
                            {items.map((i) => {
                                const active = i.href === '/admin' ? url === '/admin' : url.startsWith(i.href);
                                const cls = cn('flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm font-medium transition-colors', active ? 'bg-surface-2 text-fg' : 'text-muted hover:bg-surface-2/60 hover:text-fg');
                                return (
                                    <li key={i.href}>
                                        {i.href.startsWith('/docs') ? (
                                            <a href={i.href} className={cls}>
                                                <i.icon className="size-4" /> {i.label}
                                            </a>
                                        ) : (
                                            <Link href={i.href} className={cls} aria-current={active ? 'page' : undefined} onClick={() => setMobileNav(false)}>
                                                <i.icon className="size-4" /> {i.label}
                                            </Link>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );

    return (
        <div className="min-h-dvh lg:grid lg:grid-cols-[260px_1fr]">
            <Head title={`${title} · Admin`} />
            <aside className="sticky top-0 hidden h-dvh flex-col overflow-y-auto border-r border-border bg-surface lg:flex">
                <div className="flex h-16 items-center gap-2 border-b border-border px-5">
                    <Logo />
                    <span className="rounded-md bg-ink-900 px-1.5 py-0.5 text-[10px] font-bold tracking-wider text-white uppercase dark:bg-white dark:text-ink-900">Admin</span>
                </div>
                {sidebar}
            </aside>
            {mobileNav && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <button className="absolute inset-0 bg-overlay" aria-label="Close menu" onClick={() => setMobileNav(false)} />
                    <aside className="relative h-full w-72 overflow-y-auto bg-surface shadow-lg">{sidebar}</aside>
                </div>
            )}
            <div className="flex min-w-0 flex-col">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface/85 px-4 backdrop-blur-md sm:px-6">
                    <button className="grid size-9 place-items-center rounded-full hover:bg-surface-2 lg:hidden" aria-label="Open menu" onClick={() => setMobileNav(true)}>
                        <Menu className="size-5" />
                    </button>
                    <button type="button" onClick={() => setPalette(true)} className="flex h-9 w-full max-w-sm items-center gap-2 rounded-full border border-border bg-surface-2/60 px-3 text-sm text-subtle hover:border-border-strong">
                        <Search className="size-4" aria-hidden />
                        <span className="flex-1 text-left">Search or jump to…</span>
                        <Kbd>⌘K</Kbd>
                    </button>
                    <div className="ml-auto flex items-center gap-2">
                        <span className="hidden text-sm text-muted sm:inline">
                            {auth.user?.name} · <span className="capitalize">{auth.user?.roles[0]?.replace('_', ' ')}</span>
                        </span>
                        <ThemeToggle />
                        <button type="button" onClick={() => router.post('/logout')} className="grid size-9 place-items-center rounded-full text-muted hover:bg-surface-2 hover:text-fg" aria-label="Log out">
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </header>
                <main id="main" className="mx-auto w-full max-w-[1400px] flex-1 px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                    </div>
                    {children}
                </main>
            </div>
            <CommandPalette open={palette} onOpenChange={setPalette} />
        </div>
    );
}
