import { FileStack, History } from 'lucide-react';
import { cn } from '@/lib/utils';

export type BulkDocumentsView = 'roster' | 'history';

export function BulkDocumentsViewSwitcher({
    value,
    onChange,
}: {
    value: BulkDocumentsView;
    onChange: (next: BulkDocumentsView) => void;
}) {
    return (
        <div className="inline-flex items-center gap-0.5 rounded-lg bg-muted/60 p-0.5">
            <button
                type="button"
                onClick={() => onChange('roster')}
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                    value === 'roster'
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                <FileStack className="h-3.5 w-3.5" />
                Employees
            </button>
            <button
                type="button"
                onClick={() => onChange('history')}
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                    value === 'history'
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                <History className="h-3.5 w-3.5" />
                History
            </button>
        </div>
    );
}
