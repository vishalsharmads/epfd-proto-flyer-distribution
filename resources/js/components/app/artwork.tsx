import type { ArtworkT, ProductT } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/overlay';
import { api, ApiError, cn } from '@/lib/utils';
import { Download, FileText, Loader2, RefreshCw, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const MAX = 50 * 1024 * 1024;
const OK = ['application/pdf', 'image/png', 'image/jpeg'];

function fmtSize(b: number) {
    return b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`;
}

/** Canvas preview: artwork scaled to the bleed box, with trim (solid), bleed (red dashed) and safe-zone (blue dashed) guides. */
function Preview({ product, orientation, artwork }: { product: ProductT; orientation: 'portrait' | 'landscape'; artwork: ArtworkT | null }) {
    const ref = useRef<HTMLCanvasElement>(null);
    const [img, setImg] = useState<HTMLImageElement | null>(null);
    const swap = (orientation === 'landscape') !== product.width_mm > product.height_mm;
    const w = swap ? product.height_mm : product.width_mm;
    const h = swap ? product.width_mm : product.height_mm;
    const bleed = product.bleed_mm;
    const safe = Math.max(3, bleed);

    useEffect(() => {
        setImg(null);
        if (!artwork || !artwork.mime.startsWith('image/')) return;
        const i = new Image();
        i.onload = () => setImg(i);
        i.src = artwork.url;
    }, [artwork]);

    useEffect(() => {
        const c = ref.current;
        if (!c) return;
        const W = w + 2 * bleed;
        const H = h + 2 * bleed;
        const scale = Math.min(320 / W, 320 / H);
        const dpr = window.devicePixelRatio || 1;
        c.width = W * scale * dpr;
        c.height = H * scale * dpr;
        c.style.width = `${W * scale}px`;
        c.style.height = `${H * scale}px`;
        const g = c.getContext('2d');
        if (!g) return;
        g.scale(dpr * scale, dpr * scale);
        g.fillStyle = '#f5f5f4';
        g.fillRect(0, 0, W, H);
        if (img) {
            const s = Math.max(W / img.width, H / img.height);
            g.drawImage(img, (W - img.width * s) / 2, (H - img.height * s) / 2, img.width * s, img.height * s);
        }
        g.lineWidth = 0.6 / scale;
        g.setLineDash([]);
        g.strokeStyle = '#141414';
        g.strokeRect(bleed, bleed, w, h);
        g.setLineDash([3 / scale, 3 / scale]);
        g.strokeStyle = '#e00b33';
        g.strokeRect(0.3, 0.3, W - 0.6, H - 0.6);
        g.strokeStyle = '#2563eb';
        g.strokeRect(bleed + safe, bleed + safe, w - 2 * safe, h - 2 * safe);
    }, [img, w, h, bleed, safe]);

    return (
        <div className="flex flex-col items-center gap-3">
            <div className="relative">
                <canvas ref={ref} role="img" aria-label={`Artwork preview, ${w} by ${h} mm with ${bleed} mm bleed`} className="rounded-[4px] shadow-md" />
                {artwork && !artwork.mime.startsWith('image/') && (
                    <div className="absolute inset-0 grid place-items-center text-center text-xs text-muted">
                        <span>
                            <FileText className="mx-auto mb-1 size-6" aria-hidden /> PDF uploaded
                            <br />
                            (preview not available)
                        </span>
                    </div>
                )}
            </div>
            <ul className="flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-muted">
                <li className="flex items-center gap-1.5"><span className="h-0 w-4 border-t border-fg" aria-hidden /> Trim {w}×{h} mm</li>
                <li className="flex items-center gap-1.5"><span className="h-0 w-4 border-t border-dashed border-primary" aria-hidden /> Bleed {bleed} mm</li>
                <li className="flex items-center gap-1.5"><span className="h-0 w-4 border-t border-dashed border-[#2563eb]" aria-hidden /> Safe zone</li>
            </ul>
        </div>
    );
}

export function ArtworkSlot({ orderId, side, product, orientation, artwork, onChange }: { orderId: number; side: 'front' | 'back'; product: ProductT; orientation: 'portrait' | 'landscape'; artwork: ArtworkT | null; onChange: (a: ArtworkT | null) => void }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [drag, setDrag] = useState(false);

    const swap = (orientation === 'landscape') !== product.width_mm > product.height_mm;
    const wmm = swap ? product.height_mm : product.width_mm;
    const dpi = artwork?.width_px ? Math.round(artwork.width_px / ((wmm + 2 * product.bleed_mm) / 25.4)) : null;

    const upload = async (file: File | undefined) => {
        if (!file) return;
        setError(null);
        if (!OK.includes(file.type)) return setError('Upload a PDF, PNG or JPG file.');
        if (file.size > MAX) return setError('Files can be at most 50 MB.');
        const fd = new FormData();
        fd.append('side', side);
        fd.append('file', file);
        setBusy(true);
        try {
            const r = await api<{ data: ArtworkT }>(`/app/print/${orderId}/artworks`, { method: 'POST', body: fd });
            onChange(r.data);
        } catch (e) {
            setError(e instanceof ApiError ? (e.problem?.errors?.file?.[0] ?? e.message) : 'Upload failed. Try again.');
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };

    const remove = async () => {
        if (!artwork) return;
        setBusy(true);
        try {
            await api(`/app/print/${orderId}/artworks/${artwork.id}`, { method: 'DELETE' });
            onChange(null);
        } catch {
            setError('Could not delete the file. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="rounded-[16px] border border-border p-4 sm:p-5">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="font-semibold capitalize">{side} artwork</h3>
                {artwork && (
                    <div className="flex gap-1">
                        <Button asChild variant="ghost" size="icon-sm" aria-label="Download artwork">
                            <a href={`${artwork.url}?download=1`}>
                                <Download />
                            </a>
                        </Button>
                        <Button variant="ghost" size="icon-sm" aria-label="Replace artwork" onClick={() => input.current?.click()} disabled={busy}>
                            <RefreshCw />
                        </Button>
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="ghost" size="icon-sm" aria-label="Delete artwork" disabled={busy}>
                                    <Trash2 />
                                </Button>
                            </DialogTrigger>
                            <DialogContent title="Delete this artwork?" description={artwork.original_name}>
                                <div className="flex justify-end gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">Cancel</Button>
                                    </DialogClose>
                                    <DialogClose asChild>
                                        <Button variant="danger" onClick={remove}>
                                            Delete
                                        </Button>
                                    </DialogClose>
                                </div>
                            </DialogContent>
                        </Dialog>
                    </div>
                )}
            </div>
            <input ref={input} type="file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" className="sr-only" data-testid={side === 'front' ? 'artwork-input' : 'artwork-input-back'} aria-label={`Upload ${side} artwork`} onChange={(e) => upload(e.target.files?.[0])} />
            {artwork ? (
                <div className="grid items-center gap-5 sm:grid-cols-[auto_1fr]">
                    <Preview product={product} orientation={orientation} artwork={artwork} />
                    <div className="space-y-1.5 text-sm">
                        <p className="font-medium break-all">{artwork.original_name}</p>
                        <p className="text-muted">
                            {fmtSize(artwork.size_bytes)}
                            {artwork.width_px && ` · ${artwork.width_px}×${artwork.height_px}px`}
                        </p>
                        {dpi !== null && <p className={cn(dpi < 150 ? 'text-warning' : 'text-success')}>{dpi < 150 ? `Low resolution (~${dpi} dpi). We recommend 300 dpi for sharp print.` : `~${dpi} dpi — good for print.`}</p>}
                        {busy && <Loader2 className="size-4 animate-spin" aria-label="Working" />}
                    </div>
                </div>
            ) : (
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
                        upload(e.dataTransfer.files?.[0]);
                    }}
                    disabled={busy}
                    className={cn('flex w-full flex-col items-center gap-2 rounded-[12px] border-2 border-dashed px-6 py-10 text-center transition-colors', drag ? 'border-primary bg-primary-soft' : 'border-border-strong hover:bg-surface-2')}
                >
                    {busy ? <Loader2 className="size-6 animate-spin text-muted" aria-hidden /> : <Upload className="size-6 text-muted" aria-hidden />}
                    <span className="font-medium">{busy ? 'Uploading…' : 'Drop a file or click to upload'}</span>
                    <span className="text-xs text-muted">PDF, PNG or JPG · up to 50 MB · include {product.bleed_mm} mm bleed</span>
                </button>
            )}
            {error && (
                <p role="alert" className="mt-3 text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
