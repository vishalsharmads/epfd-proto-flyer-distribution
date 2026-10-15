import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/form';
import { Tabs } from '@/components/ui/navigation';
import { Badge, Card, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Briefcase, Palette, Printer, Search, Send } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Job {
    key: string;
    id: number;
    type: 'campaign' | 'print' | 'design';
    reference: string;
    name: string;
    status: string;
    total_cents: number;
    detail: string;
    date: string | null;
    url: string;
}

export default function Jobs({ jobs, filter: initialFilter, q: initialQ }: { jobs: Job[]; filter: string; q: string }) {
    const [filter, setFilter] = useState(['all', 'distribution', 'print', 'design', 'drafts'].includes(initialFilter) ? initialFilter : 'all');
    const [q, setQ] = useState(initialQ);
    const shown = useMemo(() => {
        const s = q.trim().toLowerCase();
        return jobs.filter((j) => {
            if (filter === 'distribution' && j.type !== 'campaign') return false;
            if (filter === 'print' && j.type !== 'print') return false;
            if (filter === 'design' && j.type !== 'design') return false;
            if (filter === 'drafts' && j.status !== 'draft') return false;
            return !s || j.name.toLowerCase().includes(s) || j.reference.toLowerCase().includes(s);
        });
    }, [jobs, filter, q]);
    const counts = { all: jobs.length, distribution: jobs.filter((j) => j.type === 'campaign').length, print: jobs.filter((j) => j.type === 'print').length, design: jobs.filter((j) => j.type === 'design').length, drafts: jobs.filter((j) => j.status === 'draft').length };
    const sync = (f: string, query: string) => router.replace({ url: `/app/jobs?filter=${f}${query ? `&q=${encodeURIComponent(query)}` : ''}`, preserveState: true, preserveScroll: true } as never);

    return (
        <AppLayout title="My Jobs">
            <PageHeader title="My Jobs" description="Every distribution campaign, print order and design order in one place." />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <Tabs
                    value={filter}
                    onChange={(f) => {
                        setFilter(f);
                        sync(f, q);
                    }}
                    tabs={[
                        { value: 'all', label: 'All', count: counts.all },
                        { value: 'distribution', label: 'Distribution', count: counts.distribution },
                        { value: 'print', label: 'Print', count: counts.print },
                        { value: 'design', label: 'Design', count: counts.design },
                        { value: 'drafts', label: 'Drafts', count: counts.drafts },
                    ]}
                />
                <div className="relative mb-6 sm:w-72">
                    <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-subtle" aria-hidden />
                    <Input type="search" aria-label="Search jobs" placeholder="Search name or reference" className="pl-10" value={q} onChange={(e) => setQ(e.target.value)} onBlur={() => sync(filter, q)} data-testid="jobs-search" />
                </div>
            </div>
            {jobs.length === 0 ? (
                <EmptyState
                    icon={Briefcase}
                    title="No jobs yet"
                    description="Start a distribution campaign, a print order or a design — it'll show up here."
                    action={
                        <div className="flex flex-wrap justify-center gap-2">
                            <Button asChild><Link href="/app/campaigns/new">New Campaign</Link></Button>
                            <Button asChild variant="secondary"><Link href="/app/print/new">New Print Order</Link></Button>
                            <Button asChild variant="secondary"><Link href="/app/design/new">New Design</Link></Button>
                        </div>
                    }
                />
            ) : shown.length === 0 ? (
                <EmptyState icon={Search} title="No matching jobs" description="Try another filter or search term." action={<Button variant="secondary" onClick={() => { setQ(''); setFilter('all'); sync('all', ''); }}>Clear filters</Button>} />
            ) : (
                <Card className="divide-y divide-border">
                    {shown.map((j) => (
                        <Link key={j.key} href={j.url} className="flex items-center gap-4 px-4 py-3.5 transition-colors hover:bg-surface-2 sm:px-5" data-testid={`job-${j.key}`}>
                            <span className="grid size-10 shrink-0 place-items-center rounded-full bg-surface-2 text-muted" aria-label={j.type === 'campaign' ? 'Distribution' : j.type === 'design' ? 'Design' : 'Print'}>
                                {j.type === 'campaign' ? <Send className="size-4" /> : j.type === 'design' ? <Palette className="size-4" /> : <Printer className="size-4" />}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium">{j.name}</p>
                                <p className="truncate text-xs text-muted">{j.reference} · {j.detail}</p>
                            </div>
                            <span className="hidden text-sm text-muted md:block">{date(j.date)}</span>
                            <span className="tabular hidden w-24 text-right text-sm font-medium sm:block">{money(j.total_cents)}</span>
                            <span className="w-36 text-right">{j.status === 'draft' ? <Badge>Draft</Badge> : <StatusBadge status={j.status} />}</span>
                        </Link>
                    ))}
                </Card>
            )}
        </AppLayout>
    );
}
