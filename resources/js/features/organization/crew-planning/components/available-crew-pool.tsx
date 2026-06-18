import { useDraggable } from '@dnd-kit/core';
import { ChevronDown, ChevronRight, GripVertical, Search, Users, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { CrewDragData, PlanningPoolEmployee } from '../types';

function DraggableCrewItem({ employee }: { employee: PlanningPoolEmployee }): ReactElement {
    const dragData: CrewDragData = {
        type: 'crew',
        employeeId: employee.id,
        employeeName: employee.name,
        rankId: employee.rank_id,
        rankName: employee.rank_name,
    };

    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: `crew:${employee.id}`,
        data: dragData,
    });

    return (
        <div
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            className={cn(
                'flex cursor-grab items-center gap-2 rounded px-2 py-1 text-xs transition-colors hover:bg-muted/60 active:cursor-grabbing',
                isDragging && 'opacity-40',
            )}
        >
            <GripVertical className="h-3 w-3 shrink-0 text-muted-foreground/50" />
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium">{employee.name}</div>
                <div className="truncate text-[10px] text-muted-foreground">{employee.rank_name}</div>
            </div>
        </div>
    );
}

type Props = {
    employees: PlanningPoolEmployee[];
};

export function AvailableCrewPool({ employees }: Props): ReactElement {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const lowerSearch = search.trim().toLowerCase();

    const filteredEmployees = useMemo(() => {
        if (lowerSearch === '') {
            return employees;
        }

        return employees.filter(
            (employee) =>
                employee.name.toLowerCase().includes(lowerSearch) ||
                employee.rank_name.toLowerCase().includes(lowerSearch),
        );
    }, [employees, lowerSearch]);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger asChild>
                <button className="flex w-full items-center gap-2 border-t px-3 py-2 text-left text-sm font-semibold hover:bg-muted/50">
                    {open ? (
                        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <Users className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span className="truncate">Available crew</span>
                    <span className="ml-auto shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                        {lowerSearch !== '' ? `${filteredEmployees.length}/${employees.length}` : employees.length}
                    </span>
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="space-y-1 px-2 pb-2">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-2 h-3 w-3 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by name or rank…"
                            className="h-7 pr-7 pl-7 text-xs"
                            aria-label="Search available crew"
                        />
                        {search !== '' ? (
                            <button
                                type="button"
                                className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                onClick={() => setSearch('')}
                                aria-label="Clear crew search"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        ) : null}
                    </div>

                    <div className="max-h-48 space-y-0.5 overflow-y-auto">
                        {filteredEmployees.length === 0 ? (
                            <p className="px-1 py-2 text-xs text-muted-foreground/60">
                                {lowerSearch !== ''
                                    ? 'No crew matching search.'
                                    : 'No ranked crew available. Assign a rank on the employee profile first.'}
                            </p>
                        ) : (
                            filteredEmployees.map((employee) => (
                                <DraggableCrewItem key={employee.id} employee={employee} />
                            ))
                        )}
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
