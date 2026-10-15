import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/overlay';
import { Card } from '@/components/ui/surface';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/** Card used for "Continue a draft" / "Re-order" lists, with an optional delete-with-confirm. */
export function DraftCard({ media, title, meta, footer, onOpen, deleteUrl, deleteLabel = 'draft', testId }: { media: ReactNode; title: string; meta: ReactNode; footer?: ReactNode; onOpen: () => void; deleteUrl?: string; deleteLabel?: string; testId?: string }) {
    const [busy, setBusy] = useState(false);
    return (
        <Card className="group relative overflow-hidden transition-shadow hover:shadow-md" data-testid={testId}>
            <button type="button" onClick={onOpen} className="block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-[-2px]">
                <div className="aspect-[16/9] w-full overflow-hidden bg-surface-2">{media}</div>
                <div className="p-4">
                    <p className="truncate pr-8 font-medium">{title}</p>
                    <div className="mt-1 text-sm text-muted">{meta}</div>
                    {footer && <div className="mt-3">{footer}</div>}
                </div>
            </button>
            {deleteUrl && (
                <Dialog>
                    <DialogTrigger asChild>
                        <button type="button" className="absolute right-3 bottom-3 grid size-8 place-items-center rounded-full text-muted hover:bg-danger-soft hover:text-danger" aria-label={`Delete ${deleteLabel} ${title}`}>
                            <Trash2 className="size-4" />
                        </button>
                    </DialogTrigger>
                    <DialogContent title={`Delete this ${deleteLabel}?`} description={`“${title}” will be removed. This can't be undone.`}>
                        <div className="flex justify-end gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button variant="danger" loading={busy} onClick={() => router.delete(deleteUrl, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>
                                Delete
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </Card>
    );
}
