import type { DesignAssetT, DesignOrderT, DesignPackageOption, DesignType } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { api, ApiError, cn, money } from '@/lib/utils';
import { BookOpen, CreditCard, FileImage, FileText, FolderOpen, Loader2, Mail, Palette, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

export const DESIGN_STEPS = ['Start', 'Service', 'Brief', 'Confirm'];

const icons: Record<DesignType, typeof FileText> = { flyer: FileText, brochure: BookOpen, business_card: CreditCard, presentation_folder: FolderOpen, stationery: Mail };

/** Tinted tile with the design type's icon (no stock imagery). */
export function DesignArt({ type, className }: { type: DesignType | string; className?: string }) {
    const Icon = icons[type as DesignType] ?? Palette;
    return (
        <div className={cn('grid place-items-center bg-gradient-to-br from-primary-soft to-surface-2', className)} aria-hidden>
            <div className="grid size-14 place-items-center rounded-[14px] border border-border bg-surface shadow-sm">
                <Icon className="size-6 text-primary-hover" />
            </div>
        </div>
    );
}

export function designStepUrl(o: Pick<DesignOrderT, 'id' | 'step'>) {
    return `/app/design/${o.id}/${o.step >= 4 ? 'confirm' : o.step >= 3 ? 'brief' : 'service'}`;
}

export function fromLabel(cents: number) {
    return `from ${money(cents).replace(/\.00$/, '')} + GST`;
}

export function revisionsText(p: Pick<DesignPackageOption, 'revisions' | 'unlimited'>) {
    return p.unlimited ? 'Unlimited revisions' : `${p.revisions} revision${p.revisions === 1 ? '' : 's'}`;
}

export function fmtSize(b: number) {
    return b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;
}

/** Small file row/thumbnail used for brief uploads and final files. */
export function AssetRow({ asset, onDelete, busy }: { asset: DesignAssetT; onDelete?: () => void; busy?: boolean }) {
    return (
        <li className="flex items-center gap-3 py-2.5">
            <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-[10px] border border-border bg-surface-2">
                {asset.is_image ? <img src={asset.url} alt="" className="size-full object-cover" loading="lazy" /> : <FileImage className="size-5 text-muted" aria-hidden />}
            </span>
            <span className="min-w-0 flex-1">
                <a href={asset.download_url} className="block truncate text-sm font-medium hover:underline">
                    {asset.original_name}
                </a>
                <span className="text-xs text-muted">{fmtSize(asset.size_bytes)}</span>
            </span>
            {onDelete && (
                <Button variant="ghost" size="icon-sm" aria-label={`Remove ${asset.original_name}`} onClick={onDelete} disabled={busy}>
                    <Trash2 />
                </Button>
            )}
        </li>
    );
}

const OK = ['application/pdf', 'image/png', 'image/jpeg', 'image/svg+xml'];

/** Multi-file uploader for logos, brand guides and reference images (PDF/PNG/JPG/SVG ≤ 20 MB). */
export function BriefUploader({ orderId, files, onChange, maxFiles = 10, canDelete = true }: { orderId: number; files: DesignAssetT[]; onChange: (files: DesignAssetT[]) => void; maxFiles?: number; canDelete?: boolean }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [drag, setDrag] = useState(false);

    const upload = async (list: FileList | null | undefined) => {
        const picked = Array.from(list ?? []);
        if (!picked.length) return;
        setError(null);
        const bad = picked.find((f) => !OK.includes(f.type) && !/\.(pdf|png|jpe?g|svg)$/i.test(f.name));
        if (bad) return setError(`${bad.name}: upload PDF, PNG, JPG or SVG files.`);
        const big = picked.find((f) => f.size > 20 * 1024 * 1024);
        if (big) return setError(`${big.name} is larger than 20 MB.`);
        if (files.length + picked.length > maxFiles) return setError(`You can upload up to ${maxFiles} files.`);
        const fd = new FormData();
        picked.forEach((f) => fd.append('files[]', f));
        setBusy(true);
        try {
            const r = await api<{ data: DesignAssetT[] }>(`/app/design/${orderId}/assets`, { method: 'POST', body: fd });
            onChange([...files, ...r.data]);
        } catch (e) {
            const errs = e instanceof ApiError ? e.problem?.errors : undefined;
            setError(errs ? Object.values(errs)[0]?.[0] ?? 'Upload failed.' : e instanceof Error ? e.message : 'Upload failed. Try again.');
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };

    const remove = async (a: DesignAssetT) => {
        setBusy(true);
        try {
            await api(`/app/design/${orderId}/assets/${a.id}`, { method: 'DELETE' });
            onChange(files.filter((f) => f.id !== a.id));
        } catch {
            setError('Could not remove the file. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-3">
            <input ref={input} type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.svg,application/pdf,image/png,image/jpeg,image/svg+xml" className="sr-only" data-testid="brief-files-input" aria-label="Upload logo, brand or reference files" onChange={(e) => upload(e.target.files)} />
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDrag(true);
                }}
                onDragLeave={() => setDrag(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDrag(false);
                    upload(e.dataTransfer.files);
                }}
                disabled={busy || files.length >= maxFiles}
                className={cn('flex w-full flex-col items-center gap-2 rounded-[12px] border-2 border-dashed px-6 py-8 text-center transition-colors disabled:opacity-60', drag ? 'border-primary bg-primary-soft' : 'border-border-strong hover:bg-surface-2')}
            >
                {busy ? <Loader2 className="size-6 animate-spin text-muted" aria-hidden /> : <Upload className="size-6 text-muted" aria-hidden />}
                <span className="font-medium">{busy ? 'Uploading…' : 'Drop files or click to upload'}</span>
                <span className="text-xs text-muted">Logo, brand guide, photos or examples you like · PDF, PNG, JPG or SVG · up to 20 MB each</span>
            </button>
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
            {files.length > 0 && (
                <ul className="divide-y divide-border" aria-label="Uploaded files">
                    {files.map((f) => (
                        <AssetRow key={f.id} asset={f} busy={busy} onDelete={canDelete ? () => remove(f) : undefined} />
                    ))}
                </ul>
            )}
        </div>
    );
}
