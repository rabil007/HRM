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
        <div className={cn('flex items-center gap-4 mb-8', className)}>
            <div className="relative flex-1 group min-w-0">
                <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground transition-colors group-focus-within:text-foreground" />
                <Input
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={cn(
                        'pl-10 rounded-xl border-white/5 bg-white/5 focus-visible:ring-primary/20 focus-visible:bg-white/10 transition-all py-6 text-base',
                        inputClassName,
                    )}
                />
            </div>
            {right}
        </div>
    );
}

