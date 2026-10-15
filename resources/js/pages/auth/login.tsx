import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label } from '@/components/ui/form';
import { Alert } from '@/components/ui/surface';
import AuthLayout from '@/layouts/auth-layout';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login({ admin, status }: { admin: boolean; status?: string | null }) {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title={admin ? 'EPFD admin sign in' : 'Welcome back'} subtitle={admin ? 'Staff access for operations, finance and support.' : 'Sign in to book print and letterbox distribution.'}>
            {status && (
                <Alert tone="success" className="mb-6">
                    {status}
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-5" noValidate>
                <Field label="Email" error={form.errors.email}>
                    <Input type="email" name="email" autoComplete="email" autoFocus required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field
                    label={
                        <span className="flex items-center justify-between">
                            Password
                            {!admin && (
                                <Link href="/forgot-password" className="text-sm font-medium text-primary hover:underline">
                                    Forgot password?
                                </Link>
                            )}
                        </span>
                    }
                    error={form.errors.password}
                >
                    <Input type="password" name="password" autoComplete="current-password" required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <div className="flex items-center gap-2.5">
                    <Checkbox id="remember" checked={form.data.remember} onCheckedChange={(v) => form.setData('remember', v === true)} />
                    <Label htmlFor="remember" className="font-normal text-muted">
                        Keep me signed in
                    </Label>
                </div>
                <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                    Sign in
                </Button>
            </form>
            {!admin && (
                <p className="mt-8 text-center text-sm text-muted">
                    New to EPFD?{' '}
                    <Link href="/register" className="font-semibold text-primary hover:underline">
                        Create a business account
                    </Link>
                </p>
            )}
        </AuthLayout>
    );
}
