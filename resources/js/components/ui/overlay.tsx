import { cn } from '@/lib/utils';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import * as DropdownPrimitive from '@radix-ui/react-dropdown-menu';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { X } from 'lucide-react';
import * as React from 'react';

/* ---------------- Dialog ---------------- */
export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export function DialogContent({ title, description, children, className, wide }: { title: React.ReactNode; description?: React.ReactNode; children: React.ReactNode; className?: string; wide?: boolean }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-overlay backdrop-blur-[2px] data-[state=open]:animate-fade-in" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed top-1/2 left-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-[16px] border border-border bg-surface p-6 shadow-lg data-[state=open]:animate-slide-up',
                    wide ? 'max-w-3xl' : 'max-w-lg',
                    className,
                )}
            >
                <div className="mb-4 pr-8">
                    <DialogPrimitive.Title className="text-lg font-semibold text-fg">{title}</DialogPrimitive.Title>
                    {description ? <DialogPrimitive.Description className="mt-1 text-sm text-muted">{description}</DialogPrimitive.Description> : <DialogPrimitive.Description className="sr-only">{String(title)}</DialogPrimitive.Description>}
                </div>
                {children}
                <DialogPrimitive.Close className="absolute top-4 right-4 grid size-8 place-items-center rounded-full text-muted hover:bg-surface-2 hover:text-fg" aria-label="Close">
                    <X className="size-4" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

/* ---------------- Popover ---------------- */
export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;
export const PopoverAnchor = PopoverPrimitive.Anchor;

export const PopoverContent = React.forwardRef<React.ElementRef<typeof PopoverPrimitive.Content>, React.ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>>(
    ({ className, align = 'start', sideOffset = 8, ...props }, ref) => (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                ref={ref}
                align={align}
                sideOffset={sideOffset}
                className={cn('z-50 rounded-[14px] border border-border bg-surface p-2 shadow-lg outline-none data-[state=open]:animate-fade-in', className)}
                {...props}
            />
        </PopoverPrimitive.Portal>
    ),
);
PopoverContent.displayName = 'PopoverContent';

/* ---------------- Dropdown ---------------- */
export const Dropdown = DropdownPrimitive.Root;
export const DropdownTrigger = DropdownPrimitive.Trigger;
export function DropdownContent({ className, ...props }: React.ComponentPropsWithoutRef<typeof DropdownPrimitive.Content>) {
    return (
        <DropdownPrimitive.Portal>
            <DropdownPrimitive.Content sideOffset={8} align="end" className={cn('z-50 min-w-48 rounded-[14px] border border-border bg-surface p-1.5 shadow-lg data-[state=open]:animate-fade-in', className)} {...props} />
        </DropdownPrimitive.Portal>
    );
}
export function DropdownItem({ className, ...props }: React.ComponentPropsWithoutRef<typeof DropdownPrimitive.Item>) {
    return <DropdownPrimitive.Item className={cn('flex cursor-pointer items-center gap-2 rounded-[10px] px-3 py-2 text-sm text-fg outline-none select-none data-[highlighted]:bg-surface-2 [&_svg]:size-4 [&_svg]:text-muted', className)} {...props} />;
}
export const DropdownSeparator = () => <DropdownPrimitive.Separator className="my-1 h-px bg-border" />;
export const DropdownLabel = ({ children }: { children: React.ReactNode }) => <DropdownPrimitive.Label className="px-3 py-1.5 text-xs text-muted">{children}</DropdownPrimitive.Label>;

/* ---------------- Tooltip ---------------- */
export function Tooltip({ content, children, side = 'left' }: { content: React.ReactNode; children: React.ReactNode; side?: 'left' | 'right' | 'top' | 'bottom' }) {
    return (
        <TooltipPrimitive.Provider delayDuration={250}>
            <TooltipPrimitive.Root>
                <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
                <TooltipPrimitive.Portal>
                    <TooltipPrimitive.Content side={side} sideOffset={8} className="z-50 rounded-[8px] bg-ink-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-md data-[state=delayed-open]:animate-fade-in dark:bg-white dark:text-ink-900">
                        {content}
                    </TooltipPrimitive.Content>
                </TooltipPrimitive.Portal>
            </TooltipPrimitive.Root>
        </TooltipPrimitive.Provider>
    );
}
