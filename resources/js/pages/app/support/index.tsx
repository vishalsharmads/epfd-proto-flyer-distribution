import { Button } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Card, CardBody, CardHeader, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/react';
import { LifeBuoy, Mail, Phone } from 'lucide-react';

interface Thread { id: number; subject: string; status: string; channel: string; messages_count: number; last_message_at: string | null }

export default function Support({ threads }: { threads: Thread[] }) {
    const { brand } = usePage<SharedProps>().props;
    const form = useForm({ subject: '', body: '' });
    return (
        <AppLayout title="Support">
            <PageHeader title="Support" description="We usually reply within one business day." />
            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
                <Card>
                    <CardHeader title="Your requests" />
                    {threads.length === 0 ? (
                        <CardBody><EmptyState icon={LifeBuoy} title="No requests yet" description="Need a hand? Send us a message using the form." className="py-8" /></CardBody>
                    ) : (
                        <ul className="divide-y divide-border">
                            {threads.map((t) => (
                                <li key={t.id}>
                                    <Link href={`/app/support/${t.id}`} className="flex items-center gap-3 px-5 py-3.5 hover:bg-surface-2">
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">{t.subject}</span>
                                            <span className="text-xs text-muted">{t.channel === 'job' ? 'Job message' : 'Support'} · {t.messages_count} message{t.messages_count === 1 ? '' : 's'} · {date(t.last_message_at)}</span>
                                        </span>
                                        <StatusBadge status={t.status} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="New request" />
                        <CardBody>
                            <form onSubmit={(e) => { e.preventDefault(); form.post('/app/support'); }} className="space-y-4">
                                <Field label="Subject" error={form.errors.subject}><Input data-testid="support-subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} maxLength={160} /></Field>
                                <Field label="How can we help?" error={form.errors.body}><Textarea data-testid="support-body" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} maxLength={4000} /></Field>
                                <Button type="submit" variant="ink" loading={form.processing} disabled={form.data.subject.trim().length < 3 || form.data.body.trim().length < 5}>Send request</Button>
                            </form>
                        </CardBody>
                    </Card>
                    <Card className="space-y-2 p-5 text-sm">
                        <p className="font-medium">Prefer to talk?</p>
                        <a href={`tel:${brand.phone.replace(/\s/g, '')}`} className="flex items-center gap-2 text-muted hover:text-fg"><Phone className="size-4" aria-hidden /> {brand.phone}</a>
                        <a href={`mailto:${brand.email}`} className="flex items-center gap-2 text-muted hover:text-fg"><Mail className="size-4" aria-hidden /> {brand.email}</a>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
