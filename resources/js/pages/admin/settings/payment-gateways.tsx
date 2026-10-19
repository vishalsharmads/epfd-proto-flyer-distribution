import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/form';
import { Alert, Badge, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { Link, router } from '@inertiajs/react';
import { PlugZap, Save } from 'lucide-react';
import { useState } from 'react';

interface GatewayField {
    key: string;
    label: string;
    secret: boolean;
    set: boolean;
    value: string | null;
}

interface Gateway {
    driver: string;
    label: string;
    active: boolean;
    mode: 'test' | 'live';
    webhook_url: string;
    fields: GatewayField[];
}

export default function PaymentGateways({ gateways }: { gateways: Gateway[] }) {
    return (
        <AdminLayout title="Payment gateways">
            <div className="flex max-w-3xl flex-col gap-6">
                <Alert tone="info">
                    Keys are stored encrypted and secret keys are never shown again. Leave a secret blank to keep the saved one. Choose which gateway
                    takes payments under{' '}
                    <Link href="/admin/settings" className="font-medium underline underline-offset-2">
                        Settings
                    </Link>
                    .
                </Alert>
                {gateways.map((g) => (
                    <GatewayCard key={g.driver} gateway={g} />
                ))}
            </div>
        </AdminLayout>
    );
}

function GatewayCard({ gateway }: { gateway: Gateway }) {
    const [mode, setMode] = useState(gateway.mode);
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(gateway.fields.map((f) => [f.key, f.value ?? ''])));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);

    const save = () =>
        router.put(
            `/admin/settings/payment-gateways/${gateway.driver}`,
            { mode, credentials: values },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: setErrors,
                onSuccess: () => {
                    setErrors({});
                    setValues((v) => Object.fromEntries(gateway.fields.map((f) => [f.key, f.secret ? '' : v[f.key]])));
                },
            },
        );

    const test = () =>
        router.post(
            `/admin/settings/payment-gateways/${gateway.driver}/test`,
            {},
            { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) },
        );

    return (
        <Card>
            <CardHeader
                title={
                    <span className="inline-flex items-center gap-2">
                        {gateway.label}
                        {gateway.active && <Badge tone="success">Taking payments</Badge>}
                        {mode === 'live' ? <Badge tone="warning">Live</Badge> : <Badge>Test mode</Badge>}
                    </span>
                }
            />
            <CardBody className="flex flex-col gap-5">
                <Field
                    label="Mode"
                    htmlFor={`${gateway.driver}-mode`}
                    hint="Test mode never moves real money. Use test keys in test mode and live keys in live mode."
                    error={errors.mode}
                >
                    <Select
                        id={`${gateway.driver}-mode`}
                        value={mode}
                        onChange={(e) => setMode(e.target.value as Gateway['mode'])}
                        className="max-w-xs"
                    >
                        <option value="test">Test</option>
                        <option value="live">Live</option>
                    </Select>
                </Field>
                {gateway.fields.map((f) => {
                    const id = `${gateway.driver}-${f.key}`;
                    return (
                        <Field
                            key={f.key}
                            htmlFor={id}
                            label={
                                <span className="inline-flex items-center gap-2">
                                    {f.label}
                                    {f.secret && (f.set ? <Badge tone="success">Saved</Badge> : <Badge tone="warning">Not set</Badge>)}
                                </span>
                            }
                            error={errors[`credentials.${f.key}`]}
                        >
                            <Input
                                id={id}
                                type={f.secret ? 'password' : 'text'}
                                autoComplete="off"
                                spellCheck={false}
                                placeholder={f.secret && f.set ? '•••••••• (leave blank to keep)' : undefined}
                                value={values[f.key]}
                                onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                            />
                        </Field>
                    );
                })}
                <Field label="Webhook URL" hint="Add this endpoint in the gateway's dashboard so payments and refunds made there are recorded here.">
                    <Input readOnly value={gateway.webhook_url} onFocus={(e) => e.currentTarget.select()} />
                </Field>
                <div className="flex flex-wrap gap-3">
                    <Button onClick={save} loading={saving}>
                        <Save /> Save
                    </Button>
                    <Button variant="secondary" onClick={test} loading={testing}>
                        <PlugZap /> Test connection
                    </Button>
                </div>
            </CardBody>
        </Card>
    );
}
