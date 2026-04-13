import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';

export function SearchBar({
    value,
    onChange,
    placeholder,
    right,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    right?: ReactNode;
}) {
    return (
        <div className="flex items-center gap-4 mb-8">
            <div className="relative flex-1 group">
                <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground transition-colors group-focus-within:text-foreground" />
                <Input
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="pl-10 rounded-xl border-white/5 bg-white/5 focus-visible:ring-primary/20 focus-visible:bg-white/10 transition-all py-6 text-base"
                />
            </div>
            {right}
        </div>
    );
}

