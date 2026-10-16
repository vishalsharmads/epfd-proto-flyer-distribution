import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/form';
import { Alert, Badge, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { router } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useMemo, useState } from 'react';

interface SettingDef {
    key: string;
    group: string;
    label: string;
    help: string;
    type: 'number' | 'select';
    unit?: string;
    options?: Record<string, string>;
    value: string | number;
    customised: boolean;
}

/** Nested payload for Laravel validation: "map.stack" → { map: { stack } }. */
function nest(values: Record<string, string>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, v] of Object.entries(values)) {
        const parts = key.split('.');
        let node = out;
        parts.slice(0, -1).forEach((p) => (node = (node[p] ??= {}) as Record<string, unknown>));
        node[parts.at(-1)!] = v;
    }
    return out;
}

export default function AdminSettings({ settings, google_key_set }: { settings: SettingDef[]; google_key_set: boolean }) {
    const initial = useMemo(() => Object.fromEntries(settings.map((s) => [s.key, String(s.value)])), [settings]);
    const [values, setValues] = useState<Record<string, string>>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const dirty = Object.keys(values).some((k) => values[k] !== initial[k]);
    const groups = [...new Set(settings.map((s) => s.group))];

    const save = () => {
        setSaving(true);
        router.put('/admin/settings', { values: nest(values) } as never, {
            preserveScroll: true,
            onError: (e) => setErrors(e),
            onSuccess: () => setErrors({}),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AdminLayout
            title="Settings"
            actions={
                <>
                    {dirty && (
                        <Button variant="ghost" onClick={() => (setValues(initial), setErrors({}))}>
                            Discard changes
                        </Button>
                    )}
                    <Button onClick={save} disabled={!dirty || saving} data-testid="save-settings">
                        <Save /> Save settings
                    </Button>
                </>
            }
        >
            <div className="flex max-w-3xl flex-col gap-6">
                {groups.map((group) => (
                    <Card key={group}>
                        <CardHeader title={group} />
                        <CardBody className="flex flex-col gap-5">
                            {settings
                                .filter((s) => s.group === group)
                                .map((s) => {
                                    const id = `setting-${s.key.replace(/\./g, '-')}`;
                                    const error = errors[`values.${s.key}`];
                                    return (
                                        <div key={s.key} className="flex flex-col gap-2">
                                            <Field
                                                htmlFor={id}
                                                label={
                                                    <span className="inline-flex items-center gap-2">
                                                        {s.label}
                                                        {s.customised && <Badge tone="info">Changed from default</Badge>}
                                                    </span>
                                                }
                                                hint={s.help}
                                                error={error}
                                            >
                                                {s.type === 'select' ? (
                                                    <Select id={id} value={values[s.key]} onChange={(e) => setValues((v) => ({ ...v, [s.key]: e.target.value }))} data-testid={id}>
                                                        {Object.entries(s.options ?? {}).map(([value, label]) => (
                                                            <option key={value} value={value}>
                                                                {label}
                                                            </option>
                                                        ))}
                                                    </Select>
                                                ) : (
                                                    <div className="flex max-w-xs items-center gap-2">
                                                        <Input id={id} type="number" inputMode="decimal" value={values[s.key]} onChange={(e) => setValues((v) => ({ ...v, [s.key]: e.target.value }))} data-testid={id} />
                                                        {s.unit && <span className="text-sm text-muted">{s.unit}</span>}
                                                    </div>
                                                )}
                                            </Field>
                                            {s.key === 'map.stack' && values[s.key] === 'terra-google' && !google_key_set && (
                                                <Alert tone="warning">GOOGLE_MAPS_KEY is not set in .env, so maps use MapLibre + OSM + Terra Draw until a key is added.</Alert>
                                            )}
                                        </div>
                                    );
                                })}
                        </CardBody>
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
