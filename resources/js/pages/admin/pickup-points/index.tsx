import { ConfirmDialog, Table, Td, Th, THead, Tr, useAction } from '@/components/admin/kit';
import { AddressTypeahead } from '@/components/app/address-typeahead';
import { Button } from '@/components/ui/button';
import { Field, Input, Label, Select, Switch } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import { AdminMap } from '@/components/admin/kit';
import AdminLayout from '@/layouts/admin-layout';
import { useForm } from '@inertiajs/react';
import { MapPin, Plus, Trash2, Warehouse } from 'lucide-react';
import { useState } from 'react';

interface Point {
    id: number;
    name: string;
    address: string;
    state: string;
    opening_hours: string | null;
    is_active: boolean;
    lng: number;
    lat: number;
}

const STATES = ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'];

export default function PickupPoints({ points }: { points: Point[] }) {
    const [edit, setEdit] = useState<Point | 'new' | null>(null);
    const [del, setDel] = useState<Point | null>(null);
    const [focus, setFocus] = useState<Point | null>(points[0] ?? null);
    const act = useAction();
    return (
        <AdminLayout title="Pickup points" actions={<Button onClick={() => setEdit('new')}><Plus /> Add pickup point</Button>}>
            {points.length === 0 ? (
                <EmptyState icon={Warehouse} title="No pickup points" description="Distributors collect flyers from pickup points. Add your first depot." action={<Button onClick={() => setEdit('new')}>Add pickup point</Button>} />
            ) : (
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                    <Table label="Pickup points">
                        <THead>
                            <Th>Name</Th>
                            <Th>Address</Th>
                            <Th>Hours</Th>
                            <Th>Status</Th>
                            <Th className="w-0"><span className="sr-only">Actions</span></Th>
                        </THead>
                        <tbody>
                            {points.map((p) => (
                                <Tr key={p.id} onClick={() => setFocus(p)} className={focus?.id === p.id ? 'bg-surface-2/60' : ''}>
                                    <Td className="font-medium">{p.name}</Td>
                                    <Td>{p.address} <span className="text-muted">{p.state}</span></Td>
                                    <Td className="text-muted">{p.opening_hours ?? '—'}</Td>
                                    <Td><StatusBadge status={p.is_active ? 'approved' : 'cancelled'} label={p.is_active ? 'Active' : 'Inactive'} /></Td>
                                    <Td>
                                        <div className="flex gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => setEdit(p)}>Edit</Button>
                                            <Button size="icon-sm" variant="ghost" aria-label={`Delete ${p.name}`} onClick={() => setDel(p)}><Trash2 /></Button>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <div>
                        {focus && <AdminMap key={focus.id} className="h-[360px]" readOnly mode="admin" pickup={{ lng: focus.lng, lat: focus.lat, label: focus.name }} />}
                        {focus && <p className="mt-2 flex items-center gap-1.5 text-xs text-muted"><MapPin className="size-3.5" /> {focus.name} · {focus.lat.toFixed(5)}, {focus.lng.toFixed(5)}</p>}
                    </div>
                </div>
            )}
            {edit && <PointDialog point={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
            <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} title={`Delete ${del?.name}?`} description="Runs and campaigns using it keep working but lose the reference. Consider marking it inactive instead." confirmLabel="Delete" tone="danger" processing={act.processing} error={act.error} onConfirm={() => del && act.run('delete', `/admin/pickup-points/${del.id}`, {}, () => setDel(null))} />
        </AdminLayout>
    );
}

function PointDialog({ point, onClose }: { point: Point | null; onClose: () => void }) {
    const form = useForm({
        name: point?.name ?? '',
        address: point?.address ?? '',
        state: point?.state ?? 'NSW',
        lng: point ? String(point.lng) : '',
        lat: point ? String(point.lat) : '',
        opening_hours: point?.opening_hours ?? '',
        is_active: point?.is_active ?? true,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const o = { preserveScroll: true, onSuccess: onClose };
        if (point) form.put(`/admin/pickup-points/${point.id}`, o);
        else form.post('/admin/pickup-points', o);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="overflow-visible" title={point ? `Edit ${point.name}` : 'Add pickup point'}>
                <form className="space-y-4" onSubmit={submit}>
                    <Field label="Name" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Rydalmere depot" />
                    </Field>
                    <Field label="Address" error={form.errors.address ?? form.errors.lng ?? form.errors.lat} success={form.data.lng ? 'Located from G-NAF' : undefined} hint="Start typing and choose a G-NAF address">
                        <AddressTypeahead
                            value={form.data.address}
                            onChange={(t) => form.setData((d) => ({ ...d, address: t, lng: '', lat: '' }))}
                            onSelect={(hit) => form.setData((d) => ({ ...d, address: hit.label, state: hit.state, lng: String(hit.location[0]), lat: String(hit.location[1]) }))}
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="State" error={form.errors.state}>
                            <Select value={form.data.state} onChange={(e) => form.setData('state', e.target.value)}>
                                {STATES.map((s) => <option key={s}>{s}</option>)}
                            </Select>
                        </Field>
                        <Field label="Opening hours" optional error={form.errors.opening_hours}>
                            <Input value={form.data.opening_hours} onChange={(e) => form.setData('opening_hours', e.target.value)} placeholder="Mon–Fri 8am–5pm" />
                        </Field>
                    </div>
                    <div className="flex items-center gap-3">
                        <Switch id="pp-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                        <Label htmlFor="pp-active">Active</Label>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={form.processing} disabled={!form.data.lng}>{point ? 'Save' : 'Add pickup point'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
