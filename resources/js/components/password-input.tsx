import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);

    const isControlled = props.value !== undefined;
    const hasTypedValue = isControlled && String(props.value ?? '').length > 0;
    const showRevealButton = !isControlled || hasTypedValue;

    return (
        <div className="relative w-full">
            <Input
                type={showPassword && showRevealButton ? 'text' : 'password'}
                className={cn('w-full', showRevealButton && 'pr-10', className)}
                ref={ref}
                {...props}
            />
            {showRevealButton ? (
                <button
                    type="button"
                    onClick={() => setShowPassword((prev) => !prev)}
                    className="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={
                        showPassword ? 'Hide password' : 'Show password'
                    }
                    tabIndex={-1}
                >
                    {showPassword ? (
                        <EyeOff className="size-4" />
                    ) : (
                        <Eye className="size-4" />
                    )}
                </button>
            ) : null}
        </div>
    );
}
