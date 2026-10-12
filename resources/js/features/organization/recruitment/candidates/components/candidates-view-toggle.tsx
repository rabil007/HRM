import { Kanban, List, Pin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { CandidatesViewPreference } from '../lib/use-candidates-view-preference';

export function CandidatesViewToggle({
    value,
    pinnedValue,
    onChange,
    onPin,
}: {
    value: CandidatesViewPreference;
    pinnedValue: CandidatesViewPreference;
    onChange: (next: CandidatesViewPreference) => void;
    onPin: (next: CandidatesViewPreference) => void;
}) {
    const isPinned = pinnedValue === value;

    return (
        <div className="flex items-center rounded-xl glass-card p-1">
            <Button
                type="button"
                variant={value === 'table' ? 'default' : 'ghost'}
                className={
                    value === 'table'
                        ? 'h-9 rounded-lg px-2.5'
                        : 'h-9 rounded-lg px-2.5 hover:bg-accent'
                }
                onClick={() => onChange('table')}
                title="Table view"
                aria-label="Table view"
                aria-pressed={value === 'table'}
            >
                <List className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant={value === 'kanban' ? 'default' : 'ghost'}
                className={
                    value === 'kanban'
                        ? 'h-9 rounded-lg px-2.5'
                        : 'h-9 rounded-lg px-2.5 hover:bg-accent'
                }
                onClick={() => onChange('kanban')}
                title="Kanban view"
                aria-label="Kanban view"
                aria-pressed={value === 'kanban'}
            >
                <Kanban className="h-4 w-4" />
            </Button>
            <div className="mx-1 h-5 w-px bg-border/60" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className={cn(
                    'h-9 w-9 rounded-lg hover:bg-accent',
                    isPinned && 'text-primary',
                )}
                onClick={() => {
                    onPin(value);
                    toast.success(
                        `Pinned ${value === 'kanban' ? 'Kanban' : 'Table'} as your default view.`,
                    );
                }}
                title={
                    isPinned
                        ? `Pinned default: ${value === 'kanban' ? 'Kanban' : 'Table'}`
                        : 'Pin current view as default'
                }
                aria-label={
                    isPinned
                        ? `Pinned default is ${value}`
                        : 'Pin current view as default'
                }
                aria-pressed={isPinned}
            >
                <Pin className={cn('h-4 w-4', isPinned && 'fill-current')} />
            </Button>
        </div>
    );
}
