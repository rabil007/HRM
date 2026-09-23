import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementFilters,
    UserOption,
} from '@/types/recruitment';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: RequirementFilters;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
    };
    onApply: (draft: RequirementFilters) => void;
    onReset: () => void;
};

export function RequirementFiltersSheet({
    open,
    onOpenChange,
    filters,
    options,
    onApply,
    onReset,
}: Props) {
    const [draft, setDraft] = useState<RequirementFilters>(filters);
    const [prevOpen, setPrevOpen] = useState(open);

    if (open !== prevOpen) {
        setPrevOpen(open);

        if (open) {
            setDraft(filters);
        }
    }

    const filteredProjects = useMemo(() => {
        if (!draft.client_id) {
            return options.projects;
        }

        return options.projects.filter(
            (p) => String(p.client_id) === String(draft.client_id),
        );
    }, [draft.client_id, options.projects]);

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (draft.client_id) {
            count++;
        }

        if (draft.project_id) {
            count++;
        }

        if (draft.position_id) {
            count++;
        }

        if (draft.assigned_to) {
            count++;
        }

        if (draft.priority) {
            count++;
        }

        if (draft.deadline_health) {
            count++;
        }

        return count;
    }, [draft]);

    const handleClientChange = (val: string) => {
        setDraft((prev) => ({
            ...prev,
            client_id: val === 'all' ? null : val,
            // Reset project if it no longer belongs to selected client
            project_id:
                val !== 'all' && prev.project_id
                    ? options.projects.some(
                          (p) =>
                              String(p.id) === String(prev.project_id) &&
                              String(p.client_id) === val,
                      )
                        ? prev.project_id
                        : null
                    : prev.project_id,
        }));
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Filter Requirements
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-xs text-muted-foreground/80">
                        Refine the requirements list by client, project,
                        position, priority, or deadline.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-5 overflow-y-auto p-6">
                    {/* Client Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Client
                        </Label>
                        <AppSelect
                            value={
                                draft.client_id
                                    ? String(draft.client_id)
                                    : 'all'
                            }
                            onValueChange={handleClientChange}
                        >
                            <AppSelectItem value="all">
                                All Clients
                            </AppSelectItem>
                            {options.clients.map((client) => (
                                <AppSelectItem
                                    key={client.id}
                                    value={String(client.id)}
                                >
                                    {client.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {/* Project Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Project
                        </Label>
                        <AppSelect
                            value={
                                draft.project_id
                                    ? String(draft.project_id)
                                    : 'all'
                            }
                            onValueChange={(val) =>
                                setDraft((prev) => ({
                                    ...prev,
                                    project_id: val === 'all' ? null : val,
                                }))
                            }
                        >
                            <AppSelectItem value="all">
                                All Projects
                            </AppSelectItem>
                            {filteredProjects.map((project) => (
                                <AppSelectItem
                                    key={project.id}
                                    value={String(project.id)}
                                >
                                    {project.title}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {/* Position Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Position
                        </Label>
                        <AppSelect
                            value={
                                draft.position_id
                                    ? String(draft.position_id)
                                    : 'all'
                            }
                            onValueChange={(val) =>
                                setDraft((prev) => ({
                                    ...prev,
                                    position_id: val === 'all' ? null : val,
                                }))
                            }
                        >
                            <AppSelectItem value="all">
                                All Positions
                            </AppSelectItem>
                            {options.positions.map((pos) => (
                                <AppSelectItem
                                    key={pos.id}
                                    value={String(pos.id)}
                                >
                                    {pos.title}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {/* Recruiter / Owner Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Assigned Recruiter
                        </Label>
                        <AppSelect
                            value={
                                draft.assigned_to
                                    ? String(draft.assigned_to)
                                    : 'all'
                            }
                            onValueChange={(val) =>
                                setDraft((prev) => ({
                                    ...prev,
                                    assigned_to: val === 'all' ? null : val,
                                }))
                            }
                        >
                            <AppSelectItem value="all">
                                All Recruiters
                            </AppSelectItem>
                            {options.recruiters.map((recruiter) => (
                                <AppSelectItem
                                    key={recruiter.id}
                                    value={String(recruiter.id)}
                                >
                                    {recruiter.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {/* Priority Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Priority
                        </Label>
                        <AppSelect
                            value={draft.priority || 'all'}
                            onValueChange={(val) =>
                                setDraft((prev) => ({
                                    ...prev,
                                    priority: val === 'all' ? null : val,
                                }))
                            }
                        >
                            <AppSelectItem value="all">
                                All Priorities
                            </AppSelectItem>
                            <AppSelectItem value="normal">Normal</AppSelectItem>
                            <AppSelectItem value="urgent">Urgent</AppSelectItem>
                        </AppSelect>
                    </div>

                    {/* Deadline Health Filter */}
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Deadline Health
                        </Label>
                        <AppSelect
                            value={draft.deadline_health || 'all'}
                            onValueChange={(val) =>
                                setDraft((prev) => ({
                                    ...prev,
                                    deadline_health: val === 'all' ? null : val,
                                }))
                            }
                        >
                            <AppSelectItem value="all">
                                All Statuses
                            </AppSelectItem>
                            <AppSelectItem value="on_track">
                                On Track
                            </AppSelectItem>
                            <AppSelectItem value="due_soon">
                                Due in 7 days
                            </AppSelectItem>
                            <AppSelectItem value="overdue">
                                Overdue
                            </AppSelectItem>
                        </AppSelect>
                    </div>
                </div>

                <SheetFooter className="flex flex-row items-center justify-between gap-3 border-t border-border/60 p-6">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            onReset();
                            onOpenChange(false);
                        }}
                    >
                        Reset All
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            onApply(draft);
                            onOpenChange(false);
                        }}
                    >
                        Apply Filters
                        {activeFilterCount > 0 && ` (${activeFilterCount})`}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
