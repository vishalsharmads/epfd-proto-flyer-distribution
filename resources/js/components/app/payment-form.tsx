import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label, Select, Switch } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import { cn, money } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { CreditCard, Lock } from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';

export interface SavedCardT {
    id: number;
    brand: string;
    last4: string;
    exp_month: number;
    exp_year: number;
    holder_name: string;
    is_default: boolean;
}

export interface PaymentContext {
    saved_cards: SavedCardT[];
    credits_cents: number;
    can_pay: boolean;
    business_status: string;
    billing: { company: string; abn: string | null; address: string };
    test_card: string;
}

export function luhn(num: string): boolean {
    const d = num.replace(/\D/g, '');
    if (d.length < 12 || d.length > 19) return false;
    let sum = 0;
    let alt = false;
    for (let i = d.length - 1; i >= 0; i--) {
        let n = Number(d[i]);
        if (alt) {
            n *= 2;
            if (n > 9) n -= 9;
        }
        sum += n;
        alt = !alt;
    }
    return sum % 10 === 0;
}

export function cardBrand(num: string): 'visa' | 'mastercard' | 'amex' | null {
    const d = num.replace(/\D/g, '');
    if (/^4/.test(d)) return 'visa';
    if (/^(5[1-5]|2[2-7])/.test(d)) return 'mastercard';
    if (/^3[47]/.test(d)) return 'amex';
    return null;
}

const brandLabel: Record<string, string> = { visa: 'Visa', mastercard: 'Mastercard', amex: 'Amex', card: 'Card' };

function formatCard(v: string) {
    const d = v.replace(/\D/g, '').slice(0, 19);
    return cardBrand(d) === 'amex' ? d.replace(/^(\d{0,4})(\d{0,6})(\d{0,5}).*/, (_, a, b, c) => [a, b, c].filter(Boolean).join(' ')) : d.replace(/(\d{4})(?=\d)/g, '$1 ');
}

function formatExp(v: string) {
    const d = v.replace(/\D/g, '').slice(0, 4);
    return d.length > 2 ? `${d.slice(0, 2)}/${d.slice(2)}` : d;
}

function expValid(v: string) {
    const m = v.match(/^(\d{2})\/(\d{2})$/);
    if (!m) return false;
    const mm = Number(m[1]);
    const yy = 2000 + Number(m[2]);
    if (mm < 1 || mm > 12) return false;
    const now = new Date();
    return yy > now.getFullYear() || (yy === now.getFullYear() && mm >= now.getMonth() + 1);
}

/**
 * Billing + payment + acknowledgements + "Pay $X". Shared by the campaign and print checkout.
 * Posts with Inertia; server errors arrive as page errors (payment, card.*, billing.*, accept_terms…).
 */
