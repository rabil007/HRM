import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export function SearchBar({
    value,
    onChange,
    placeholder,
    right,
    className,
    inputClassName,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    right?: ReactNode;
    className?: string;
    inputClassName?: string;
}) {
    return (
        <div className={cn('mb-8 flex items-center gap-4', className)}>
            <div className="group relative min-w-0 flex-1">
                <Search className="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground transition-colors group-focus-within:text-foreground" />
                <Input
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={cn(
                        'rounded-xl border-input bg-background/80 py-6 pl-10 text-base transition-all focus-visible:bg-background focus-visible:ring-primary/20 dark:border-white/5 dark:bg-white/5 dark:focus-visible:bg-white/10',
                        inputClassName,
                    )}
                />
            </div>
            {right}
        </div>
    );
}
