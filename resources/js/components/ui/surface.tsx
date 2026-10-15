import { cn, titleCase } from '@/lib/utils';
import { AlertTriangle, Inbox, RefreshCw } from 'lucide-react';
import * as React from 'react';
import { Button } from './button';

export function Card({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('rounded-[16px] border border-border bg-surface shadow-sm', className)} {...props} />;
}

export function CardHeader({ title, description, action, className }: { title: React.ReactNode; description?: React.ReactNode; action?: React.ReactNode; className?: string }) {
    return (
        <div className={cn('flex items-start justify-between gap-4 border-b border-border px-5 py-4 sm:px-6', className)}>
            <div className="min-w-0">
                <h2 className="text-base font-semibold text-fg">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-muted">{description}</p>}
            </div>
            {action}
        </div>
    );
}

export function CardBody({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('px-5 py-5 sm:px-6', className)} {...props} />;
}

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info';
const tones: Record<Tone, string> = {
    neutral: 'bg-surface-2 text-muted border-border',
    brand: 'bg-primary-soft text-primary-hover border-transparent', // text-primary on primary-soft was 4.48:1 (axe)
    success: 'bg-success-soft text-success border-transparent',
    warning: 'bg-warning-soft text-warning border-transparent',
    danger: 'bg-danger-soft text-danger border-transparent',
    info: 'bg-info-soft text-info border-transparent',
};

export function Badge({ tone = 'neutral', className, children, dot }: { tone?: Tone; className?: string; children: React.ReactNode; dot?: boolean }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', tones[tone], className)}>
            {dot && <span className="size-1.5 rounded-full bg-current" aria-hidden />}
            {children}
        </span>
    );
}

const statusTone: Record<string, Tone> = {
    draft: 'neutral',
    submitted: 'info',
    awaiting_artwork_approval: 'warning',
    in_printing: 'info',
    in_design: 'info',
    proof_ready: 'brand',
    changes_requested: 'warning',
    shipped: 'info',
    runs_published: 'info',
    published: 'info',
    allocated: 'info',
    collected: 'info',
    in_delivery: 'brand',
    completed: 'success',
    succeeded: 'success',
    paid: 'success',
    approved: 'success',
    open: 'warning',
    pending: 'warning',
    resolved: 'success',
    cancelled: 'neutral',
    failed: 'danger',
    rejected: 'danger',
    suspended: 'danger',
    refunded: 'neutral',
    partially_refunded: 'warning',
};

/** Status pill: text label + dot (never colour-only). */
export function StatusBadge({ status, label }: { status: string; label?: string }) {
    return (
        <Badge tone={statusTone[status] ?? 'neutral'} dot>
            {label ?? titleCase(status)}
        </Badge>
    );
}

export function Kbd({ children, className }: { children: React.ReactNode; className?: string }) {
    return <kbd className={cn('kbd', className)}>{children}</kbd>;
}

export function Skeleton({ className }: { className?: string }) {
    return <div className={cn('skeleton h-4 w-full', className)} aria-hidden />;
}

export function SkeletonRows({ rows = 4 }: { rows?: number }) {
    return (
        <div className="space-y-3" role="status" aria-label="Loading">
            {Array.from({ length: rows }).map((_, i) => (
                <Skeleton key={i} className="h-12" />
            ))}
        </div>
    );
}