export function PaymentForm({
    action,
    totalCents,
    payment,
    extraAcks,
    extraValid = true,
    extraPayload = {},
    blockedReason,
    termsLabel,
}: {
    action: string;
    totalCents: number;
    payment: PaymentContext;
    extraAcks?: ReactNode;
    extraValid?: boolean;
    extraPayload?: Record<string, unknown>;
    blockedReason?: string | null;
    termsLabel?: ReactNode;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const defaultCard = payment.saved_cards.find((c) => c.is_default) ?? payment.saved_cards[0];
    const [billing, setBilling] = useState({ company: payment.billing.company ?? '', abn: payment.billing.abn ?? '', address: payment.billing.address ?? '' });
    const [method, setMethod] = useState<string>(defaultCard ? String(defaultCard.id) : 'new');
    const [card, setCard] = useState({ number: '', exp: '', cvc: '', name: '', country: 'AU' });
    const [touched, setTouched] = useState<Record<string, boolean>>({});
    const [saveCard, setSaveCard] = useState(false);
    const [useCredits, setUseCredits] = useState(false);
    const [accept, setAccept] = useState(false);
    const [processing, setProcessing] = useState(false);
    const idem = useRef(crypto.randomUUID?.() ?? String(Date.now()));

    const credits = useCredits ? Math.min(payment.credits_cents, totalCents) : 0;
    const toCharge = totalCents - credits;
    const needCard = toCharge > 0;
    const brand = cardBrand(card.number);

    const cardErrors = useMemo(() => {
        const e: Record<string, string> = {};
        if (!luhn(card.number)) e.number = 'Enter a valid card number.';
        if (!expValid(card.exp)) e.exp = 'Enter a future expiry date as MM/YY.';
        if (!/^\d{3,4}$/.test(card.cvc) || (brand === 'amex' ? card.cvc.length !== 4 : card.cvc.length !== 3)) e.cvc = brand === 'amex' ? 'Enter the 4-digit code.' : 'Enter the 3-digit code.';
        if (card.name.trim().length < 2) e.name = 'Enter the name on the card.';
        return e;
    }, [card, brand]);

    const billingValid = billing.company.trim().length > 1 && billing.address.trim().length > 4;
    const cardOk = !needCard || method !== 'new' || Object.keys(cardErrors).length === 0;
    const valid = billingValid && cardOk && accept && extraValid && !blockedReason && payment.can_pay && totalCents > 0;

    const fieldErr = (k: keyof typeof card) => (touched[k] ? cardErrors[k] : undefined) ?? errors[`card.${k}`];

    const submit = () => {
        setTouched({ number: true, exp: true, cvc: true, name: true });
        if (!valid) return;
        router.post(
            action,
            {
                billing,
                saved_card_id: needCard && method !== 'new' ? Number(method) : null,
                card: needCard && method === 'new' ? card : null,
                save_card: method === 'new' && saveCard,
                use_credits: useCredits,
                accept_terms: accept,
                idempotency_key: idem.current,
                ...extraPayload,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: () => {
                    idem.current = crypto.randomUUID?.() ?? String(Date.now());
                },
            },
        );
    };

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader title="Billing details" description="Shown on your tax invoice." />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field label="Company" error={errors['billing.company']} className="sm:col-span-1">
                        <Input value={billing.company} onChange={(e) => setBilling({ ...billing, company: e.target.value })} autoComplete="organization" />
                    </Field>
                    <Field label="ABN" optional error={errors['billing.abn']} hint="Printed on the invoice if provided.">
                        <Input value={billing.abn} inputMode="numeric" onChange={(e) => setBilling({ ...billing, abn: e.target.value })} placeholder="11 digits" />
                    </Field>
                    <Field label="Billing address" error={errors['billing.address']} className="sm:col-span-2">
                        <Input value={billing.address} onChange={(e) => setBilling({ ...billing, address: e.target.value })} autoComplete="street-address" />
                    </Field>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Payment" description={<span className="inline-flex items-center gap-1.5"><Lock className="size-3.5" aria-hidden /> Payments are processed securely. We never store full card numbers.</span>} />
                <CardBody className="space-y-4">
                    {payment.credits_cents > 0 && (
                        <label className="flex items-center justify-between gap-4 rounded-[12px] border border-border px-4 py-3">
                            <span>
                                <span className="block text-sm font-medium">Use credits</span>
                                <span className="text-sm text-muted">{money(payment.credits_cents)} available</span>
                            </span>
                            <Switch checked={useCredits} onCheckedChange={setUseCredits} aria-label="Use credits" />
                        </label>
                    )}
                    {!needCard ? (
                        <Alert tone="success">Your credits cover the full amount — no card needed.</Alert>
                    ) : (
                        <fieldset className="space-y-2">
                            <legend className="sr-only">Payment method</legend>
                            {payment.saved_cards.map((c) => (
                                <label key={c.id} className={cn('flex cursor-pointer items-center gap-3 rounded-[12px] border px-4 py-3 transition-colors', method === String(c.id) ? 'border-fg bg-surface-2' : 'border-border hover:bg-surface-2')}>
                                    <input type="radio" name="method" className="accent-ink-900" checked={method === String(c.id)} onChange={() => setMethod(String(c.id))} />
                                    <CreditCard className="size-4 text-muted" aria-hidden />
                                    <span className="flex-1 text-sm">
                                        <span className="font-medium">{brandLabel[c.brand] ?? c.brand}</span> •••• {c.last4}
                                        <span className="ml-2 text-muted">
                                            exp {String(c.exp_month).padStart(2, '0')}/{String(c.exp_year).slice(-2)}
                                        </span>
                                    </span>
                                    {c.is_default && <span className="text-xs text-muted">Default</span>}
                                </label>
                            ))}
                            {payment.saved_cards.length > 0 && (
                                <label className={cn('flex cursor-pointer items-center gap-3 rounded-[12px] border px-4 py-3', method === 'new' ? 'border-fg bg-surface-2' : 'border-border hover:bg-surface-2')}>
                                    <input type="radio" name="method" className="accent-ink-900" checked={method === 'new'} onChange={() => setMethod('new')} />
                                    <span className="text-sm font-medium">New card</span>
                                </label>
                            )}
                            {method === 'new' && (
                                <div className="grid gap-4 pt-2 sm:grid-cols-6">
                                    <Field label={<span className="flex items-center justify-between">Card number{brand && <span className="rounded-md bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted">{brandLabel[brand]}</span>}</span>} error={fieldErr('number')} className="sm:col-span-6" hint={`Test card: ${payment.test_card} · any future expiry and CVC`}>
                                        <Input data-testid="card-number" inputMode="numeric" autoComplete="cc-number" placeholder="1234 1234 1234 1234" value={card.number} onChange={(e) => setCard({ ...card, number: formatCard(e.target.value) })} onBlur={() => setTouched((t) => ({ ...t, number: true }))} />
                                    </Field>
                                    <Field label="Expiry" error={fieldErr('exp')} className="sm:col-span-2">
                                        <Input data-testid="card-expiry" inputMode="numeric" autoComplete="cc-exp" placeholder="MM/YY" value={card.exp} onChange={(e) => setCard({ ...card, exp: formatExp(e.target.value) })} onBlur={() => setTouched((t) => ({ ...t, exp: true }))} />
                                    </Field>
                                    <Field label="CVC" error={fieldErr('cvc')} className="sm:col-span-2">
                                        <Input data-testid="card-cvc" inputMode="numeric" autoComplete="cc-csc" placeholder={brand === 'amex' ? '1234' : '123'} value={card.cvc} onChange={(e) => setCard({ ...card, cvc: e.target.value.replace(/\D/g, '').slice(0, 4) })} onBlur={() => setTouched((t) => ({ ...t, cvc: true }))} />
                                    </Field>
                                    <Field label="Country" className="sm:col-span-2">
                                        <Select value={card.country} onChange={(e) => setCard({ ...card, country: e.target.value })} autoComplete="country">
                                            {[['AU', 'Australia'], ['NZ', 'New Zealand'], ['US', 'United States'], ['UK', 'United Kingdom'], ['CA', 'Canada'], ['IE', 'Ireland'], ['SG', 'Singapore']].map(([v, l]) => (
                                                <option key={v} value={v}>
                                                    {l}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Name on card" error={fieldErr('name')} className="sm:col-span-6">
                                        <Input data-testid="card-name" autoComplete="cc-name" value={card.name} onChange={(e) => setCard({ ...card, name: e.target.value })} onBlur={() => setTouched((t) => ({ ...t, name: true }))} />
                                    </Field>
                                    <div className="flex items-center gap-2.5 sm:col-span-6">
                                        <Checkbox id="save-card" checked={saveCard} onCheckedChange={(v) => setSaveCard(v === true)} />
                                        <Label htmlFor="save-card" className="font-normal text-muted">
                                            Save this card for later
                                        </Label>
                                    </div>
                                </div>
                            )}
                        </fieldset>
                    )}
                </CardBody>
            </Card>

            <div className="space-y-3">
                {extraAcks}
                <div className="flex items-start gap-3">
                    <Checkbox id="accept-terms" data-testid="accept-terms" checked={accept} onCheckedChange={(v) => setAccept(v === true)} aria-invalid={errors.accept_terms ? true : undefined} className="mt-0.5" />
                    <Label htmlFor="accept-terms" className="font-normal text-muted">
                        {termsLabel ?? (
                            <>
                                I agree to the{' '}
                                <Link href="/legal/terms" target="_blank" className="font-medium text-fg underline underline-offset-2">
                                    Terms & Conditions
                                </Link>
                                ,{' '}
                                <Link href="/legal/community-guidelines" target="_blank" className="font-medium text-fg underline underline-offset-2">
                                    Community Guidelines
                                </Link>{' '}
                                and{' '}
                                <Link href="/legal/privacy" target="_blank" className="font-medium text-fg underline underline-offset-2">
                                    Privacy Policy
                                </Link>
                                .
                            </>
                        )}
                    </Label>
                </div>
                {errors.accept_terms && <p className="text-sm text-danger">{errors.accept_terms}</p>}
            </div>

            {errors.payment && (
                <Alert tone="danger" title="Payment not completed">
                    {errors.payment}
                </Alert>
            )}
            {(blockedReason || !payment.can_pay) && (
                <Alert tone="warning" title="Payment unavailable">
                    {blockedReason ?? 'Your business account is awaiting approval. Your draft is saved — you can pay as soon as an EPFD admin approves your account.'}
                </Alert>
            )}

            <Button data-testid="pay-button" size="lg" className="w-full" onClick={submit} loading={processing} disabled={!valid}>
                <Lock /> Pay {money(toCharge)}
                {credits > 0 && <span className="font-normal opacity-80">({money(credits)} credits applied)</span>}
            </Button>
            {!valid && !processing && payment.can_pay && !blockedReason && <p className="text-center text-xs text-muted">Complete the billing and payment details and accept the terms to continue.</p>}
        </div>
    );
}
