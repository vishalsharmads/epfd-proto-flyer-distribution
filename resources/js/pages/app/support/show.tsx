import { MessageThread } from '@/components/app/misc';
import type { ThreadT } from '@/components/app/types';
import { PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export default function SupportShow({ thread, related }: { thread: ThreadT; related: { reference: string; url: string } | null }) {
    return (
        <AppLayout title={thread.subject}>
            <PageHeader
                eyebrow={<Link href="/app/support" className="hover:underline">Support</Link>}
                title={thread.subject}
                description={<span className="flex flex-wrap items-center gap-2">Opened {date(thread.created_at)} <StatusBadge status={thread.status} />{related && <Link href={related.url} className="text-primary hover:underline">View job {related.reference}</Link>}</span>}
            />
            <div className="max-w-3xl">
                <MessageThread thread={thread} action={`/app/support/${thread.id}/messages`} title="Conversation" />
            </div>
        </AppLayout>
    );
}
