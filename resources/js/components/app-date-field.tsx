import { Calendar, X } from 'lucide-react';
import * as React from 'react';
import { formatDisplayDate, useDateFormat } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export type AppDateFieldVariant = 'card' | 'dark';

export interface AppDateFieldProps extends Omit<
    React.InputHTMLAttributes<HTMLInputElement>,
    'value' | 'onChange' | 'size'
> {
    value?: string | null;
    onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
    onValueChange?: (value: string) => void;
    format?: string;
    variant?: AppDateFieldVariant;
    clearable?: boolean;
    containerClassName?: string;
}

function defaultPlaceholderForFormat(format: string): string {
    switch (format) {
        case 'Y-m-d':
            return 'yyyy-mm-dd';
        case 'd/m/Y':
            return 'dd/mm/yyyy';
        case 'm/d/Y':
            return 'mm/dd/yyyy';
        case 'M d, Y':
            return 'mmm dd, yyyy';
        case 'd-m-Y':
        default:
            return 'dd-mm-yyyy';
    }
}

export const AppDateField = React.forwardRef<
    HTMLInputElement,
    AppDateFieldProps
>(function AppDateField(
    {
        id,
        name,
        value,
        onChange,
        onValueChange,
        format: overrideFormat,
        variant = 'card',
        placeholder,
        className,
        containerClassName,
        disabled = false,
        clearable = true,
        min,
        max,
        required,
        onKeyDown,
        onClick,
        'aria-invalid': ariaInvalid,
        ...props
    },
    forwardedRef,
) {
    const inputRef = React.useRef<HTMLInputElement | null>(null);

    React.useImperativeHandle(
        forwardedRef,
        () => inputRef.current as HTMLInputElement,
    );

    const platformFormat = useDateFormat(overrideFormat);
    const displayValue = value ? formatDisplayDate(value, platformFormat) : '';
    const placeholderText =
        placeholder ?? defaultPlaceholderForFormat(platformFormat);

    const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        onChange?.(event);
        onValueChange?.(event.target.value);
    };

    const handleClear = (event: React.MouseEvent) => {
        event.preventDefault();
        event.stopPropagation();

        if (disabled) {
            return;
        }

        if (inputRef.current) {
            inputRef.current.value = '';
        }

        if (onChange) {
            const syntheticEvent = {
                target: { value: '', name: name ?? id ?? '' },
                currentTarget: { value: '', name: name ?? id ?? '' },
                persist: () => {},
            } as unknown as React.ChangeEvent<HTMLInputElement>;
            onChange(syntheticEvent);
        }

        onValueChange?.('');
    };

    const triggerPicker = () => {
        if (disabled) {
            return;
        }

        try {
            inputRef.current?.showPicker?.();
        } catch {
            inputRef.current?.focus();
        }
    };

    return (
        <div
            data-slot="app-date-field"
            className={cn(
                'relative flex h-11 w-full items-center justify-between gap-2 rounded-xl border px-3 text-sm shadow-xs transition-all outline-none',
                variant === 'dark'
                    ? 'border-input bg-background/50 text-foreground focus-within:ring-[3px] focus-within:ring-primary/40 dark:border-white/10 dark:bg-white/5 dark:text-zinc-100'
                    : 'border-border bg-card text-foreground focus-within:ring-[3px] focus-within:ring-primary/40',
                ariaInvalid &&
                    'border-destructive ring-destructive/20 focus-within:border-destructive focus-within:ring-destructive/20',
                disabled && 'pointer-events-none cursor-not-allowed opacity-50',
                className,
                containerClassName,
            )}
            onClick={triggerPicker}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'truncate text-sm select-none',
                    displayValue
                        ? 'font-medium text-foreground'
                        : 'font-normal text-muted-foreground/60',
                )}
            >
                {displayValue || placeholderText}
            </span>

            <div className="flex shrink-0 items-center gap-1.5">
                {clearable && displayValue && !disabled ? (
                    <button
                        type="button"
                        tabIndex={-1}
                        aria-label="Clear date"
                        className="z-10 rounded-md p-0.5 text-muted-foreground/60 transition-colors hover:bg-muted/50 hover:text-foreground"
                        onClick={handleClear}
                    >
                        <X className="size-3.5" />
                    </button>
                ) : null}
                <Calendar className="pointer-events-none size-4 shrink-0 text-muted-foreground/70" />
            </div>

            <input
                ref={inputRef}
                id={id}
                name={name}
                type="date"
                disabled={disabled}
                value={value ?? ''}
                min={min}
                max={max}
                required={required}
                aria-invalid={ariaInvalid}
                onChange={handleChange}
                onClick={(e) => {
                    onClick?.(e);

                    if (!disabled) {
                        try {
                            e.currentTarget.showPicker?.();
                        } catch {
                            // ignore
                        }
                    }
                }}
                onKeyDown={(e) => {
                    onKeyDown?.(e);

                    if (e.key === 'Enter' || e.key === ' ') {
                        try {
                            e.currentTarget.showPicker?.();
                        } catch {
                            // ignore
                        }
                    } else if (e.key === 'Backspace' || e.key === 'Delete') {
                        if (clearable) {
                            handleClear(e as unknown as React.MouseEvent);
                        }
                    }
                }}
                className="absolute inset-0 z-0 h-full w-full cursor-pointer opacity-0"
                tabIndex={disabled ? -1 : 0}
                {...props}
            />
        </div>
    );
});
