import { DraftCard } from '@/components/app/draft-card';
import { printStepUrl, ProductArt } from '@/components/app/print-bits';
import type { PrintOrderT } from '@/components/app/types';
import { ago, PRINT_STEPS, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { History, Plus, Sparkles } from 'lucide-react';
import { useState } from 'react';

export default function PrintStart({ drafts, past }: { drafts: PrintOrderT[]; past: PrintOrderT[] }) {
    const [busy, setBusy] = useState(false);
    const startNew = () => router.post('/app/print', {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const media = (o: PrintOrderT) => (o.product ? <ProductArt product={o.product} className="size-full" /> : <div className="size-full bg-surface-2" />);
    return (
        <AppLayout title="New print order" fullBleed hideFooter>
            <WizardShell steps={PRINT_STEPS} current={0} back={() => router.visit('/app/print')} next={startNew} nextLabel="Start new" nextLoading={busy}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Start a print order</h1>
                <p className="mt-1.5 text-muted">Continue a draft, re-order something you've printed before, or start fresh.</p>
                <button type="button" onClick={startNew} data-testid="start-new-print" className="mt-8 flex w-full items-center gap-4 rounded-[16px] border-2 border-dashed border-border-strong p-5 text-left transition-colors hover:border-fg hover:bg-surface-2">
                    <span className="grid size-12 place-items-center rounded-full bg-primary text-primary-fg">
                        <Plus className="size-6" />
                    </span>
                    <span>
                        <span className="block font-semibold">Start new</span>
                        <span className="text-sm text-muted">Choose a product, paper and quantity, then upload artwork.</span>
                    </span>
                </button>
                <section className="mt-10">
                    <h2 className="mb-4 flex items-center gap-2 font-semibold">
                        <Sparkles className="size-4 text-muted" aria-hidden /> Continue a draft
                    </h2>
                    {drafts.length === 0 ? (
                        <EmptyState title="No drafts" description="Print drafts save automatically." className="py-8" action={<Button size="sm" onClick={startNew}>Start new</Button>} />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {drafts.map((o) => (
                                <DraftCard key={o.id} media={media(o)} title={o.name} meta={<>{money(o.total_cents)} · edited {ago(o.updated_at)}</>} onOpen={() => router.visit(printStepUrl(o))} deleteUrl={`/app/print/${o.id}`} />
                            ))}
                        </div>
                    )}
                </section>
                <section className="mt-10">
                    <h2 className="mb-4 flex items-center gap-2 font-semibold">
                        <History className="size-4 text-muted" aria-hidden /> Re-order
                    </h2>
                    {past.length === 0 ? (
                        <EmptyState title="Nothing to re-order yet" description="Your submitted print orders will appear here." className="py-8" />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {past.map((o) => (
                                <DraftCard
                                    key={o.id}
                                    media={media(o)}
                                    title={o.name}
                                    meta={<>{money(o.total_cents)} · {date(o.submitted_at)}</>}
                                    footer={
                                        <span className="flex items-center justify-between">
                                            <StatusBadge status={o.status} />
                                            <span className="text-sm font-semibold text-primary">Re-order</span>
                                        </span>
                                    }
                                    onOpen={() => router.post(`/app/print/${o.id}/reorder`)}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </WizardShell>
        </AppLayout>
    );
}
