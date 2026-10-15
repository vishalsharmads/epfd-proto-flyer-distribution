import { AudienceMap, type AudienceState } from '@/components/app/map';
import type { CampaignT, DistributionOptions } from '@/components/app/types';
import { CAMPAIGN_STEPS, WizardShell, type SaveState } from '@/components/app/wizard';
import AppLayout from '@/layouts/app-layout';
import { api, ApiError, debounce, money, num } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

type Res = { data: CampaignT; options: DistributionOptions };

export default function CampaignAudience({ campaign: initial }: { campaign: CampaignT; options: DistributionOptions }) {
    const [campaign, setCampaign] = useState(initial);
    const [save, setSave] = useState<SaveState>('idle');
    const [error, setError] = useState<string | null>(null);
    const queue = useRef<Promise<unknown>>(Promise.resolve());
    const latest = useRef<AudienceState | null>(null);
    const toggles = useRef({ h: initial.include_houses, u: initial.include_units });

    const push = useMemo(
        () =>
            debounce(() => {
                const state = latest.current;
                if (!state) return;
                setSave('saving');
                queue.current = queue.current.then(async () => {
                    try {
                        if (state.include_houses !== toggles.current.h || state.include_units !== toggles.current.u) {
                            await api<Res>(`/app/campaigns/${campaign.id}`, { method: 'PATCH', json: { include_houses: state.include_houses, include_units: state.include_units } });
                            toggles.current = { h: state.include_houses, u: state.include_units };
                        }
                        const res = await api<Res>(`/app/campaigns/${campaign.id}/areas`, {
                            method: 'PUT',
                            json: { areas: state.areas.map((a) => ({ name: a.name, draw_mode: a.draw_mode, suburb_id: a.suburb_id ?? null, geometry: a.geometry })) },
                        });
                        setCampaign(res.data);
                        setError(null);
                        setSave('saved');
                    } catch (e) {
                        setError(e instanceof ApiError ? e.message : 'Could not save your areas. Check your connection and try again.');
                        setSave('error');
                    }
                });
            }, 400),
        [campaign.id],
    );

    const onChange = (s: AudienceState) => {
        latest.current = s;
        push();
    };

    const hasLetterboxes = campaign.areas.length > 0 && campaign.letterboxes > 0 && campaign.areas.every((a) => a.total > 0);
    const next = async () => {
        await queue.current;
        router.visit(`/app/campaigns/${campaign.id}/details`);
    };

    return (
        <AppLayout title={`${campaign.name} · Audience`} fullBleed hideFooter>
            <WizardShell
                fill
                steps={CAMPAIGN_STEPS}
                current={1}
                onStep={(i) => i === 0 && router.visit('/app/campaigns/new')}
                back={() => router.visit('/app/campaigns/new')}
                next={next}
                nextDisabled={!hasLetterboxes || save === 'saving'}
                saveState={save}
                nextHint={
                    campaign.letterboxes > 0 ? (
                        <span className="tabular">
                            {num(campaign.letterboxes)} letterboxes (est.) · <strong className="text-fg">{money(campaign.total_cents)}</strong> incl. GST
                        </span>
                    ) : (
                        'Add at least one area with letterboxes'
                    )
                }
            >
                <AudienceMap
                    className="absolute inset-0"
                    areas={initial.areas}
                    pickup={initial.pickup}
                    includeHouses={initial.include_houses}
                    includeUnits={initial.include_units}
                    coverage={initial.coverage}
                    material={initial.material}
                    onChange={onChange}
                />
                {error && (
                    <div role="alert" className="absolute top-4 left-1/2 z-20 flex max-w-[90%] -translate-x-1/2 items-center gap-2 rounded-[12px] border border-danger/30 bg-surface px-4 py-2.5 text-sm text-danger shadow-md">
                        <AlertTriangle className="size-4 shrink-0" aria-hidden /> {error}
                        <button type="button" className="ml-2 font-medium text-fg underline" onClick={() => push()}>
                            Retry
                        </button>
                    </div>
                )}
            </WizardShell>
        </AppLayout>
    );
}
