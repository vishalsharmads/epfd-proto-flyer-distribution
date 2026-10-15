import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/surface';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type ClipboardEvent, type FormEvent, type KeyboardEvent } from 'react';

export default function VerifyOtp({ email, resend_in }: { email: string; resend_in: number; expires_at: string | null }) {
    const form = useForm({ code: '' });
    const [digits, setDigits] = useState<string[]>(Array(6).fill(''));
    const [cooldown, setCooldown] = useState(resend_in);
    const [resending, setResending] = useState(false);
    const refs = useRef<(HTMLInputElement | null)[]>([]);

    useEffect(() => setCooldown(resend_in), [resend_in]);
    useEffect(() => {
        if (cooldown <= 0) return;
        const t = setTimeout(() => setCooldown((c) => c - 1), 1000);
        return () => clearTimeout(t);
    }, [cooldown]);

    const code = digits.join('');
    useEffect(() => form.setData('code', code), [code]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (i: number, v: string) => {
        const d = v.replace(/\D/g, '');
        if (d.length > 1) return fill(d, i);
        const next = [...digits];
        next[i] = d;
        setDigits(next);
        if (d && i < 5) refs.current[i + 1]?.focus();
    };
    const fill = (d: string, from = 0) => {
        const next = [...digits];
        d.slice(0, 6 - from)
            .split('')
            .forEach((c, k) => (next[from + k] = c));
        setDigits(next);
        refs.current[Math.min(5, from + d.length)]?.focus();
    };
    const onKey = (i: number, e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace' && !digits[i] && i > 0) refs.current[i - 1]?.focus();
        if (e.key === 'ArrowLeft' && i > 0) refs.current[i - 1]?.focus();
        if (e.key === 'ArrowRight' && i < 5) refs.current[i + 1]?.focus();
    };
    const onPaste = (e: ClipboardEvent) => {
        const d = e.clipboardData.getData('text').replace(/\D/g, '');
        if (d) {
            e.preventDefault();
            fill(d);
        }
    };
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (code.length !== 6) return;
        form.post('/verify', { onError: () => setDigits(Array(6).fill('')) });
    };
    useEffect(() => {
        if (code.length === 6 && !form.processing) submit();
    }, [code]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <AuthLayout title="Check your email" subtitle={<>We sent a 6-digit code to <strong className="text-fg">{email}</strong>. It expires in 10 minutes.</>}>
            <form onSubmit={submit} className="space-y-6">
                <fieldset>
                    <legend className="mb-3 text-sm font-medium">Verification code</legend>
                    <div className="flex justify-between gap-2" onPaste={onPaste}>
                        {digits.map((d, i) => (
                            <input
                                key={i}
                                ref={(el) => {
                                    refs.current[i] = el;
                                }}
                                data-testid={`otp-${i}`}
                                aria-label={`Digit ${i + 1}`}
                                inputMode="numeric"
                                autoComplete={i === 0 ? 'one-time-code' : 'off'}
                                maxLength={6}
                                autoFocus={i === 0}
                                value={d}
                                onChange={(e) => set(i, e.target.value)}
                                onKeyDown={(e) => onKey(i, e)}
                                aria-invalid={form.errors.code ? true : undefined}
                                className={cn('tabular h-14 w-full min-w-0 rounded-[12px] border bg-surface text-center text-2xl font-semibold shadow-xs transition focus:border-primary focus:ring-4 focus:ring-primary/15 focus:outline-none', form.errors.code ? 'border-danger' : 'border-border-strong')}
                            />
                        ))}
                    </div>
                </fieldset>
                {form.errors.code && <Alert tone="danger">{form.errors.code}</Alert>}
                <Button type="submit" size="lg" className="w-full" loading={form.processing} disabled={code.length !== 6} data-testid="otp-submit">
                    Verify email
                </Button>
            </form>
            <div className="mt-6 flex items-center justify-between text-sm">
                <span className="text-muted">Didn't get it? Check spam.</span>
                <Button
                    variant="link"
                    disabled={cooldown > 0 || resending}
                    data-testid="otp-resend"
                    onClick={() =>
                        router.post('/verify/resend', {}, {
                            preserveScroll: true,
                            onStart: () => setResending(true),
                            onFinish: () => setResending(false),
                            onSuccess: () => setCooldown(60),
                        })
                    }
                >
                    {cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend code'}
                </Button>
            </div>
            <p className="mt-8 text-center text-sm text-muted">
                Wrong email?{' '}
                <button type="button" onClick={() => router.post('/logout')} className="font-semibold text-primary hover:underline">
                    Sign out and start again
                </button>
            </p>
        </AuthLayout>
    );
}
