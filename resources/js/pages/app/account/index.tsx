import { AddressTypeahead } from '@/components/app/address-typeahead';
import { MapThumb } from '@/components/app/map';
import type { SavedCardT } from '@/components/app/payment-form';
import type { InvoiceT } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/form';
import { Tabs } from '@/components/ui/navigation';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/overlay';
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money, num } from '@/lib/utils';
import type { GeoJsonPolygon } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import { CreditCard, FileText, Map as MapIcon, Scale, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Props {
    tab: string;
    industries: string[];
    profile: { business_name: string; contact_name: string; email: string; mobile: string; address: string; abn: string | null; industry: string | null; status: string };
    cards: SavedCardT[];
    credits_cents: number;
    invoices: InvoiceT[];
    legal: { slug: string; title: string; updated_at: string }[];
    areas: { id: number; name: string; houses: number; units: number; geometry: GeoJsonPolygon | null; created_at: string | null }[];
}

const TABS = [
    { value: 'profile', label: 'Profile' },
    { value: 'payments', label: 'Payments' },
    { value: 'invoices', label: 'Invoices' },
    { value: 'security', label: 'Security' },
    { value: 'legal', label: 'Legal' },
    { value: 'areas', label: 'Saved areas' },
];

function ConfirmDelete({ label, onConfirm }: { label: string; onConfirm: () => void }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Delete ${label}`}>
                    <Trash2 />
                </Button>
            </DialogTrigger>
            <DialogContent title={`Delete ${label}?`} description="This can't be undone.">
                <div className="flex justify-end gap-2">
                    <DialogClose asChild><Button variant="secondary">Cancel</Button></DialogClose>
                    <DialogClose asChild><Button variant="danger" onClick={onConfirm}>Delete</Button></DialogClose>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Profile({ profile, industries }: Pick<Props, 'profile' | 'industries'>) {
    const form = useForm({ business_name: profile.business_name, contact_name: profile.contact_name, mobile: profile.mobile ?? '', address: profile.address, abn: profile.abn ?? '', industry: profile.industry ?? '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/app/account/profile', { preserveScroll: true });
    };
    return (
        <Card>
            <CardHeader title="Business profile" description={<span className="flex items-center gap-2">Account status <StatusBadge status={profile.status} /></span>} />
            <CardBody>
                <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2">
                    <Field label="Business name" error={form.errors.business_name}><Input value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} /></Field>
                    <Field label="Contact name" error={form.errors.contact_name}><Input value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} /></Field>
                    <Field label="Email" hint="Contact support to change your login email."><Input value={profile.email} disabled /></Field>
                    <Field label="Mobile" error={form.errors.mobile}><Input type="tel" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} /></Field>
                    <Field label="Business address" error={form.errors.address} className="sm:col-span-2">
                        <AddressTypeahead value={form.data.address} onChange={(v) => form.setData('address', v)} onSelect={(h) => form.setData('address', h.label)} />
                    </Field>
                    <Field label="ABN" optional error={form.errors.abn}><Input inputMode="numeric" value={form.data.abn} onChange={(e) => form.setData('abn', e.target.value)} /></Field>
                    <Field label="Industry" error={form.errors.industry}>
                        <Select value={form.data.industry} onChange={(e) => form.setData('industry', e.target.value)}>
                            <option value="">Choose…</option>
                            {industries.map((i) => <option key={i}>{i}</option>)}
                        </Select>
                    </Field>
                    <div className="sm:col-span-2"><Button type="submit" loading={form.processing} disabled={!form.isDirty}>Save changes</Button></div>
                </form>
            </CardBody>
        </Card>
    );
}

function Payments({ cards, credits_cents }: Pick<Props, 'cards' | 'credits_cents'>) {
    return (
        <div className="space-y-6">
            <Card className="p-5">
                <p className="text-sm text-muted">Credit balance</p>
                <p className="tabular mt-1 text-2xl font-semibold">{money(credits_cents)}</p>
                <p className="mt-1 text-xs text-subtle">Credits can be applied at checkout.</p>
            </Card>
            <Card>
                <CardHeader title="Saved cards" description="Cards are saved when you tick “Save this card for later” at checkout." />
                {cards.length === 0 ? (
                    <CardBody><EmptyState icon={CreditCard} title="No saved cards" description="Save a card during your next checkout for faster payments." className="py-8" /></CardBody>
                ) : (
                    <ul className="divide-y divide-border">
                        {cards.map((c) => (
                            <li key={c.id} className="flex items-center gap-3 px-5 py-3.5 text-sm">
                                <CreditCard className="size-5 text-muted" aria-hidden />
                                <span className="flex-1"><span className="font-medium capitalize">{c.brand}</span> •••• {c.last4} <span className="text-muted">· exp {String(c.exp_month).padStart(2, '0')}/{String(c.exp_year).slice(-2)} · {c.holder_name}</span></span>
                                {c.is_default ? <Badge tone="success">Default</Badge> : <Button variant="ghost" size="sm" onClick={() => router.post(`/app/account/cards/${c.id}/default`, {}, { preserveScroll: true })}>Make default</Button>}
                                <ConfirmDelete label={`card ending ${c.last4}`} onConfirm={() => router.delete(`/app/account/cards/${c.id}`, { preserveScroll: true })} />
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

function Invoices({ invoices }: Pick<Props, 'invoices'>) {
    if (!invoices.length) return <EmptyState icon={FileText} title="No invoices yet" description="A GST tax invoice is issued for every payment." action={<Button asChild><Link href="/app/campaigns/new">Start a campaign</Link></Button>} />;
    return (
        <Card className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
                <thead>
                    <tr className="border-b border-border text-left text-xs text-muted uppercase">
                        <th className="px-5 py-3 font-medium">Date</th>
                        <th className="px-5 py-3 font-medium">Invoice</th>
                        <th className="px-5 py-3 font-medium">Reference</th>
                        <th className="px-5 py-3 text-right font-medium">Total incl. GST</th>
                        <th className="px-5 py-3 font-medium">Status</th>
                        <th className="px-5 py-3"><span className="sr-only">PDF</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {invoices.map((i) => (
                        <tr key={i.id}>
                            <td className="px-5 py-3">{date(i.issued_at)}</td>
                            <td className="px-5 py-3 font-medium">{i.number}</td>
                            <td className="px-5 py-3 text-muted">{i.reference ?? '—'}</td>
                            <td className="tabular px-5 py-3 text-right">{money(i.total_cents)}<span className="block text-xs text-subtle">GST {money(i.gst_cents)}</span></td>
                            <td className="px-5 py-3"><StatusBadge status={i.status} /></td>
                            <td className="px-5 py-3 text-right"><Button asChild variant="ghost" size="sm"><a href={i.pdf_url}><FileText /> PDF</a></Button></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Card>
    );
}

function Security() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const mismatch = form.data.password_confirmation.length > 0 && form.data.password !== form.data.password_confirmation;
    return (
        <Card className="max-w-xl">
            <CardHeader title="Change password" />
            <CardBody>
                <form onSubmit={(e) => { e.preventDefault(); form.put('/app/account/password', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="space-y-5">
                    <Field label="Current password" error={form.errors.current_password}><Input type="password" autoComplete="current-password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} /></Field>
                    <Field label="New password" error={form.errors.password} hint="At least 8 characters with letters and numbers."><Input type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></Field>
                    <Field label="Confirm new password" error={mismatch ? 'Passwords do not match.' : undefined}><Input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} /></Field>
                    <Button type="submit" loading={form.processing} disabled={!form.data.current_password || form.data.password.length < 8 || mismatch}>Update password</Button>
                </form>
            </CardBody>
        </Card>
    );
}

export default function Account(props: Props) {
    const [tab, setTab] = useState(props.tab);
    const change = (t: string) => {
        setTab(t);
        window.history.replaceState(window.history.state, '', `/app/account?tab=${t}`);
    };
    return (
        <AppLayout title="Account">
            <PageHeader title="Account" description="Your business profile, payments and preferences." />
            <Tabs value={tab} onChange={change} tabs={TABS} />
            {tab === 'profile' && <Profile profile={props.profile} industries={props.industries} />}
            {tab === 'payments' && <Payments cards={props.cards} credits_cents={props.credits_cents} />}
            {tab === 'invoices' && <Invoices invoices={props.invoices} />}
            {tab === 'security' && <Security />}
            {tab === 'legal' && (
                props.legal.length === 0 ? <EmptyState icon={Scale} title="No legal documents" /> : (
                    <Card className="divide-y divide-border">
                        {props.legal.map((l) => (
                            <div key={l.slug} className="flex items-center gap-3 px-5 py-3.5">
                                <Scale className="size-4 text-muted" aria-hidden />
                                <span className="flex-1"><span className="font-medium">{l.title}</span><span className="block text-xs text-muted">Updated {date(l.updated_at)}</span></span>
                                <Button asChild variant="secondary" size="sm"><Link href={`/legal/${l.slug}`}>Read</Link></Button>
                            </div>
                        ))}
                    </Card>
                )
            )}
            {tab === 'areas' && (
                props.areas.length === 0 ? (
                    <EmptyState icon={MapIcon} title="No saved areas" description="Open a submitted campaign and choose “Save area” to reuse it later." action={<Button asChild variant="secondary"><Link href="/app/jobs?filter=distribution">Go to my campaigns</Link></Button>} />
                ) : (
                    <>
                        <Alert tone="info" className="mb-4">Saved areas are kept for reference and future re-runs.</Alert>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {props.areas.map((a) => (
                                <Card key={a.id} className="overflow-hidden">
                                    <div className="aspect-[16/9] bg-surface-2"><MapThumb polygons={[a.geometry]} className="rounded-none" /></div>
                                    <div className="flex items-center gap-2 p-4">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium">{a.name}</p>
                                            <p className="tabular text-xs text-muted">{num(a.houses)} houses · {num(a.units)} units (est.)</p>
                                        </div>
                                        <ConfirmDelete label={a.name} onConfirm={() => router.delete(`/app/account/areas/${a.id}`, { preserveScroll: true })} />
                                    </div>
                                </Card>
                            ))}
                        </div>
                    </>
                )
            )}
        </AppLayout>
    );
}
