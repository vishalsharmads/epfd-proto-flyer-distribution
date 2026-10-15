import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label } from '@/components/ui/form';
import { Alert } from '@/components/ui/surface';
import AuthLayout from '@/layouts/auth-layout';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface DemoAccount {
    email: string;
    label: string;
    hint: string;
    admin: boolean;
}

export default function Login({ admin, status, demoAccounts = [] }: { admin: boolean; status?: string | null; demoAccounts?: DemoAccount[] }) {
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
            {demoAccounts.length > 0 && (
                <section aria-labelledby="demo-accounts" className="mt-8 rounded-[16px] border border-dashed border-border-strong bg-surface-2/50 p-4">
                    <h2 id="demo-accounts" className="text-sm font-semibold text-fg">
                        Demo accounts
                    </h2>
                    <p className="mt-0.5 text-xs text-muted">
                        Click to fill in the form. Password for all: <code className="font-mono">password</code>
                    </p>
                    <ul className="mt-3 grid gap-1.5 sm:grid-cols-2">
                        {[...demoAccounts].sort((a, b) => Number(a.admin !== admin) - Number(b.admin !== admin)).map((a) => (
                            <li key={a.email}>
                                <button
                                    type="button"
                                    data-testid={`demo-${a.email}`}
                                    onClick={() => form.setData({ email: a.email, password: 'password', remember: false })}
                                    className="w-full rounded-[10px] border border-border bg-surface px-3 py-2 text-left transition-colors hover:border-border-strong hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-focus"
                                >
                                    <span className="flex items-center justify-between gap-2 text-sm font-medium text-fg">
                                        {a.label}
                                        <span className="rounded-full bg-surface-2 px-1.5 text-[10px] font-semibold tracking-wide text-muted uppercase">{a.admin ? 'Staff' : 'Business'}</span>
                                    </span>
                                    <span className="block truncate text-xs text-muted">{a.email}</span>
                                    <span className="block truncate text-xs text-subtle">{a.hint}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
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
