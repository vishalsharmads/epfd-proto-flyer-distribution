import { AddressTypeahead } from '@/components/app/address-typeahead';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label, Select } from '@/components/ui/form';
import AuthLayout from '@/layouts/auth-layout';
import { api } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

function abnChecksum(abn: string): boolean {
    const d = abn.replace(/\D/g, '');
    if (d.length !== 11) return false;
    const w = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];
    const sum = d.split('').reduce((s, c, i) => s + (Number(c) - (i === 0 ? 1 : 0)) * w[i], 0);
    return sum % 89 === 0;
}

const mobileOk = (m: string) => /^(?:04\d{8}|\+614\d{8})$/.test(m.replace(/[^\d+]/g, ''));

export default function Register({ industries }: { industries: string[] }) {
    const form = useForm({
        business_name: '',
        contact_name: '',
        email: '',
        mobile: '',
        address: '',
        address_gnaf_pid: '',
        address_lng: null as number | null,
        address_lat: null as number | null,
        state: '',
        industry: '',
        abn: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });
    const [touched, setTouched] = useState<Record<string, boolean>>({});
    const [abnEntity, setAbnEntity] = useState<string | null>(null);
    const t = (k: string) => setTouched((s) => ({ ...s, [k]: true }));

    const client: Record<string, string | undefined> = {
        business_name: form.data.business_name.trim() ? undefined : 'Enter your business name.',
        contact_name: form.data.contact_name.trim() ? undefined : 'Enter your name.',
        email: /^\S+@\S+\.\S+$/.test(form.data.email) ? undefined : 'Enter a valid email address.',
        mobile: mobileOk(form.data.mobile) ? undefined : 'Enter an Australian mobile number, e.g. 0412 345 678.',
        address: form.data.address_gnaf_pid ? undefined : 'Start typing your business address and choose it from the list.',
        industry: form.data.industry ? undefined : 'Choose your industry.',
        abn: !form.data.abn.trim() || abnChecksum(form.data.abn) ? undefined : 'Enter a valid 11-digit ABN (or leave it blank).',
        password: form.data.password.length >= 8 && /[a-z]/i.test(form.data.password) && /\d/.test(form.data.password) ? undefined : 'At least 8 characters with letters and numbers.',
        password_confirmation: form.data.password_confirmation === form.data.password && form.data.password ? undefined : 'Passwords do not match.',
        terms: form.data.terms ? undefined : 'Please accept the Terms & Conditions and Privacy Policy.',
    };
    const err = (k: string) => form.errors[k as keyof typeof form.errors] ?? (touched[k] ? client[k] : undefined);

    useEffect(() => {
        setAbnEntity(null);
        if (!abnChecksum(form.data.abn)) return;
        const ctl = setTimeout(() => {
            api<{ data: { entity_name: string; status: string } }>(`/register/abn?abn=${encodeURIComponent(form.data.abn)}`)
                .then((r) => setAbnEntity(`${r.data.entity_name} · ${r.data.status}`))
                .catch(() => setAbnEntity(null));
        }, 300);
        return () => clearTimeout(ctl);
    }, [form.data.abn]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setTouched(Object.fromEntries(Object.keys(client).map((k) => [k, true])));
        if (Object.values(client).some(Boolean)) return;
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="Create your business account" subtitle="Book print and letterbox distribution in minutes." wide>
            <form onSubmit={submit} className="grid gap-5 sm:grid-cols-2" noValidate>
                <Field label="Business name" error={err('business_name')} className="sm:col-span-2">
                    <Input data-testid="register-business-name" autoComplete="organization" value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} onBlur={() => t('business_name')} />
                </Field>
                <Field label="Your name" error={err('contact_name')}>
                    <Input data-testid="register-contact-name" autoComplete="name" value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} onBlur={() => t('contact_name')} />
                </Field>
                <Field label="Mobile" error={err('mobile')}>
                    <Input data-testid="register-mobile" type="tel" inputMode="tel" autoComplete="tel" placeholder="0412 345 678" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} onBlur={() => t('mobile')} />
                </Field>
                <Field label="Work email" error={err('email')} className="sm:col-span-2">
                    <Input data-testid="register-email" type="email" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} onBlur={() => t('email')} />
                </Field>
                <Field label="Business address" error={err('address')} success={form.data.address_gnaf_pid ? 'Address verified with G-NAF' : undefined} className="sm:col-span-2">
                    <AddressTypeahead
                        data-testid="register-address"
                        placeholder="Start typing, e.g. 257 Victoria Rd Rydalmere"
                        value={form.data.address}
                        onChange={(v) => form.setData((d) => ({ ...d, address: v, address_gnaf_pid: '', address_lng: null, address_lat: null }))}
                        onSelect={(h) => {
                            form.setData((d) => ({ ...d, address: h.label, address_gnaf_pid: h.gnaf_pid, address_lng: h.location[0], address_lat: h.location[1], state: h.state }));
                            t('address');
                        }}
                        onBlur={() => t('address')}
                    />
                </Field>
                <Field label="Industry" error={err('industry')}>
                    <Select data-testid="register-industry" value={form.data.industry} onChange={(e) => form.setData('industry', e.target.value)} onBlur={() => t('industry')}>
                        <option value="">Choose…</option>
                        {industries.map((i) => (
                            <option key={i}>{i}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="ABN" optional error={err('abn')} success={abnEntity ? `Registered to ${abnEntity}` : undefined} hint="Add it now or later — it appears on your tax invoices.">
                    <Input data-testid="register-abn" inputMode="numeric" placeholder="51 824 753 556" value={form.data.abn} onChange={(e) => form.setData('abn', e.target.value)} onBlur={() => t('abn')} />
                </Field>
                <Field label="Password" error={err('password')} hint="At least 8 characters with letters and numbers.">
                    <Input data-testid="register-password" type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} onBlur={() => t('password')} />
                </Field>
                <Field label="Confirm password" error={err('password_confirmation')}>
                    <Input data-testid="register-password-confirmation" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} onBlur={() => t('password_confirmation')} />
                </Field>
                <div className="sm:col-span-2">
                    <div className="flex items-start gap-3">
                        <Checkbox id="terms" data-testid="register-terms" className="mt-0.5" checked={form.data.terms} onCheckedChange={(v) => form.setData('terms', v === true)} aria-invalid={err('terms') ? true : undefined} />
                        <Label htmlFor="terms" className="font-normal text-muted">
                            I agree to the{' '}
                            <Link href="/legal/terms" target="_blank" className="font-medium text-fg underline underline-offset-2">
                                Terms & Conditions
                            </Link>{' '}
                            and{' '}
                            <Link href="/legal/privacy" target="_blank" className="font-medium text-fg underline underline-offset-2">
                                Privacy Policy
                            </Link>
                            .
                        </Label>
                    </div>
                    {err('terms') && <p className="mt-1.5 text-sm text-danger" role="alert">{err('terms')}</p>}
                </div>
                <Button type="submit" size="lg" className="sm:col-span-2" loading={form.processing} data-testid="register-submit">
                    Create account
                </Button>
            </form>
            <p className="mt-8 text-center text-sm text-muted">
                Already have an account?{' '}
                <Link href="/login" className="font-semibold text-primary hover:underline">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
