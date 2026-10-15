import { cn } from '@/lib/utils';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as SwitchPrimitive from '@radix-ui/react-switch';
import { AlertCircle, Check } from 'lucide-react';
import * as React from 'react';

const control =
    'w-full rounded-[12px] border border-border-strong bg-surface px-3.5 text-[15px] text-fg placeholder:text-subtle shadow-xs transition-[border,box-shadow] duration-150 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger aria-[invalid=true]:ring-danger/15';

export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(({ className, ...props }, ref) => (
    <input ref={ref} className={cn(control, 'h-11', className)} {...props} />
));
Input.displayName = 'Input';

export const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...props }, ref) => (
    <textarea ref={ref} className={cn(control, 'min-h-24 py-2.5', className)} {...props} />
));
Textarea.displayName = 'Textarea';

export const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, children, ...props }, ref) => (
    <select ref={ref} className={cn(control, 'h-11 appearance-none bg-[length:16px] bg-[right_12px_center] bg-no-repeat pr-10', className)} style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23767985' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }} {...props}>
        {children}
    </select>
));
Select.displayName = 'Select';

export function Label({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
    return <label className={cn('text-sm font-medium text-fg', className)} {...props} />;
}

interface FieldProps {
    label?: React.ReactNode;
    htmlFor?: string;
    hint?: React.ReactNode;
    error?: string | null;
    success?: React.ReactNode;
    optional?: boolean;
    className?: string;
    children: React.ReactElement<Record<string, unknown>>;
}

/** Label + control + hint / inline validation. Wires aria-invalid and aria-describedby. */
export function Field({ label, htmlFor, hint, error, success, optional, className, children }: FieldProps) {
    const autoId = React.useId();
    const id = htmlFor ?? (children.props.id as string | undefined) ?? autoId;
    const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : success ? `${id}-success` : undefined;
    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            {label && (
                <Label htmlFor={id}>
                    {label}
                    {optional && <span className="ml-1 font-normal text-subtle">(optional)</span>}
                </Label>
            )}
            {React.cloneElement(children, { id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
            {error ? (
                <p id={`${id}-error`} role="alert" className="flex items-center gap-1.5 text-sm text-danger">
                    <AlertCircle className="size-4 shrink-0" aria-hidden /> {error}
                </p>
            ) : success ? (
                <p id={`${id}-success`} className="flex items-center gap-1.5 text-sm text-success">
                    <Check className="size-4 shrink-0" aria-hidden /> {success}
                </p>
            ) : hint ? (
                <p id={`${id}-hint`} className="text-sm text-muted">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

export const Switch = React.forwardRef<React.ElementRef<typeof SwitchPrimitive.Root>, React.ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>>(
    ({ className, ...props }, ref) => (
        <SwitchPrimitive.Root
            ref={ref}
            className={cn(
                'peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent bg-ink-300 transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-ink-900 dark:bg-ink-600 dark:data-[state=checked]:bg-brand-500',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb className="pointer-events-none block size-5 rounded-full bg-white shadow-md transition-transform duration-200 ease-out data-[state=checked]:translate-x-5 data-[state=unchecked]:translate-x-0" />
        </SwitchPrimitive.Root>
    ),
);
Switch.displayName = 'Switch';

export const Checkbox = React.forwardRef<React.ElementRef<typeof CheckboxPrimitive.Root>, React.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>>(
    ({ className, ...props }, ref) => (
        <CheckboxPrimitive.Root
            ref={ref}
            className={cn(
                'peer size-5 shrink-0 rounded-[6px] border border-border-strong bg-surface shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger data-[state=checked]:border-ink-900 data-[state=checked]:bg-ink-900 data-[state=checked]:text-white dark:data-[state=checked]:border-brand-500 dark:data-[state=checked]:bg-brand-500',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator className="flex items-center justify-center">
                <Check className="size-3.5" strokeWidth={3} />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    ),
);
Checkbox.displayName = 'Checkbox';
