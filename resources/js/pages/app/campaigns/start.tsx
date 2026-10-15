import { DraftCard } from '@/components/app/draft-card';
import { MapThumb } from '@/components/app/map';
import type { CampaignCard } from '@/components/app/types';
import { ago, CAMPAIGN_STEPS, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money, num } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { History, Plus, Sparkles } from 'lucide-react';
import { useState } from 'react';



export default function CampaignStart({ drafts, past }: { drafts: CampaignCard[]; past: CampaignCard[] }) {
    const [busy, setBusy] = useState<string | null>(null);
    const startNew = () => router.post('/app/campaigns', {}, { onStart: () => setBusy('new'), onFinish: () => setBusy(null) });
    const resume = (c: CampaignCard) => router.visit(`/app/campaigns/${c.id}/${c.step >= 4 ? 'confirm' : c.step >= 3 ? 'details' : 'audience'}`);

    return (
        <AppLayout title="New campaign" fullBleed hideFooter>
            <WizardShell steps={CAMPAIGN_STEPS} current={0} back={() => router.visit('/app/distribution')} next={startNew} nextLabel="Start new" nextLoading={busy === 'new'}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Start a campaign</h1>
                <p className="mt-1.5 text-muted">Continue where you left off, re-run a past campaign or start fresh.</p>

                <button type="button" onClick={startNew} data-testid="start-new-campaign" className="mt-8 flex w-full items-center gap-4 rounded-[16px] border-2 border-dashed border-border-strong p-5 text-left transition-colors hover:border-fg hover:bg-surface-2">
                    <span className="grid size-12 place-items-center rounded-full bg-primary text-primary-fg">
                        <Plus className="size-6" />
                    </span>
                    <span>
                        <span className="block font-semibold">Start new</span>
                        <span className="text-sm text-muted">Draw areas on the map and see letterboxes and price instantly.</span>
                    </span>
                </button>

                <section className="mt-10" aria-labelledby="drafts-h">
                    <h2 id="drafts-h" className="mb-4 flex items-center gap-2 font-semibold">
                        <Sparkles className="size-4 text-muted" aria-hidden /> Continue a draft
                    </h2>
                    {drafts.length === 0 ? (
                        <EmptyState title="No drafts" description="Drafts save automatically as you build a campaign." className="py-8" action={<Button size="sm" onClick={startNew}>Start new</Button>} />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {drafts.map((c) => (
                                <DraftCard
                                    key={c.id}
                                    testId={`draft-${c.id}`}
                                    media={<MapThumb polygons={c.polygons} className="size-full rounded-none" />}
                                    title={c.name}
                                    meta={
                                        <>
                                            {c.area_count} area{c.area_count === 1 ? '' : 's'} · {num(c.letterboxes)} flyers · <span className="tabular">{money(c.total_cents)}</span>
                                            <span className="block text-xs text-subtle">Edited {ago(c.updated_at)}</span>
                                        </>
                                    }
                                    onOpen={() => resume(c)}
                                    deleteUrl={`/app/campaigns/${c.id}`}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="mt-10" aria-labelledby="past-h">
                    <h2 id="past-h" className="mb-4 flex items-center gap-2 font-semibold">
                        <History className="size-4 text-muted" aria-hidden /> Re-run a past campaign
                    </h2>
                    {past.length === 0 ? (
                        <EmptyState title="No past campaigns yet" description="Submitted campaigns can be re-run with one click." className="py-8" />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {past.map((c) => (
                                <DraftCard
                                    key={c.id}
                                    media={<MapThumb polygons={c.polygons} className="size-full rounded-none" />}
                                    title={c.name}
                                    meta={
                                        <>
                                            {num(c.letterboxes)} flyers · {money(c.total_cents)} · {date(c.submitted_at)}
                                        </>
                                    }
                                    footer={
                                        <span className="flex items-center justify-between">
                                            <StatusBadge status={c.status} />
                                            <span className="text-sm font-semibold text-primary">{busy === `re${c.id}` ? 'Copying…' : 'Re-run'}</span>
                                        </span>
                                    }
                                    onOpen={() => router.post(`/app/campaigns/${c.id}/rerun`, {}, { onStart: () => setBusy(`re${c.id}`), onFinish: () => setBusy(null) })}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </WizardShell>
        </AppLayout>
    );
}
