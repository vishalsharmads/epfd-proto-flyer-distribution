import { Markdown } from '@/components/app/misc';
import { Logo, SiteFooter, ThemeToggle } from '@/components/brand';
import { cn, date } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function LegalShow({ page, others }: { page: { slug: string; title: string; body: string; updated_at: string }; others: { slug: string; title: string }[] }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <div className="flex min-h-dvh flex-col">
            <Head title={page.title} />
            <header className="border-b border-border bg-surface">
                <div className="mx-auto flex h-16 max-w-5xl items-center gap-4 px-4 sm:px-6">
                    <Link href={auth.user ? '/app' : '/'} aria-label="EPFD home">
                        <Logo />
                    </Link>
                    <div className="ml-auto flex items-center gap-2">
                        <ThemeToggle />
                        <Link href={auth.user ? '/app' : '/login'} className="text-sm font-medium text-primary hover:underline">
                            {auth.user ? 'Back to portal' : 'Sign in'}
                        </Link>
                    </div>
                </div>
            </header>
            <main className="mx-auto grid w-full max-w-5xl flex-1 gap-10 px-4 py-10 sm:px-6 md:grid-cols-[200px_1fr]">
                <nav aria-label="Legal" className="flex gap-2 overflow-x-auto md:flex-col">
                    {others.map((o) => (
                        <Link key={o.slug} href={`/legal/${o.slug}`} aria-current={o.slug === page.slug ? 'page' : undefined} className={cn('shrink-0 rounded-[10px] px-3 py-2 text-sm', o.slug === page.slug ? 'bg-surface-2 font-medium text-fg' : 'text-muted hover:text-fg')}>
                            {o.title}
                        </Link>
                    ))}
                </nav>
                <article className="max-w-prose">
                    <h1 className="text-3xl font-semibold tracking-tight">{page.title}</h1>
                    <p className="mt-2 mb-8 text-sm text-muted">Last updated {date(page.updated_at)}</p>
                    <Markdown source={page.body} />
                </article>
            </main>
            <SiteFooter />
        </div>
    );
}
