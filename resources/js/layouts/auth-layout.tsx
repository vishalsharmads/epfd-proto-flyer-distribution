import { Logo, ThemeToggle } from '@/components/brand';
import { useFlashToasts } from '@/layouts/app-layout';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import type { ReactNode } from 'react';

export default function AuthLayout({ title, subtitle, children, wide = false }: { title: string; subtitle?: ReactNode; children: ReactNode; wide?: boolean }) {
    const { brand } = usePage<SharedProps>().props;
    useFlashToasts();
    return (
        <div className="grid min-h-dvh lg:grid-cols-[1fr_minmax(420px,520px)]">
            <Head title={title} />
            <aside className="relative hidden overflow-hidden bg-ink-900 p-12 text-white lg:flex lg:flex-col">
                <div className="absolute -top-40 -right-40 size-[520px] rounded-full bg-brand-500/25 blur-3xl" aria-hidden />
                <div className="absolute -bottom-48 -left-24 size-[420px] rounded-full bg-accent-400/15 blur-3xl" aria-hidden />
                <div className="relative">
                    <span className="inline-block rounded-[14px] bg-white px-3 py-2">
                        <Logo />
                    </span>
                </div>
                <div className="relative mt-auto max-w-lg">
                    <h2 className="text-4xl leading-tight font-semibold tracking-tight">{brand.tagline}</h2>
                    <ul className="mt-8 space-y-3 text-white/80">
                        {['Draw your area — see houses, units and price instantly', 'Print and letterbox in one checkout', 'Live GPS proof of distribution'].map((t) => (
                            <li key={t} className="flex items-center gap-3">
                                <CheckCircle2 className="size-5 text-brand-400" aria-hidden /> {t}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-10 text-sm text-white/50">
                        {brand.cities.join(' · ')} · {brand.phone}
                    </p>
                </div>
            </aside>
            <main className="flex flex-col px-5 py-8 sm:px-10">
                <div className="flex items-center justify-between">
                    <Link href="/" className="lg:invisible" aria-label="EPFD home">
                        <Logo />
                    </Link>
                    <ThemeToggle />
                </div>
                <div className={`mx-auto my-auto w-full py-10 ${wide ? 'max-w-xl' : 'max-w-sm'}`}>
                    <h1 className="text-2xl font-semibold tracking-tight text-fg">{title}</h1>
                    {subtitle && <p className="mt-2 text-muted">{subtitle}</p>}
                    <div className="mt-8">{children}</div>
                </div>
            </main>
        </div>
    );
}
