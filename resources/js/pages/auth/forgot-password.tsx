import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/form';
import { Alert } from '@/components/ui/surface';
import AuthLayout from '@/layouts/auth-layout';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ForgotPassword({ status }: { status?: string | null }) {
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password');
    };
    return (
        <AuthLayout title="Forgot your password?" subtitle="Enter your email and we'll send you a reset link.">
            {status && (
                <Alert tone="success" className="mb-6">
                    {status}
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-5" noValidate>
                <Field label="Email" error={form.errors.email}>
                    <Input type="email" autoComplete="email" autoFocus value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} data-testid="forgot-email" />
                </Field>
                <Button type="submit" size="lg" className="w-full" loading={form.processing} disabled={!form.data.email.includes('@')}>
                    Email reset link
                </Button>
            </form>
            <p className="mt-8 text-center text-sm text-muted">
                Remembered it?{' '}
                <Link href="/login" className="font-semibold text-primary hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
