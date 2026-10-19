import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Stripe } from '@stripe/stripe-js';
import { useEffect, type MutableRefObject } from 'react';

/** Turns the card in the Payment Element into a PaymentMethod id, or throws a message to show the customer. */
export type CardTokenizer = () => Promise<string>;

const stripes = new Map<string, Promise<Stripe | null>>();

/** One Stripe.js instance per publishable key, loaded on first use. */
export function stripeFor(publishableKey: string): Promise<Stripe | null> {
    if (!stripes.has(publishableKey)) stripes.set(publishableKey, loadStripe(publishableKey));
    return stripes.get(publishableKey)!;
}

/**
 * Stripe Payment Element for a new card. The card number never touches our server: on submit,
 * `tokenizer.current()` returns a PaymentMethod id that the checkout posts instead.
 */
export function StripeCard({
    publishableKey,
    amountCents,
    tokenizer,
}: {
    publishableKey: string;
    amountCents: number;
    tokenizer: MutableRefObject<CardTokenizer | null>;
}) {
    return (
        <Elements
            stripe={stripeFor(publishableKey)}
            options={{
                mode: 'payment',
                amount: Math.max(amountCents, 50),
                currency: 'aud',
                paymentMethodCreation: 'manual',
                allowedPaymentMethodTypes: ['card'],
                appearance: { theme: 'stripe', variables: { borderRadius: '10px' } },
            }}
        >
            <CardFields tokenizer={tokenizer} />
        </Elements>
    );
}

function CardFields({ tokenizer }: { tokenizer: MutableRefObject<CardTokenizer | null> }) {
    const stripe = useStripe();
    const elements = useElements();

    useEffect(() => {
        tokenizer.current = async () => {
            if (!stripe || !elements) throw new Error('The card form is still loading. Try again in a moment.');
            const { error: submitError } = await elements.submit();
            if (submitError) throw new Error(submitError.message ?? 'Check your card details.');
            const { error, paymentMethod } = await stripe.createPaymentMethod({ elements });
            if (error) throw new Error(error.message ?? 'Your card could not be used.');
            return paymentMethod.id;
        };
        return () => {
            tokenizer.current = null;
        };
    }, [stripe, elements, tokenizer]);

    return <PaymentElement options={{ layout: 'tabs', wallets: { applePay: 'never', googlePay: 'never' } }} />;
}
