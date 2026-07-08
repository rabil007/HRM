import { FileStack, History } from 'lucide-react';
import { Button } from '@/components/ui/button';
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
        <div className="flex items-center rounded-xl glass-card p-1">
            <Button
                type="button"
                variant={value === 'roster' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-4 text-sm font-medium',
                    value !== 'roster' && 'hover:bg-accent',
                )}
                onClick={() => onChange('roster')}
            >
                <FileStack className="mr-2 h-4 w-4" />
                Employees
            </Button>
            <Button
                type="button"
                variant={value === 'history' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-4 text-sm font-medium',
                    value !== 'history' && 'hover:bg-accent',
                )}
                onClick={() => onChange('history')}
            >
                <History className="mr-2 h-4 w-4" />
                History
            </Button>
        </div>
    );
}
