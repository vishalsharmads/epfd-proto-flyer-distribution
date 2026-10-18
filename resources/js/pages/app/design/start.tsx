import { DesignArt, designStepUrl, DESIGN_STEPS, fromLabel } from '@/components/app/design-bits';
import { DraftCard } from '@/components/app/draft-card';
import type { DesignCatalogue, DesignOrderT, DesignType } from '@/components/app/types';
import { ago, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Plus, Sparkles } from 'lucide-react';
import { useState } from 'react';

export default function DesignStart({ drafts, catalogue }: { drafts: DesignOrderT[]; catalogue: DesignCatalogue }) {
    const [busy, setBusy] = useState(false);
    const start = (type?: DesignType) => router.post('/app/design', type ? { design_type: type } : {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    return (
        <AppLayout title="New design" fullBleed hideFooter>
            <WizardShell steps={DESIGN_STEPS} current={0} back={() => router.visit('/app/design')} next={() => start()} nextLabel="Start new" nextLoading={busy}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Start a design</h1>
                <p className="mt-1.5 text-muted">Continue a saved brief or choose what you'd like designed.</p>
                <button type="button" onClick={() => start()} disabled={busy} data-testid="start-new-design" className="mt-8 flex w-full items-center gap-4 rounded-[16px] border-2 border-dashed border-border-strong p-5 text-left transition-colors hover:border-fg hover:bg-surface-2">
                    <span className="grid size-12 place-items-center rounded-full bg-primary text-primary-fg">
                        <Plus className="size-6" />
                    </span>
                    <span>
                        <span className="block font-semibold">Start new</span>
                        <span className="text-sm text-muted">Pick a service and package, write a short brief, then pay — proofs arrive by email.</span>
                    </span>
                </button>
                <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {catalogue.types.map((t) => (
                        <button key={t.value} type="button" disabled={busy} onClick={() => start(t.value)} className="overflow-hidden rounded-[14px] border border-border bg-surface text-left text-sm transition-shadow hover:shadow-md focus-visible:outline-2">
                            <DesignArt icon={t.icon} className="aspect-[16/9]" />
                            <span className="block px-3 pt-2 font-medium">{t.label}</span>
                            <span className="block px-3 pb-3 text-xs text-muted">{fromLabel(t.from_cents)}</span>
                        </button>
                    ))}
                </div>
                <section className="mt-10">
                    <h2 className="mb-4 flex items-center gap-2 font-semibold">
                        <Sparkles className="size-4 text-muted" aria-hidden /> Continue a draft
                    </h2>
                    {drafts.length === 0 ? (
                        <EmptyState title="No drafts" description="Design briefs save automatically as you type." className="py-8" action={<Button size="sm" onClick={() => start()}>Start new</Button>} />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {drafts.map((o) => (
                                <DraftCard key={o.id} media={<DesignArt icon={o.type_icon} className="size-full" />} title={o.name} meta={<>{money(o.total_cents)} · edited {ago(o.updated_at)}</>} onOpen={() => router.visit(designStepUrl(o))} deleteUrl={`/app/design/${o.id}`} />
                            ))}
                        </div>
                    )}
                </section>
            </WizardShell>
        </AppLayout>
    );
}
