import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { money } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export interface RefundablePayment {
    id: number;
    amount_cents: number;
    credits_applied_cents: number;
    refunded_cents: number;
}

/** Full/partial refund to card or as account credit (CheckoutService::refund). */
export function RefundDialog({ payment, open, onOpenChange }: { payment: RefundablePayment | null; open: boolean; onOpenChange: (o: boolean) => void }) {
    const max = payment ? payment.amount_cents + payment.credits_applied_cents - payment.refunded_cents : 0;
    const form = useForm({ amount: '', method: 'card', reason: '' });
    useEffect(() => {
        if (open) {
            form.setData({ amount: (max / 100).toFixed(2), method: 'card', reason: '' });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, max]);
    const amountCents = Math.round(parseFloat(form.data.amount || '0') * 100);
    const tooMuch = amountCents > max;
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title="Issue a refund" description={`Up to ${money(max)} can be refunded on this payment.`}>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!payment) return;
                        form.post(`/admin/payments/${payment.id}/refund`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Amount (AUD)" error={tooMuch ? `Maximum ${money(max)}` : form.errors.amount} hint={amountCents === max ? 'Full refund' : 'Partial refund'}>
                            <Input type="number" step="0.01" min="0.01" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        </Field>
                        <Field label="Refund to" error={form.errors.method}>
                            <Select value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                                <option value="card">Original card</option>
                                <option value="credit">Account credit</option>
                            </Select>
                        </Field>
                    </div>
                    <Field label="Reason" error={form.errors.reason}>
                        <Textarea rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Customer cancelled before printing" />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="danger" loading={form.processing} disabled={tooMuch || amountCents <= 0 || form.data.reason.trim().length < 3}>
                            Refund {amountCents > 0 ? money(amountCents) : ''}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
