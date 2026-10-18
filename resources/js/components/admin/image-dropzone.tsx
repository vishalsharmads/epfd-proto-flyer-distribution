import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ImagePlus, RefreshCw, Trash2, UploadCloud } from 'lucide-react';
import { useCallback, useEffect, useId, useState } from 'react';
import { type FileRejection, useDropzone } from 'react-dropzone';

const MIME: Record<string, string[]> = { 'image/jpeg': ['.jpg', '.jpeg'], 'image/png': ['.png'], 'image/webp': ['.webp'] };

function fmtBytes(n: number) {
    return n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1024 / 1024).toFixed(1)} MB`;
}

function rejectionMessage(r: FileRejection, maxMb: number) {
    const code = r.errors[0]?.code;
    if (code === 'file-too-large') return `${r.file.name} is ${fmtBytes(r.file.size)} — the limit is ${maxMb} MB.`;
    if (code === 'file-invalid-type') return `${r.file.name} isn’t a JPG, PNG or WebP image.`;
    if (code === 'too-many-files') return 'Choose one image.';
    return r.errors[0]?.message ?? 'That file can’t be used.';
}

/**
 * Single-image picker: drag & drop, click/keyboard to browse, or paste from the clipboard. Shows a live preview at the
 * product's aspect ratio with file details, replace/remove, client-side type/size checks and an upload progress bar.
 * The file itself is submitted with the surrounding form (Inertia useForm).
 */
export function ImageDropzone({
    file,
    currentUrl,
    onChange,
    onRemove,
    aspect = 4 / 3,
    maxMb = 4,
    progress,
    error,
    hint,
    label = 'Product image',
}: {
    file: File | null;
    currentUrl: string | null;
    onChange: (file: File) => void;
    onRemove: () => void;
    aspect?: number;
    maxMb?: number;
    progress?: number | null;
    error?: string;
    hint?: string;
    label?: string;
}) {
    const [localError, setLocalError] = useState<string | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [dims, setDims] = useState<{ w: number; h: number } | null>(null);
    const hintId = useId();

    useEffect(() => {
        if (!file) return setPreview(null);
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);
    const shown = preview ?? currentUrl;
    useEffect(() => {
        setDims(null);
        if (!shown) return;
        const img = new Image();
        img.onload = () => setDims({ w: img.naturalWidth, h: img.naturalHeight });
        img.src = shown;
    }, [shown]);

    const accept = useCallback(
        (f: File) => {
            setLocalError(null);
            onChange(f);
        },
        [onChange],
    );
    const { getRootProps, getInputProps, isDragActive, isDragReject, open } = useDropzone({
        accept: MIME,
        maxSize: maxMb * 1024 * 1024,
        multiple: false,
        noClick: !!shown,
        onDropAccepted: (files) => files[0] && accept(files[0]),
        onDropRejected: (rejections) => setLocalError(rejectionMessage(rejections[0], maxMb)),
    });

    // Paste an image from the clipboard anywhere on the page (except while typing in a field).
    useEffect(() => {
        const onPaste = (e: ClipboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (target?.closest('input, textarea, [contenteditable="true"]')) return;
            const item = Array.from(e.clipboardData?.files ?? []).find((f) => f.type.startsWith('image/'));
            if (!item) return;
            e.preventDefault();
            if (!MIME[item.type]) return setLocalError('Pasted image must be JPG, PNG or WebP.');
            if (item.size > maxMb * 1024 * 1024) return setLocalError(`Pasted image is ${fmtBytes(item.size)} — the limit is ${maxMb} MB.`);
            accept(
                new File([item], item.name && item.name !== 'image.png' ? item.name : `pasted-${Date.now()}.${item.type.split('/')[1]}`, {
                    type: item.type,
                }),
            );
        };
        document.addEventListener('paste', onPaste);
        return () => document.removeEventListener('paste', onPaste);
    }, [accept, maxMb]);

    const message = localError ?? error;
    const uploading = typeof progress === 'number' && progress < 100;

    return (
        <div className="space-y-2">
            <div
                {...getRootProps({
                    'aria-label': shown ? `${label}: drop a new image to replace it` : `${label}: drop an image or press Enter to browse`,
                    'aria-describedby': hintId,
                })}
                className={cn(
                    'group relative overflow-hidden rounded-[14px] border-2 border-dashed transition-[border-color,background-color,box-shadow] outline-none focus-visible:ring-2 focus-visible:ring-focus',
                    shown
                        ? 'border-transparent bg-surface-2'
                        : 'cursor-pointer border-border-strong bg-surface-2/40 hover:border-fg/40 hover:bg-surface-2',
                    isDragActive && !isDragReject && 'border-primary bg-primary-soft',
                    isDragReject && 'border-danger bg-danger-soft',
                )}
                style={{ aspectRatio: String(Math.min(2, Math.max(0.6, aspect))) }}
            >
                <input {...getInputProps()} data-testid="image-dropzone-input" />
                {shown ? (
                    <>
                        <img src={shown} alt="" className="size-full object-cover" />
                        <div
                            className={cn(
                                'absolute inset-0 grid place-items-center bg-ink-900/55 text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100',
                                isDragActive && 'opacity-100',
                            )}
                        >
                            {isDragActive ? (
                                <p className="flex items-center gap-2 font-medium">
                                    <UploadCloud className="size-5" aria-hidden /> Drop to replace
                                </p>
                            ) : (
                                <div className="flex gap-2">
                                    <Button type="button" size="sm" variant="secondary" onClick={open}>
                                        <RefreshCw /> Replace
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setLocalError(null);
                                            onRemove();
                                        }}
                                    >
                                        <Trash2 /> Remove
                                    </Button>
                                </div>
                            )}
                        </div>
                        {file && (
                            <span className="absolute top-2 left-2 rounded-full bg-warning px-2 py-0.5 text-xs font-medium text-white shadow-sm">
                                Not saved yet
                            </span>
                        )}
                    </>
                ) : (
                    <div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center">
                        <span
                            className={cn(
                                'grid size-11 place-items-center rounded-full bg-surface shadow-sm transition-transform',
                                isDragActive && 'scale-110',
                            )}
                        >
                            {isDragActive ? (
                                <UploadCloud className="size-5 text-primary" aria-hidden />
                            ) : (
                                <ImagePlus className="size-5 text-muted" aria-hidden />
                            )}
                        </span>
                        <p className="text-sm font-medium">
                            {isDragReject ? 'That file type isn’t supported' : isDragActive ? 'Drop the image here' : 'Drag an image here'}
                        </p>
                        <p className="text-xs text-muted">
                            or <span className="font-medium text-primary-hover underline underline-offset-2">browse</span> · paste with ⌘V / Ctrl+V
                        </p>
                    </div>
                )}
                {uploading && (
                    <div
                        className="absolute inset-x-0 bottom-0 h-1.5 bg-ink-900/20"
                        role="progressbar"
                        aria-label="Uploading image"
                        aria-valuenow={progress}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    >
                        <div className="h-full bg-primary transition-[width]" style={{ width: `${progress}%` }} />
                    </div>
                )}
            </div>
            <div id={hintId} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-muted">
                {shown ? (
                    <span className="min-w-0 truncate">
                        {file ? `${file.name} · ${fmtBytes(file.size)}` : 'Saved image'}
                        {dims ? ` · ${dims.w}×${dims.h}px` : ''}
                    </span>
                ) : (
                    <span>{hint ?? `JPG, PNG or WebP up to ${maxMb} MB`}</span>
                )}
                {dims && dims.w < 800 && <span className="text-warning">Low resolution — 1200px wide or more looks sharper</span>}
            </div>
            {message && (
                <p role="alert" className="text-sm text-danger">
                    {message}
                </p>
            )}
        </div>
    );
}
