import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/form';
import AuthLayout from '@/layouts/auth-layout';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const mismatch = form.data.password_confirmation.length > 0 && form.data.password !== form.data.password_confirmation;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };
    return (
        <AuthLayout title="Choose a new password" subtitle="Use at least 8 characters with letters and numbers.">
            <form onSubmit={submit} className="space-y-5" noValidate>
                <Field label="Email" error={form.errors.email}>
                    <Input type="email" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="New password" error={form.errors.password}>
                    <Input type="password" autoComplete="new-password" autoFocus value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <Field label="Confirm new password" error={mismatch ? 'Passwords do not match.' : undefined}>
                    <Input type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                </Field>
                <Button type="submit" size="lg" className="w-full" loading={form.processing} disabled={form.data.password.length < 8 || mismatch}>
                    Reset password
                </Button>
            </form>
        </AuthLayout>
    );
}
