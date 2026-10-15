import { AdminMap, ConfirmDialog, Dl, ReadOnlyNote, Table, Td, Th, THead, Tr, useAction, useCan } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/form';
import { Dialog, DialogContent, Dropdown, DropdownContent, DropdownItem, DropdownSeparator, DropdownTrigger } from '@/components/ui/overlay';
import { Alert, Card, CardBody, CardHeader, EmptyState, StatusBadge, Timeline } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, dateTime, money, num, titleCase } from '@/lib/utils';
import type { AudienceState } from '@/map';
import type { GeoJsonPolygon, LngLat } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { FileText, MoreHorizontal, Navigation, Scissors, Send, Upload, UserPlus, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Run {
    id: number;
    reference: string;
    name: string;
    status: string;
    flyers: number;
    houses: number;
    units: number;
    distance_km: number;
    pay_cents: number;
    deadline: string | null;
    pickup_point_id: number | null;
    distributor_id: number | null;
    coverage_pct: number | null;
    gps_points_count: number;
    distributor: { id: number; name: string } | null;
    pickup_point: { id: number; name: string } | null;
    geometry: GeoJsonPolygon;
}
interface Props {
    campaign: {
        id: number;
        reference: string;
        name: string;
        status: string;
        include_houses: boolean;
        include_units: boolean;
        coverage: string;
        material: string;
        houses: number;
        units: number;
        letterboxes: number;
        distance_km: number;
        total_cents: number;
        gst_cents: number;
        available_from: string | null;
        notes: string | null;
        pickup_address: string | null;
        proof_report_path: string | null;
        submitted_at: string | null;
        paid_at: string | null;
        completed_at: string | null;
        business: { id: number; name: string; email: string };
        pickup_point: { id: number; name: string; address: string } | null;
        print_order: { id: number; reference: string; status: string } | null;
        invoice: { id: number; number: string } | null;
    };
    areas: { id: number; name: string; draw_mode: 'pins' | 'trace' | 'suburb'; houses: number; units: number; geometry: GeoJsonPolygon }[];
    boundary: GeoJsonPolygon | null;
    runs: Run[];
    tracks: { run_id: number; points: LngLat[] }[];
    pickup_points: { id: number; name: string; state: string }[];
    distributors: { id: number; name: string; email: string }[];
    pay_per_item_cents: number;
    transitions: Record<string, string[]>;
    history: { id: number; action: string; changes: Record<string, unknown> | null; created_at: string; user: { name: string } | null }[];
}

const est = (houses: number, units: number, distance_km: number) => ({ houses, units, total: houses + units, distance_km, source: 'gnaf', estimated: true as const, no_letterboxes: houses + units === 0 });

const STEPS = [
    { key: 'submitted', label: 'Submitted' },
    { key: 'runs_published', label: 'Runs published' },
    { key: 'allocated', label: 'Allocated' },
    { key: 'collected', label: 'Collected' },
    { key: 'in_delivery', label: 'In delivery' },
    { key: 'completed', label: 'Completed' },
];

const ADVANCE_LABEL: Record<string, string> = {
    published: 'Publish',
    allocated: 'Mark allocated',
    draft: 'Unpublish',
    collected: 'Mark collected',
    in_delivery: 'Start delivery',
    completed: 'Mark completed',
    failed: 'Mark failed',
};

export default function CampaignShow(props: Props) {
    const { campaign, areas, boundary, runs, tracks, transitions, history } = props;
    const can = useCan();
    const canManage = can('campaigns.manage');
    const [splitOpen, setSplitOpen] = useState(false);
    const [confirm, setConfirm] = useState<null | { title: string; description?: string; label: string; tone?: 'danger' | 'primary'; method: 'post' | 'delete'; url: string; data?: Record<string, unknown>; testId?: string }>(null);
    const [edit, setEdit] = useState<Run | null>(null);
    const [assign, setAssign] = useState<Run | null>(null);
    const act = useAction();
    const drafts = runs.filter((r) => r.status === 'draft');
    const locked = runs.some((r) => r.status !== 'draft');
    const splittable = canManage && !['draft', 'cancelled', 'completed'].includes(campaign.status) && !locked;
    const totalFlyers = runs.reduce((s, r) => s + r.flyers, 0);
    const totalPay = runs.reduce((s, r) => s + r.pay_cents, 0);

    const mapAreas = useMemo(
        () => (runs.length ? runs.map((r) => ({ id: r.id, name: `${r.name} (${r.reference})`, draw_mode: 'pins' as const, geometry: r.geometry, estimate: est(r.houses, r.units, r.distance_km) })) : areas.map((a) => ({ id: a.id, name: a.name, draw_mode: a.draw_mode, geometry: a.geometry, estimate: est(a.houses, a.units, 0) }))),
        [runs, areas],
    );
    const stepIndex = STEPS.findIndex((s) => s.key === campaign.status);
    const dates: Record<string, string> = {};
    if (campaign.submitted_at) dates.submitted = dateTime(campaign.submitted_at);
    history.filter((h) => h.action === 'campaign.status_changed').forEach((h) => (dates[String(h.changes?.to)] ??= dateTime(h.created_at)));

    return (
        <AdminLayout
            title={campaign.name}
            actions={
                canManage && (
                    <>
                        {splittable && (
                            <>
                                <Button variant="secondary" data-testid="create-runs-one-per-area" onClick={() => setConfirm({ title: 'Create one run per area?', description: `${areas.length} run(s) will be created from the campaign areas${drafts.length ? ', replacing the current draft runs' : ''}.`, label: 'Create runs', method: 'post', url: `/admin/campaigns/${campaign.id}/runs/one-per-area`, testId: 'create-runs' })}>
                                    Create one run per area
                                </Button>
                                <Button variant="secondary" data-testid="create-runs" onClick={() => setSplitOpen(true)}>
                                    <Scissors /> Split into runs
                                </Button>
                            </>
                        )}
                        {drafts.length > 0 && (
                            <Button data-testid="publish-runs" onClick={() => setConfirm({ title: `Publish ${drafts.length} run(s)?`, description: 'Runs appear on the distributor job board and the campaign moves to “Runs published”.', label: 'Publish runs', method: 'post', url: `/admin/campaigns/${campaign.id}/runs/publish`, testId: 'publish-runs' })}>
                                <Send /> Publish runs
                            </Button>
                        )}
                    </>
                )
            }
        >
            <p className="-mt-4 mb-6 flex flex-wrap items-center gap-2 text-sm text-muted">
                <Link href="/admin/campaigns" className="hover:underline">
                    Campaigns
                </Link>
                / {campaign.reference} · <StatusBadge status={campaign.status} /> ·{' '}
                <Link href={`/admin/businesses/${campaign.business.id}`} className="hover:underline">
                    {campaign.business.name}
                </Link>
            </p>
            <ReadOnlyNote show={!canManage} />
            {campaign.status === 'submitted' && runs.length === 0 && canManage && (
                <Alert tone="warning" title="Runs needed" className="mb-6">
                    Split the area into walkable runs, or create one run per area, then publish them to distributors.
                </Alert>
            )}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="min-w-0 space-y-6">
                    <Card className="overflow-hidden">
                        <CardHeader title={runs.length ? 'Runs & GPS tracks' : 'Campaign area'} description={`${num(campaign.letterboxes)} letterboxes · ${campaign.distance_km} km walking estimate`} />
                        <AdminMap className="h-[420px] rounded-none border-0" readOnly mode="admin" boundary={runs.length ? boundary : null} areas={mapAreas} tracks={tracks.filter((t) => t.points.length > 1).map((t) => ({ points: t.points }))} />
                    </Card>

                    <Card>
                        <CardHeader title="Runs" description={runs.length ? `${runs.length} run(s) · ${num(totalFlyers)} flyers · ${money(totalPay)} distributor pay` : 'No runs yet'} />
                        {runs.length === 0 ? (
                            <CardBody>
                                <EmptyState
                                    icon={Scissors}
                                    title="No runs yet"
                                    description="Draw run polygons with the same tools businesses use, or create one run per campaign area."
                                    action={splittable && <Button onClick={() => setSplitOpen(true)}>Split into runs</Button>}
                                />
                            </CardBody>
                        ) : (
                            <Table className="rounded-none border-0" label="Runs">
                                <THead>
                                    <Th>Run</Th>
                                    <Th numeric>Flyers</Th>
                                    <Th numeric>Pay</Th>
                                    <Th>Deadline</Th>
                                    <Th>Pickup</Th>
                                    <Th>Distributor</Th>
                                    <Th numeric>Coverage</Th>
                                    <Th>Status</Th>
                                    <Th className="w-0">
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </THead>
                                <tbody>
                                    {runs.map((r) => (
                                        <Tr key={r.id}>
                                            <Td>
                                                <div className="font-medium">{r.name}</div>
                                                <div className="text-xs text-muted">
                                                    {r.reference} · {num(r.houses)} houses · {num(r.units)} units · {r.distance_km} km
                                                </div>
                                            </Td>
                                            <Td numeric>{num(r.flyers)}</Td>
                                            <Td numeric>{money(r.pay_cents)}</Td>
                                            <Td className="whitespace-nowrap">{date(r.deadline)}</Td>
                                            <Td>{r.pickup_point?.name ?? '—'}</Td>
                                            <Td>{r.distributor?.name ?? <span className="text-subtle">Unassigned</span>}</Td>
                                            <Td numeric>{r.coverage_pct != null ? `${r.coverage_pct}%` : '—'}</Td>
                                            <Td>
                                                <StatusBadge status={r.status} />
                                            </Td>
                                            <Td>
                                                {canManage && (
                                                    <div className="flex items-center justify-end gap-1">
                                                        {(transitions[r.status] ?? [])
                                                            .filter((s) => !['draft', 'published', 'allocated'].includes(s))
                                                            .slice(0, 1)
                                                            .map((s) => (
                                                                <Button key={s} size="sm" variant="secondary" data-testid={`run-status-${s}`} onClick={() => setConfirm({ title: `${ADVANCE_LABEL[s]} — ${r.reference}?`, label: ADVANCE_LABEL[s], method: 'post', url: `/admin/runs/${r.id}/status`, data: { status: s }, testId: `run-status-${s}` })}>
                                                                    {ADVANCE_LABEL[s]}
                                                                </Button>
                                                            ))}
                                                        <Dropdown>
                                                            <DropdownTrigger asChild>
                                                                <Button size="icon-sm" variant="ghost" aria-label={`More actions for ${r.reference}`}>
                                                                    <MoreHorizontal />
                                                                </Button>
                                                            </DropdownTrigger>
                                                            <DropdownContent align="end">
                                                                <DropdownItem onSelect={() => setEdit(r)}>Edit run…</DropdownItem>
                                                                {['draft', 'published', 'allocated'].includes(r.status) && (
                                                                    <DropdownItem onSelect={() => setAssign(r)}>
                                                                        <UserPlus className="size-4" /> {r.distributor ? 'Reassign' : 'Assign'} distributor…
                                                                    </DropdownItem>
                                                                )}
                                                                {(transitions[r.status] ?? []).map((s) => (
                                                                    <DropdownItem key={s} data-testid={`run-status-${s}-menu`} onSelect={() => setConfirm({ title: `${ADVANCE_LABEL[s]} — ${r.reference}?`, label: ADVANCE_LABEL[s], tone: s === 'failed' ? 'danger' : 'primary', method: 'post', url: `/admin/runs/${r.id}/status`, data: { status: s }, testId: `run-status-${s}` })}>
                                                                        {ADVANCE_LABEL[s]}
                                                                    </DropdownItem>
                                                                ))}
                                                                {['in_delivery', 'completed'].includes(r.status) && (
                                                                    <DropdownItem data-testid="simulate-gps" onSelect={() => setConfirm({ title: `Simulate GPS for ${r.reference}?`, description: `Generates a walk along the G-NAF address points inside this run (one fix every 15 s) and computes coverage within 25 m.${r.gps_points_count ? ' Existing GPS points are replaced.' : ''}`, label: 'Simulate GPS', method: 'post', url: `/admin/runs/${r.id}/simulate-gps`, testId: 'simulate-gps' })}>
                                                                        <Navigation className="size-4" /> Simulate GPS
                                                                    </DropdownItem>
                                                                )}
                                                                {['draft', 'published'].includes(r.status) && (
                                                                    <>
                                                                        <DropdownSeparator />
                                                                        <DropdownItem className="text-danger" onSelect={() => setConfirm({ title: `Delete ${r.reference}?`, label: 'Delete run', tone: 'danger', method: 'delete', url: `/admin/runs/${r.id}` })}>
                                                                            <X className="size-4" /> Delete run
                                                                        </DropdownItem>
                                                                    </>
                                                                )}
                                                            </DropdownContent>
                                                        </Dropdown>
                                                    </div>
                                                )}
                                            </Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Status" />
                        <CardBody>
                            <Timeline steps={STEPS} current={campaign.status === 'cancelled' ? -1 : stepIndex} cancelled={campaign.status === 'cancelled'} dates={dates} />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Details" />
                        <CardBody>
                            <Dl
                                className="sm:grid-cols-[110px_1fr]"
                                items={[
                                    ['Audience', [campaign.include_houses && 'Houses', campaign.include_units && 'Units'].filter(Boolean).join(' + ')],
                                    ['Material', titleCase(campaign.material)],
                                    ['Letterboxes', num(campaign.letterboxes)],
                                    ['Total', `${money(campaign.total_cents)} incl ${money(campaign.gst_cents)} GST`],
                                    ['Available', date(campaign.available_from)],
                                    ['Pickup', campaign.pickup_point?.name ?? campaign.pickup_address],
                                    ['Paid', campaign.paid_at ? dateTime(campaign.paid_at) : null],
                                    ['Print order', campaign.print_order ? <Link href={`/admin/print-orders/${campaign.print_order.id}`} className="text-primary hover:underline">{campaign.print_order.reference}</Link> : null],
                                    ['Invoice', campaign.invoice ? <a className="text-primary hover:underline" target="_blank" rel="noreferrer" href={`/admin/invoices/${campaign.invoice.id}/pdf`}>{campaign.invoice.number}</a> : null],
                                    ['Notes', campaign.notes],
                                ]}
                            />
                        </CardBody>
                    </Card>
                    <ProofReport campaign={campaign} canManage={canManage} />
                    <Card>
                        <CardHeader title="Activity" />
                        {history.length === 0 ? (
                            <CardBody>
                                <p className="text-sm text-muted">No staff actions yet.</p>
                            </CardBody>
                        ) : (
                            <ul className="max-h-96 divide-y divide-border overflow-y-auto text-sm">
                                {history.map((h) => (
                                    <li key={h.id} className="px-5 py-2.5">
                                        <p className="font-medium">
                                            {titleCase(h.action.replace('.', ' · '))}
                                            {h.changes?.to ? ` → ${titleCase(String(h.changes.to))}` : ''}
                                        </p>
                                        <p className="text-xs text-muted">
                                            {h.user?.name ?? 'System'} · {dateTime(h.created_at)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(o) => !o && setConfirm(null)}
                title={confirm?.title ?? ''}
                description={confirm?.description}
                confirmLabel={confirm?.label}
                tone={confirm?.tone}
                processing={act.processing}
                error={act.error}
                testId={confirm?.testId}
                onConfirm={() => confirm && act.run(confirm.method, confirm.url, confirm.data ?? {}, () => setConfirm(null))}
            />
            {splitOpen && <SplitTool campaign={campaign} boundary={boundary} runs={drafts} onClose={() => setSplitOpen(false)} />}
            {edit && <EditRun run={edit} pickupPoints={props.pickup_points} onClose={() => setEdit(null)} payPerItem={props.pay_per_item_cents} />}
            {assign && <AssignRun run={assign} distributors={props.distributors} onClose={() => setAssign(null)} />}
        </AdminLayout>
    );
}

/** Full-screen split tool: the map agent's AudienceBuilder in admin mode with the campaign area as a dashed boundary. */
function SplitTool({ campaign, boundary, runs, onClose }: { campaign: Props['campaign']; boundary: GeoJsonPolygon | null; runs: Run[]; onClose: () => void }) {
    const [state, setState] = useState<AudienceState | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const initial = useMemo(() => runs.map((r) => ({ id: r.id, name: r.name, draw_mode: 'pins' as const, geometry: r.geometry, estimate: est(r.houses, r.units, r.distance_km) })), [runs]);
    const count = state?.areas.length ?? initial.length;
    const save = () => {
        const areas = state?.areas ?? initial.map((a, i) => ({ ...a, key: String(i) }));
        if (!areas.length) return setError('Draw at least one run.');
        setSaving(true);
        setError(null);
        router.post(
            `/admin/campaigns/${campaign.id}/runs`,
            { runs: areas.map((a) => ({ name: a.name, geometry: a.geometry })) },
            {
                preserveScroll: true,
                onSuccess: (page) => !(page.props as { flash?: { error?: string | null } }).flash?.error && onClose(),
                onError: (e) => setError(Object.values(e)[0] ?? 'Could not save runs.'),
                onFinish: () => setSaving(false),
            },
        );
    };
    return (
        <DialogPrimitive.Root open onOpenChange={(o) => !o && onClose()}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-overlay" />
                <DialogPrimitive.Content className="fixed inset-2 z-50 flex flex-col overflow-hidden rounded-[16px] border border-border bg-surface shadow-lg sm:inset-6" aria-describedby="split-desc">
                    <div className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
                        <div className="min-w-0 flex-1">
                            <DialogPrimitive.Title className="font-semibold">Split {campaign.reference} into runs</DialogPrimitive.Title>
                            <DialogPrimitive.Description id="split-desc" className="text-sm text-muted">
                                Draw one polygon per run inside the dashed campaign area. Counts are re-estimated on the server (no pickup radius rule).
                            </DialogPrimitive.Description>
                        </div>
                        {state && (
                            <p className="tabular text-sm text-muted">
                                {count} run(s) · {num(state.totals.letterboxes)} letterboxes
                            </p>
                        )}
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={save} loading={saving} disabled={count === 0} data-testid="save-runs">
                            Save {count} run(s)
                        </Button>
                    </div>
                    {error && (
                        <Alert tone="danger" className="m-3">
                            {error}
                        </Alert>
                    )}
                    <div className="relative flex-1">
                        <AdminMap className="absolute inset-0 rounded-none border-0" mode="admin" boundary={boundary} areas={initial} includeHouses={campaign.include_houses} includeUnits={campaign.include_units} onChange={setState} />
                    </div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function EditRun({ run, pickupPoints, onClose, payPerItem }: { run: Run; pickupPoints: Props['pickup_points']; onClose: () => void; payPerItem: number }) {
    const form = useForm({ name: run.name, flyers: String(run.flyers), pay: (run.pay_cents / 100).toFixed(2), deadline: run.deadline?.slice(0, 10) ?? '', pickup_point_id: run.pickup_point_id ? String(run.pickup_point_id) : '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title={`Edit ${run.reference}`}>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({ ...d, pickup_point_id: d.pickup_point_id || null, deadline: d.deadline || null }));
                        form.patch(`/admin/runs/${run.id}`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <Field label="Name" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Flyers" error={form.errors.flyers} hint={`Estimated ${num(run.houses + run.units)}`}>
                            <Input type="number" min={0} value={form.data.flyers} onChange={(e) => form.setData('flyers', e.target.value)} />
                        </Field>
                        <Field label="Distributor pay (AUD)" error={form.errors.pay} hint={<button type="button" className="text-primary hover:underline" onClick={() => form.setData('pay', ((Number(form.data.flyers) * payPerItem) / 100).toFixed(2))}>Reset to {money(Math.round(payPerItem))}/flyer</button>}>
                            <Input type="number" step="0.01" min={0} value={form.data.pay} onChange={(e) => form.setData('pay', e.target.value)} />
                        </Field>
                        <Field label="Deadline" error={form.errors.deadline}>
                            <Input type="date" value={form.data.deadline} onChange={(e) => form.setData('deadline', e.target.value)} />
                        </Field>
                        <Field label="Pickup point" error={form.errors.pickup_point_id}>
                            <Select value={form.data.pickup_point_id} onChange={(e) => form.setData('pickup_point_id', e.target.value)}>
                                <option value="">None</option>
                                {pickupPoints.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.state})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                            Save run
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AssignRun({ run, distributors, onClose }: { run: Run; distributors: Props['distributors']; onClose: () => void }) {
    const form = useForm({ distributor_id: run.distributor_id ? String(run.distributor_id) : '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title={`Assign ${run.reference}`} description="The run moves to “Allocated” and the campaign rolls up accordingly.">
                {distributors.length === 0 ? (
                    <EmptyState title="No distributors yet" description="Create a distributor in Users & roles first." action={<Button asChild variant="secondary"><Link href="/admin/users?tab=distributors">Add distributor</Link></Button>} />
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/runs/${run.id}/assign`, { preserveScroll: true, onSuccess: onClose });
                        }}
                    >
                        <Field label="Distributor" error={form.errors.distributor_id}>
                            <Select value={form.data.distributor_id} onChange={(e) => form.setData('distributor_id', e.target.value)}>
                                <option value="">Choose a distributor…</option>
                                {distributors.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name} — {d.email}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={form.processing} disabled={!form.data.distributor_id} data-testid="assign-run">
                                Assign
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ProofReport({ campaign, canManage }: { campaign: Props['campaign']; canManage: boolean }) {
    const form = useForm<{ report: File | null }>({ report: null });
    return (
        <Card>
            <CardHeader title="Proof of delivery report" description="PDF shared with the business" />
            <CardBody className="space-y-3">
                {campaign.proof_report_path ? (
                    <a href={`/admin/campaigns/${campaign.id}/proof-report`} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-sm text-primary hover:underline">
                        <FileText className="size-4" /> View proof report
                    </a>
                ) : (
                    <p className="text-sm text-muted">Not uploaded yet.</p>
                )}
                {canManage && (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/campaigns/${campaign.id}/proof-report`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <Field label="PDF (max 20 MB)" error={form.errors.report}>
                            <Input type="file" accept="application/pdf" onChange={(e) => form.setData('report', e.target.files?.[0] ?? null)} />
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" variant="secondary" loading={form.processing} disabled={!form.data.report}>
                                <Upload /> Upload report
                            </Button>
                            <Button type="button" size="sm" variant="ghost" data-testid="generate-proof" onClick={() => router.post(`/admin/campaigns/${campaign.id}/proof-report/generate`, {}, { preserveScroll: true })}>
                                <FileText /> Generate from GPS
                            </Button>
                        </div>
                    </form>
                )}
            </CardBody>
        </Card>
    );
}
