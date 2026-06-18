import { useDraggable } from '@dnd-kit/core';
import { ChevronDown, ChevronRight, GripVertical, Search, Users, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { CrewDragData, PlanningPoolEmployee } from '../types';

function avatarColor(name: string): string {
    const colors = [
        'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
        'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
    ];
    const index = [...name].reduce((acc, c) => acc + c.charCodeAt(0), 0) % colors.length;

    return colors[index];
}

function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

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
                'flex cursor-grab items-center gap-2.5 rounded-md px-2 py-1.5 text-xs transition-all hover:bg-muted/70 active:cursor-grabbing active:scale-95',
                isDragging && 'opacity-40',
            )}
        >
            <div
                className={cn(
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                    avatarColor(employee.name),
                )}
            >
                {initials(employee.name)}
            </div>
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium leading-tight">{employee.name}</div>
                <div className="truncate text-[10px] text-muted-foreground/70">{employee.rank_name}</div>
            </div>
            <GripVertical className="h-3 w-3 shrink-0 text-muted-foreground/30" />
        </div>
    );
}

type Props = {
    employees: PlanningPoolEmployee[];
};

export function CrewPool({ employees }: Props): ReactElement {
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
                <button className="flex w-full items-center gap-2 border-t px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-muted/40">
                    <Users className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">Crew</span>
                    <span className="ml-auto shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary/70">
                        {lowerSearch !== '' ? `${filteredEmployees.length}/${employees.length}` : employees.length}
                    </span>
                    {open ? (
                        <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                    ) : (
                        <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                    )}
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="px-2 pb-3">
                    <div className="relative mb-1.5">
                        <Search className="absolute top-1/2 left-2.5 h-3 w-3 -translate-y-1/2 text-muted-foreground/60" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by name or rank…"
                            className="h-7 rounded-md pr-7 pl-7 text-xs"
                            aria-label="Search crew"
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

                    <div className="max-h-52 space-y-0.5 overflow-y-auto">
                        {filteredEmployees.length === 0 ? (
                            <p className="px-2 py-3 text-center text-xs text-muted-foreground/50">
                                {lowerSearch !== ''
                                    ? 'No crew matching search.'
                                    : 'No crew in the selected departments.'}
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