export function EmptyState({ icon: Icon = Inbox, title, description, action, className }: { icon?: React.ComponentType<{ className?: string }>; title: string; description?: React.ReactNode; action?: React.ReactNode; className?: string }) {
    return (
        <div className={cn('flex flex-col items-center justify-center rounded-[16px] border border-dashed border-border-strong px-6 py-12 text-center', className)}>
            <div className="mb-4 grid size-12 place-items-center rounded-full bg-surface-2 text-muted">
                <Icon className="size-6" />
            </div>
            <h3 className="text-base font-semibold text-fg">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

export function ErrorState({ title = 'Something went wrong', description, onRetry }: { title?: string; description?: React.ReactNode; onRetry?: () => void }) {
    return (
        <div role="alert" className="flex flex-col items-center rounded-[16px] border border-danger/30 bg-danger-soft px-6 py-10 text-center">
            <AlertTriangle className="mb-3 size-6 text-danger" aria-hidden />
            <h3 className="font-semibold text-fg">{title}</h3>
            {description && <p className="mt-1 text-sm text-muted">{description}</p>}
            {onRetry && (
                <Button variant="secondary" size="sm" className="mt-4" onClick={onRetry}>
                    <RefreshCw /> Retry
                </Button>
            )}
        </div>
    );
}

export function Alert({ tone = 'info', title, children, action, className }: { tone?: Tone; title?: React.ReactNode; children?: React.ReactNode; action?: React.ReactNode; className?: string }) {
    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('flex items-start gap-3 rounded-[12px] border px-4 py-3 text-sm', tones[tone], className)}>
            <div className="min-w-0 flex-1">
                {title && <p className="font-semibold">{title}</p>}
                {children && <div className={cn(title && 'mt-0.5', 'text-fg/80')}>{children}</div>}
            </div>
            {action}
        </div>
    );
}

export function PageHeader({ title, description, actions, eyebrow }: { title: React.ReactNode; description?: React.ReactNode; actions?: React.ReactNode; eyebrow?: React.ReactNode }) {
    return (
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                {eyebrow && <div className="mb-1 text-xs font-semibold tracking-wider text-muted uppercase">{eyebrow}</div>}
                <h1 className="text-2xl font-semibold tracking-tight text-fg sm:text-3xl">{title}</h1>
                {description && <p className="mt-1.5 text-muted">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function StatTile({ label, value, hint, icon: Icon }: { label: string; value: React.ReactNode; hint?: React.ReactNode; icon?: React.ComponentType<{ className?: string }> }) {
    return (
        <Card className="p-5">
            <div className="flex items-center justify-between text-sm text-muted">
                <span>{label}</span>
                {Icon && <Icon className="size-4" aria-hidden />}
            </div>
            <div className="tabular mt-2 text-2xl font-semibold tracking-tight text-fg">{value}</div>
            {hint && <div className="mt-1 text-xs text-subtle">{hint}</div>}
        </Card>
    );
}

/** Ordered status timeline. `current` = index of the current step (−1 = none, e.g. cancelled). */
export function Timeline({ steps, current, cancelled, dates = {} }: { steps: { key: string; label: string }[]; current: number; cancelled?: boolean; dates?: Record<string, string | null | undefined> }) {
    return (
        <ol className="relative space-y-0" aria-label="Status timeline">
            {steps.map((s, i) => {
                const done = i < current || (i === current && s.key === 'completed');
                const active = i === current && !done;
                return (
                    <li key={s.key} className="relative flex gap-3 pb-5 last:pb-0">
                        {i < steps.length - 1 && <span className={cn('absolute top-6 left-[11px] h-[calc(100%-16px)] w-px', i < current ? 'bg-ink-900 dark:bg-brand-500' : 'bg-border')} aria-hidden />}
                        <span
                            className={cn(
                                'relative z-10 grid size-6 shrink-0 place-items-center rounded-full border-2 text-[10px] font-bold',
                                done && 'border-ink-900 bg-ink-900 text-white dark:border-brand-500 dark:bg-brand-500',
                                active && 'border-primary bg-surface text-primary',
                                !done && !active && 'border-border-strong bg-surface text-subtle',
                            )}
                            aria-hidden
                        >
                            {done ? '✓' : i + 1}
                        </span>
                        <div className="min-w-0 pt-0.5">
                            <p className={cn('text-sm font-medium', done || active ? 'text-fg' : 'text-subtle')}>
                                {s.label}
                                <span className="sr-only">{done ? ' (done)' : active ? ' (current)' : ' (upcoming)'}</span>
                            </p>
                            {dates[s.key] && <p className="text-xs text-muted">{dates[s.key]}</p>}
                        </div>
                    </li>
                );
            })}
            {cancelled && (
                <li className="flex items-center gap-3 pt-2">
                    <span className="grid size-6 place-items-center rounded-full bg-danger text-xs font-bold text-white" aria-hidden>
                        ×
                    </span>
                    <span className="text-sm font-medium text-danger">Cancelled</span>
                </li>
            )}
        </ol>
    );
}
