import { Logo, SiteFooter, ThemeToggle } from '@/components/brand';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Map, MousePointerClick, Printer, Radar, ShieldCheck } from 'lucide-react';

export default function Welcome() {
    const { brand, auth } = usePage<SharedProps>().props;
    const home = auth.user ? (auth.user.is_staff ? '/admin' : '/app') : null;
    return (
        <div className="min-h-dvh">
            <Head title="Letterbox distribution & printing" />
            <header className="mx-auto flex h-20 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <Logo withText />
                <nav className="flex items-center gap-2" aria-label="Account">
                    <ThemeToggle />
                    {home ? (
                        <Button asChild>
                            <Link href={home}>Open dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button variant="ghost" asChild>
                                <Link href="/login">Sign in</Link>
                            </Button>
                            <Button asChild>
                                <Link href="/register">Get started</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </header>

            <main>
                <section className="mx-auto max-w-7xl px-4 pt-12 pb-20 sm:px-6 lg:px-8 lg:pt-20">
                    <div className="max-w-3xl">
                        <p className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-sm text-muted shadow-xs">
                            <span className="size-1.5 rounded-full bg-brand-500" aria-hidden /> {brand.cities.join(' · ')}
                        </p>
                        <h1 className="mt-6 text-4xl font-semibold tracking-tight text-fg sm:text-6xl">
                            {brand.tagline.split(' for ')[0]}
                            <span className="text-primary"> for {brand.tagline.split(' for ')[1]}</span>
                        </h1>
                        <p className="mt-6 max-w-2xl text-lg text-muted">
                            Draw the streets you want on a map, see exactly how many houses and units you'll reach, print your flyers and book letterbox delivery — with live GPS proof of distribution.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Button size="lg" asChild>
                                <Link href={home ?? '/register'}>
                                    Plan a campaign <ArrowRight />
                                </Link>
                            </Button>
                            <Button size="lg" variant="secondary" asChild>
                                <a href="/docs">API docs</a>
                            </Button>
                        </div>
                    </div>

                    <ul className="mt-20 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            { icon: MousePointerClick, title: 'Draw your audience', body: 'Pins, freehand trace or a whole suburb. Live houses vs units counts.' },
                            { icon: Map, title: 'Built on G-NAF', body: 'Every Australian address, geocoded. Estimates you can trust.' },
                            { icon: Printer, title: 'Print + distribute', body: 'Order flyers and send them straight to your campaign.' },
                            { icon: Radar, title: 'GPS proof', body: 'Watch distributors live and download a coverage report.' },
                        ].map((f) => (
                            <li key={f.title} className="rounded-[16px] border border-border bg-surface p-6 shadow-sm">
                                <f.icon className="size-6 text-primary" aria-hidden />
                                <h2 className="mt-4 font-semibold text-fg">{f.title}</h2>
                                <p className="mt-1.5 text-sm text-muted">{f.body}</p>
                            </li>
                        ))}
                    </ul>

                    <p className="mt-10 flex items-center gap-2 text-sm text-muted">
                        <ShieldCheck className="size-4 text-success" aria-hidden /> Prices in AUD incl. GST · Secure payments · Australian-owned
                    </p>
                </section>
            </main>
            <SiteFooter />
        </div>
    );
}
