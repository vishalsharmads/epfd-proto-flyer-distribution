import { ConfirmDialog, useAction } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Field, Input, Label, Switch, Textarea } from '@/components/ui/form';
import { Tabs } from '@/components/ui/navigation';
import { Badge, Card, CardBody, EmptyState } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { FileText, Plus, Trash2 } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';

interface Page {
    id: number;
    type: 'faq' | 'legal';
    slug: string;
    title: string;
    body: string;
    sort: number;
    is_published: boolean;
    updated_at: string;
}

/** Tiny, safe markdown-ish renderer (headings, lists, bold, italics, links, paragraphs). No HTML injection. */
function inline(s: string): ReactNode[] {
    const out: ReactNode[] = [];
    const re = /(\*\*[^*]+\*\*|\*[^*]+\*|\[[^\]]+\]\((https?:\/\/|\/)[^)]+\))/g;
    let last = 0;
    let m: RegExpExecArray | null;
    let i = 0;
    while ((m = re.exec(s))) {
        out.push(s.slice(last, m.index));
        const t = m[0];
        if (t.startsWith('**')) out.push(<strong key={i++}>{t.slice(2, -2)}</strong>);
        else if (t.startsWith('[')) {
            const [, label, href] = t.match(/^\[([^\]]+)\]\(([^)]+)\)$/) ?? [];
            out.push(<a key={i++} href={href} className="text-primary underline">{label}</a>);
        } else out.push(<em key={i++}>{t.slice(1, -1)}</em>);
        last = m.index + t.length;
    }
    out.push(s.slice(last));
    return out;
}
export function MarkdownPreview({ source }: { source: string }) {
    const blocks = source.replace(/\r/g, '').split(/\n{2,}/);
    return (
        <div className="space-y-3 text-sm leading-relaxed">
            {blocks.map((b, i) => {
                const lines = b.split('\n');
                if (/^#{1,3} /.test(b)) {
                    const level = b.match(/^#+/)![0].length;
                    const cls = level === 1 ? 'text-xl font-semibold' : level === 2 ? 'text-lg font-semibold' : 'font-semibold';
                    return <p key={i} role="heading" aria-level={level + 1} className={cls}>{inline(b.replace(/^#+ /, ''))}</p>;
                }
                if (lines.every((l) => /^\s*[-*] /.test(l))) return <ul key={i} className="list-disc space-y-1 pl-5">{lines.map((l, j) => <li key={j}>{inline(l.replace(/^\s*[-*] /, ''))}</li>)}</ul>;
                if (lines.every((l) => /^\s*\d+\. /.test(l))) return <ol key={i} className="list-decimal space-y-1 pl-5">{lines.map((l, j) => <li key={j}>{inline(l.replace(/^\s*\d+\. /, ''))}</li>)}</ol>;
                return <p key={i}>{lines.map((l, j) => <Fragment key={j}>{j > 0 && <br />}{inline(l)}</Fragment>)}</p>;
            })}
        </div>
    );
}

export default function Content({ type, pages, counts }: { type: 'faq' | 'legal'; pages: Page[]; counts: Record<string, number> }) {
    const [selected, setSelected] = useState<Page | 'new' | null>(pages[0] ?? null);
    const [del, setDel] = useState<Page | null>(null);
    const act = useAction();
    const current = selected === 'new' ? null : pages.find((p) => p.id === selected?.id) ?? null;
    return (
        <AdminLayout title="Content" actions={<Button onClick={() => setSelected('new')}><Plus /> New {type === 'faq' ? 'FAQ' : 'legal page'}</Button>}>
            <Tabs value={type} onChange={(t) => router.get('/admin/content', { type: t })} tabs={[{ value: 'faq', label: 'FAQs', count: counts.faq ?? 0 }, { value: 'legal', label: 'Terms & legal', count: counts.legal ?? 0 }]} />
            {pages.length === 0 && selected !== 'new' ? (
                <EmptyState icon={FileText} title={type === 'faq' ? 'No FAQs yet' : 'No legal pages yet'} description="Content appears on the public site once published." action={<Button onClick={() => setSelected('new')}>Create the first one</Button>} />
            ) : (
                <div className="grid gap-6 lg:grid-cols-[300px_1fr]">
                    <nav aria-label="Pages" className="space-y-1">
                        {pages.map((p) => (
                            <button key={p.id} type="button" onClick={() => setSelected(p)} aria-current={current?.id === p.id ? 'true' : undefined} className={`flex w-full items-start justify-between gap-2 rounded-[10px] px-3 py-2.5 text-left text-sm ${current?.id === p.id ? 'bg-surface-2 font-medium' : 'hover:bg-surface-2/60'}`}>
                                <span className="min-w-0">{p.title}<span className="block truncate text-xs text-subtle">/{p.slug}</span></span>
                                {!p.is_published && <Badge>Draft</Badge>}
                            </button>
                        ))}
                    </nav>
                    <Editor key={current?.id ?? 'new'} page={current} type={type} onDelete={current ? () => setDel(current) : undefined} />
                </div>
            )}
            <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} title={`Delete “${del?.title}”?`} description="This can't be undone." confirmLabel="Delete" tone="danger" processing={act.processing} error={act.error} onConfirm={() => del && act.run('delete', `/admin/content/${del.id}`, {}, () => (setDel(null), setSelected(null)))} />
        </AdminLayout>
    );
}

function Editor({ page, type, onDelete }: { page: Page | null; type: 'faq' | 'legal'; onDelete?: () => void }) {
    const form = useForm({ type, title: page?.title ?? '', slug: page?.slug ?? '', body: page?.body ?? '', sort: String(page?.sort ?? 0), is_published: page?.is_published ?? true });
    const [preview, setPreview] = useState(false);
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (page) form.put(`/admin/content/${page.id}`, { preserveScroll: true });
        else form.post('/admin/content', { preserveScroll: true });
    };
    return (
        <Card>
            <CardBody>
                <form className="space-y-4" onSubmit={submit}>
                    <div className="grid gap-4 sm:grid-cols-[1fr_200px_100px]">
                        <Field label={type === 'faq' ? 'Question' : 'Title'} error={form.errors.title}>
                            <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        </Field>
                        <Field label="Slug" optional error={form.errors.slug}>
                            <Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} placeholder="auto" />
                        </Field>
                        <Field label="Order" error={form.errors.sort}>
                            <Input type="number" min={0} value={form.data.sort} onChange={(e) => form.setData('sort', e.target.value)} />
                        </Field>
                    </div>
                    <div>
                        <div className="mb-1.5 flex items-center justify-between">
                            <Label htmlFor="body">{type === 'faq' ? 'Answer' : 'Body'}</Label>
                            <div role="tablist" className="flex gap-1 text-sm">
                                <button type="button" role="tab" aria-selected={!preview} onClick={() => setPreview(false)} className={`rounded-[8px] px-2 py-0.5 ${!preview ? 'bg-surface-2 font-medium' : 'text-muted'}`}>Write</button>
                                <button type="button" role="tab" aria-selected={preview} onClick={() => setPreview(true)} className={`rounded-[8px] px-2 py-0.5 ${preview ? 'bg-surface-2 font-medium' : 'text-muted'}`}>Preview</button>
                            </div>
                        </div>
                        {preview ? (
                            <div className="min-h-64 rounded-[12px] border border-border p-4">{form.data.body ? <MarkdownPreview source={form.data.body} /> : <p className="text-sm text-muted">Nothing to preview.</p>}</div>
                        ) : (
                            <Textarea id="body" rows={14} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="font-mono text-sm" aria-invalid={form.errors.body ? true : undefined} />
                        )}
                        <p className="mt-1 text-xs text-muted">Supports # headings, - lists, 1. lists, **bold**, *italic* and [links](https://…).</p>
                        {form.errors.body && <p className="mt-1 text-sm text-danger" role="alert">{form.errors.body}</p>}
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-sm"><Switch checked={form.data.is_published} onCheckedChange={(c) => form.setData('is_published', c)} /> Published</label>
                        {page && <span className="text-xs text-subtle">Updated {dateTime(page.updated_at)}</span>}
                        <div className="ml-auto flex gap-2">
                            {onDelete && <Button type="button" variant="ghost" onClick={onDelete}><Trash2 /> Delete</Button>}
                            <Button type="submit" loading={form.processing} disabled={page ? !form.isDirty : false}>{page ? 'Save changes' : 'Create'}</Button>
                        </div>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
