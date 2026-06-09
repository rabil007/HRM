import { Lock } from 'lucide-react';
import type { ComponentProps } from 'react';
import PasswordInput from '@/components/password-input';
import { cn } from '@/lib/utils';

type Props = Omit<ComponentProps<typeof PasswordInput>, 'type'>;

export function SettingsSecretInput({ className, ...props }: Props) {
    return (
        <div className="relative">
            <Lock className="pointer-events-none absolute left-3.5 top-1/2 z-10 size-4 -translate-y-1/2 text-muted-foreground/40" />
            <PasswordInput
                {...props}
                className={cn(
                    'h-11 rounded-xl border-input bg-background/50 pl-10 pr-10 focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5',
                    className,
                )}
            />
        </div>
    );
}
