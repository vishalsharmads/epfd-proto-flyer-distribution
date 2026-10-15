import { Button } from '@/components/ui/button';
import { Field, Textarea } from '@/components/ui/form';
import { Card, CardBody, CardHeader, EmptyState } from '@/components/ui/surface';
import { cn, dateTime } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { MessageSquare, Star } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import type { ThreadT } from './types';

/** Tiny safe Markdown renderer (headings, lists, paragraphs, **bold**) — no raw HTML. */
export function Markdown({ source }: { source: string }) {
    const inline = (t: string): ReactNode[] => t.split(/(\*\*[^*]+\*\*)/g).map((p, i) => (p.startsWith('**') && p.endsWith('**') ? <strong key={i}>{p.slice(2, -2)}</strong> : <Fragment key={i}>{p}</Fragment>));
    const blocks = source.replace(/\r/g, '').split(/\n{2,}/);
    return (
        <div className="space-y-4 leading-relaxed text-fg/90">
            {blocks.map((b, i) => {
                const lines = b.split('\n');
                if (/^#{1,3} /.test(b)) {
                    const level = b.match(/^#+/)![0].length;
                    const text = lines[0].replace(/^#+ /, '');
                    const rest = lines.slice(1).join('\n');
                    return (
                        <Fragment key={i}>
                            {level <= 2 ? <h2 className="pt-2 text-lg font-semibold text-fg">{inline(text)}</h2> : <h3 className="font-semibold text-fg">{inline(text)}</h3>}
                            {rest && <p>{inline(rest)}</p>}
                        </Fragment>
                    );
                }
                if (lines.every((l) => /^[-*] /.test(l))) {
                    return (
                        <ul key={i} className="list-disc space-y-1.5 pl-5">
                            {lines.map((l, j) => (
                                <li key={j}>{inline(l.slice(2))}</li>
                            ))}
                        </ul>
                    );
                }
                return <p key={i}>{inline(b)}</p>;
            })}
        </div>
    );
}

export function StarRating({ value, onChange, size = 'md', readOnly }: { value: number; onChange?: (v: number) => void; size?: 'sm' | 'md'; readOnly?: boolean }) {
    const [hover, setHover] = useState(0);
    const shown = hover || value;
    return (
        <div role={readOnly ? 'img' : 'radiogroup'} aria-label={readOnly ? `${value} out of 5 stars` : 'Rating'} className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) =>
                readOnly ? (
                    <Star key={n} className={cn(size === 'sm' ? 'size-4' : 'size-6', n <= value ? 'fill-accent-400 text-accent-400' : 'text-border-strong')} aria-hidden />
                ) : (
                    <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={`${n} star${n > 1 ? 's' : ''}`} onMouseEnter={() => setHover(n)} onMouseLeave={() => setHover(0)} onClick={() => onChange?.(n)} className="rounded-md p-0.5 focus-visible:outline-2">
                        <Star className={cn('size-7 transition-colors', n <= shown ? 'fill-accent-400 text-accent-400' : 'text-border-strong')} />
                    </button>
                ),
            )}
        </div>
    );
}

export function RatingCard({ action, rating, comment }: { action: string; rating: number | null; comment: string | null }) {
    const form = useForm({ rating: rating ?? 0, comment: comment ?? '' });
    return (
        <Card>
            <CardHeader title="Rate this job" description={rating ? 'Thanks for your feedback. You can update it any time.' : 'How did we do? Your feedback helps our distributors.'} />
            <CardBody className="space-y-4">
                <StarRating value={form.data.rating} onChange={(v) => form.setData('rating', v)} />
                <Field label="Comment" optional error={form.errors.comment}>
                    <Textarea value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} maxLength={1000} placeholder="Anything we should know?" />
                </Field>
                {form.errors.rating && <p className="text-sm text-danger">{form.errors.rating}</p>}
                <Button variant="secondary" disabled={form.data.rating < 1} loading={form.processing} onClick={() => form.post(action, { preserveScroll: true })}>
                    {rating ? 'Update rating' : 'Submit rating'}
                </Button>
            </CardBody>
        </Card>
    );
}

/** Message list + reply box (job messages and support threads). Staff messages show "EPFD team". */
export function MessageThread({ thread, action, title = 'Messages', description, emptyText = 'No messages yet. Ask us anything about this job.' }: { thread: ThreadT | null; action: string; title?: string; description?: ReactNode; emptyText?: string }) {
    const form = useForm({ body: '' });
    return (
        <Card>
            <CardHeader title={title} description={description} />
            <CardBody className="space-y-5">
                {!thread || thread.messages.length === 0 ? (
                    <EmptyState icon={MessageSquare} title="No messages yet" description={emptyText} className="py-8" />
                ) : (
                    <ol className="space-y-3" aria-label="Messages">
                        {thread.messages.map((m) => (
                            <li key={m.id} className={cn('flex', m.from_staff ? 'justify-start' : 'justify-end')}>
                                <div className={cn('max-w-[85%] rounded-[14px] px-4 py-3 text-sm', m.from_staff ? 'rounded-tl-sm border border-border bg-surface-2' : 'rounded-tr-sm bg-ink-900 text-white dark:bg-white dark:text-ink-900')}>
                                    <p className={cn('mb-1 text-xs font-semibold', m.from_staff ? 'text-primary' : 'opacity-70')}>
                                        {m.author} · <span className="font-normal">{dateTime(m.created_at)}</span>
                                    </p>
                                    <p className="whitespace-pre-line">{m.body}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(action, { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                    className="space-y-3"
                >
                    <Field label="Your message" error={form.errors.body}>
                        <Textarea value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} maxLength={4000} placeholder="Type your message…" />
                    </Field>
                    <Button type="submit" variant="ink" loading={form.processing} disabled={form.data.body.trim().length < 2}>
                        Send message
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}
