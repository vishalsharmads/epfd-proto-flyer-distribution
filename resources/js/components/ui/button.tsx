import { cn } from '@/lib/utils';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import * as React from 'react';

export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap font-semibold select-none transition-[background,color,box-shadow,transform] duration-150 ease-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98] [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-primary-fg shadow-sm hover:bg-primary-hover',
                ink: 'bg-ink-900 text-white shadow-sm hover:bg-ink-800 dark:bg-white dark:text-ink-900 dark:hover:bg-ink-100',
                secondary: 'bg-surface text-fg border border-border shadow-xs hover:bg-surface-2',
                ghost: 'text-fg hover:bg-surface-2',
                danger: 'bg-danger text-white shadow-sm hover:opacity-90',
                'danger-soft': 'bg-danger-soft text-danger hover:bg-danger hover:text-white',
                link: 'text-primary underline-offset-4 hover:underline px-0 h-auto',
            },
            size: {
                sm: 'h-8 px-3 text-sm rounded-[10px]',
                md: 'h-10 px-4 text-sm rounded-button',
                lg: 'h-12 px-6 text-base rounded-button',
                icon: 'size-10 rounded-full',
                'icon-sm': 'size-8 rounded-full',
            },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
    loading?: boolean;
    /** Keyboard-shortcut badge shown inside the button (e.g. "Enter", "Space", "Esc"). */
    kbd?: React.ReactNode;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild, loading, kbd, children, disabled, ...props }, ref) => {
        const Comp = asChild ? Slot : 'button';
        if (asChild) {
            return (
                <Comp ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...props}>
                    {children}
                </Comp>
            );
        }
        return (
            <button
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {loading && <Loader2 className="animate-spin" aria-hidden />}
                {children}
                {kbd && (
                    <kbd className="kbd ml-1 border-current/30 bg-white/15 text-current shadow-none" aria-hidden>
                        {kbd}
                    </kbd>
                )}
            </button>
        );
    },
);
Button.displayName = 'Button';
