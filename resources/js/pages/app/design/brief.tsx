import { BriefUploader, DesignArt, DESIGN_STEPS } from '@/components/app/design-bits';
import type { DesignAssetT, DesignOrderT } from '@/components/app/types';
import { PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, date } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

type Res = { data: DesignOrderT };
type BriefKey = 'headline' | 'key_message' | 'call_to_action' | 'contact_details' | 'brand_colours' | 'style_notes' | 'target_audience' | 'due_date';

export default function DesignBrief({ order: initial, upload }: { order: DesignOrderT; upload: { max_mb: number; max_files: number } }) {
    const [o, setO] = useState(initial);
    const [files, setFiles] = useState<DesignAssetT[]>(initial.brief_files);
    const [brief, setBrief] = useState<Record<BriefKey, string>>({
        headline: initial.headline ?? '',
        key_message: initial.key_message ?? '',
        call_to_action: initial.call_to_action ?? '',
        contact_details: initial.contact_details ?? '',
        brand_colours: initial.brand_colours ?? '',
        style_notes: initial.style_notes ?? '',
        target_audience: initial.target_audience ?? '',
        due_date: initial.due_date ?? '',
    });
    const [leaving, setLeaving] = useState(false);
    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/design/${o.id}`, { method: 'PATCH', json: patch }), [o.id]);
    const auto = useAutosave(send, useCallback((r: Res) => setO(r.data), []), 600);
    const upd = (k: BriefKey, v: string) => {
        setBrief((b) => ({ ...b, [k]: v }));
        auto.save({ [k]: v || null });
    };
    const missing = !brief.headline.trim() ? 'Add a headline.' : !brief.key_message.trim() ? 'Add your key message.' : null;
    const today = new Date().toISOString().slice(0, 10);

    const next = async () => {
        setLeaving(true);
        if (!(await auto.settle())) return setLeaving(false);
        router.visit(`/app/design/${o.id}/confirm`, { onFinish: () => setLeaving(false) });
    };

    return (
        <AppLayout title="Design · Brief" fullBleed hideFooter>
            <WizardShell
                steps={DESIGN_STEPS}
                current={2}
                onStep={(i) => router.visit(i === 0 ? '/app/design/new' : `/app/design/${o.id}/service`)}
                back={() => router.visit(`/app/design/${o.id}/service`)}
                next={next}
                nextDisabled={!!missing}
                nextHint={missing ?? undefined}
                nextLoading={leaving}
                saveState={auto.state}
            >
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Your brief</h1>
                <p className="mt-1.5 text-muted">The more our designers know, the closer the first proof will be. Everything saves automatically.</p>
                {auto.errors._ && <Alert tone="danger" className="mt-6">{auto.errors._}</Alert>}

                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_340px]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader title="Message" description="What your design needs to say." />
                            <CardBody className="grid gap-4 sm:grid-cols-2">
                                <Field label="Headline" error={auto.errors.headline} className="sm:col-span-2" hint="The big line people read first.">
                                    <Input data-testid="brief-headline" value={brief.headline} maxLength={160} onChange={(e) => upd('headline', e.target.value)} placeholder="e.g. Fresh coffee, five minutes from home" />
                                </Field>
                                <Field label="Key message" error={auto.errors.key_message} className="sm:col-span-2" hint="Offer, details, dates — the essentials.">
                                    <Textarea data-testid="brief-key-message" value={brief.key_message} maxLength={2000} onChange={(e) => upd('key_message', e.target.value)} placeholder="e.g. Grand opening Saturday 25 October — free coffee for the first 100 customers." />
                                </Field>
                                <Field label="Call to action" optional error={auto.errors.call_to_action}>
                                    <Input value={brief.call_to_action} maxLength={160} onChange={(e) => upd('call_to_action', e.target.value)} placeholder="e.g. Book online at …" />
                                </Field>
                                <Field label="Target audience" optional error={auto.errors.target_audience}>
                                    <Input value={brief.target_audience} maxLength={255} onChange={(e) => upd('target_audience', e.target.value)} placeholder="e.g. Young families in Rydalmere" />
                                </Field>
                                <Field label="Contact details to show" optional error={auto.errors.contact_details} className="sm:col-span-2">
                                    <Textarea className="min-h-16" value={brief.contact_details} maxLength={500} onChange={(e) => upd('contact_details', e.target.value)} placeholder="Phone, email, website, address, socials" />
                                </Field>
                            </CardBody>
                        </Card>
                        <Card>
                            <CardHeader title="Look & feel" />
                            <CardBody className="grid gap-4 sm:grid-cols-2">
                                <Field label="Brand colours" optional error={auto.errors.brand_colours}>
                                    <Input value={brief.brand_colours} maxLength={160} onChange={(e) => upd('brand_colours', e.target.value)} placeholder="e.g. Navy #1B2A4A, cream" />
                                </Field>
                                <Field label="Needed by" optional error={auto.errors.due_date} hint="We'll tell you if the date is tight.">
                                    <Input data-testid="brief-due-date" type="date" min={today} value={brief.due_date} onChange={(e) => upd('due_date', e.target.value)} />
                                </Field>
                                <Field label="Style notes" optional error={auto.errors.style_notes} className="sm:col-span-2">
                                    <Textarea value={brief.style_notes} maxLength={2000} onChange={(e) => upd('style_notes', e.target.value)} placeholder="Fonts, mood, examples you like, things to avoid" />
                                </Field>
                            </CardBody>
                        </Card>
                        <Card>
                            <CardHeader title="Logo, brand & reference files" description={`Optional · up to ${upload.max_files} files, ${upload.max_mb} MB each`} />
                            <CardBody>
                                <BriefUploader orderId={o.id} files={files} onChange={setFiles} maxFiles={upload.max_files} />
                            </CardBody>
                        </Card>
                    </div>
                    <aside className="lg:sticky lg:top-24" aria-label="Order summary">
                        <Card>
                            <CardBody className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <DesignArt type={o.design_type} className="size-12 shrink-0 rounded-[10px] [&>div]:size-8 [&_svg]:size-4" />
                                    <div>
                                        <h2 className="font-semibold">{o.name}</h2>
                                        <p className="text-sm text-muted">{o.reference}{o.size && ` · ${o.size}`}</p>
                                    </div>
                                </div>
                                <PriceBreakdown quote={o.pricing} compact />
                                {o.pricing?.meta.estimated_first_proof && <p className="text-sm text-muted">First proof by about <strong className="text-fg">{date(o.pricing.meta.estimated_first_proof)}</strong> after payment.</p>}
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
