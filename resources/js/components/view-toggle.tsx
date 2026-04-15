import { LayoutGrid, List, Pin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { setOrganizationDefaultView } from '@/hooks/use-view-preference';
import type { ViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';

export function ViewToggle({
    value,
    onChange,
    gridLabel = 'Grid view',
    listLabel = 'List view',
    defaultLabel = 'Set as default',
}: {
    value: ViewPreference;
    onChange: (next: ViewPreference) => void;
    gridLabel?: string;
    listLabel?: string;
    defaultLabel?: string;
}) {
    return (
        <div className="flex items-center rounded-xl border border-white/5 bg-white/5 p-1">
            <Button
                type="button"
                variant={value === 'grid' ? 'default' : 'ghost'}
                className={value === 'grid' ? 'rounded-lg h-11 px-3' : 'rounded-lg h-11 px-3 hover:bg-white/10'}
                onClick={() => onChange('grid')}
                title={gridLabel}
            >
                <LayoutGrid className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant={value === 'list' ? 'default' : 'ghost'}
                className={value === 'list' ? 'rounded-lg h-11 px-3' : 'rounded-lg h-11 px-3 hover:bg-white/10'}
                onClick={() => onChange('list')}
                title={listLabel}
            >
                <List className="h-4 w-4" />
            </Button>
            <div className="mx-1 h-6 w-px bg-white/10" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-11 w-11 rounded-lg hover:bg-white/10"
                onClick={() => {
                    setOrganizationDefaultView(value);
                    toast.success(`Default view set to ${value === 'list' ? 'List' : 'Grid'}.`);
                }}
                title={defaultLabel}
            >
                <Pin className="h-4 w-4" />
            </Button>
        </div>
    );
}

