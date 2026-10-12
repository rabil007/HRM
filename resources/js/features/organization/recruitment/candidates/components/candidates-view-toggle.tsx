import { Kanban, List, Pin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { toast } from '@/lib/toast';
import type { CandidatesViewPreference } from '../lib/use-candidates-view-preference';

export function CandidatesViewToggle({
    value,
    onChange,
    onPin,
}: {
    value: CandidatesViewPreference;
    onChange: (next: CandidatesViewPreference) => void;
    onPin: (next: CandidatesViewPreference) => void;
}) {
    return (
        <div className="flex items-center rounded-xl glass-card p-1">
            <Button
                type="button"
                variant={value === 'table' ? 'default' : 'ghost'}
                className={
                    value === 'table'
                        ? 'h-11 rounded-lg px-3'
                        : 'h-11 rounded-lg px-3 hover:bg-accent'
                }
                onClick={() => onChange('table')}
                title="Table view"
                aria-label="Table view"
            >
                <List className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant={value === 'kanban' ? 'default' : 'ghost'}
                className={
                    value === 'kanban'
                        ? 'h-11 rounded-lg px-3'
                        : 'h-11 rounded-lg px-3 hover:bg-accent'
                }
                onClick={() => onChange('kanban')}
                title="Kanban view"
                aria-label="Kanban view"
            >
                <Kanban className="h-4 w-4" />
            </Button>
            <div className="mx-1 h-6 w-px bg-border/60" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-11 w-11 rounded-lg hover:bg-accent"
                onClick={() => {
                    onPin(value);
                    toast.success(
                        `Default view set to ${value === 'kanban' ? 'Kanban' : 'Table'}.`,
                    );
                }}
                title="Set as default"
                aria-label="Set as default view"
            >
                <Pin className="h-4 w-4" />
            </Button>
        </div>
    );
}
