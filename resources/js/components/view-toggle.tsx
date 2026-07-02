import { Folder, LayoutGrid, List, Network, Pin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { setOrganizationDefaultView } from '@/hooks/use-view-preference';
import type { ViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';

export function ViewToggle({
    value,
    onChange,
    gridLabel = 'Grid view',
    listLabel = 'List view',
    employeeLabel = 'By employee',
    treeLabel = 'Tree view',
    showEmployeeView = false,
    showTreeView = false,
    defaultLabel = 'Set as default',
}: {
    value: ViewPreference;
    onChange: (next: ViewPreference) => void;
    gridLabel?: string;
    listLabel?: string;
    employeeLabel?: string;
    treeLabel?: string;
    showEmployeeView?: boolean;
    showTreeView?: boolean;
    defaultLabel?: string;
}) {
    return (
        <div className="flex items-center rounded-xl glass-card p-1">
            <Button
                type="button"
                variant={value === 'grid' ? 'default' : 'ghost'}
                className={
                    value === 'grid'
                        ? 'h-11 rounded-lg px-3'
                        : 'h-11 rounded-lg px-3 hover:bg-accent'
                }
                onClick={() => onChange('grid')}
                title={gridLabel}
            >
                <LayoutGrid className="h-4 w-4" />
            </Button>
            <Button
                type="button"
                variant={value === 'list' ? 'default' : 'ghost'}
                className={
                    value === 'list'
                        ? 'h-11 rounded-lg px-3'
                        : 'h-11 rounded-lg px-3 hover:bg-accent'
                }
                onClick={() => onChange('list')}
                title={listLabel}
            >
                <List className="h-4 w-4" />
            </Button>
            {showTreeView ? (
                <Button
                    type="button"
                    variant={value === 'tree' ? 'default' : 'ghost'}
                    className={
                        value === 'tree'
                            ? 'h-11 rounded-lg px-3'
                            : 'h-11 rounded-lg px-3 hover:bg-accent'
                    }
                    onClick={() => onChange('tree')}
                    title={treeLabel}
                >
                    <Network className="h-4 w-4" aria-hidden />
                </Button>
            ) : null}
            {showEmployeeView ? (
                <Button
                    type="button"
                    variant={value === 'employee' ? 'default' : 'ghost'}
                    className={
                        value === 'employee'
                            ? 'h-11 rounded-lg px-3'
                            : 'h-11 rounded-lg px-3 hover:bg-accent'
                    }
                    onClick={() => onChange('employee')}
                    title={employeeLabel}
                >
                    <Folder className="h-4 w-4" aria-hidden />
                </Button>
            ) : null}
            <div className="mx-1 h-6 w-px bg-border/60" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-11 w-11 rounded-lg hover:bg-accent"
                onClick={() => {
                    if (value !== 'employee') {
                        setOrganizationDefaultView(value);
                        toast.success(
                            `Default view set to ${value === 'list' ? 'List' : 'Grid'}.`,
                        );
                    }
                }}
                title={defaultLabel}
            >
                <Pin className="h-4 w-4" />
            </Button>
        </div>
    );
}
