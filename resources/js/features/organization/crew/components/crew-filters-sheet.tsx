import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { CREW_TOUR_STATUS_FILTER_OPTIONS } from '@/features/organization/crew/lib/tour-of-duty';
import type {
    CrewAssignmentFilterOptions,
    CrewAssignmentFilters,
} from '@/features/organization/crew/types';
import { CREW_PHASE_LABELS } from '@/features/organization/crew/types';

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export function CrewFiltersSheet({
    open,
    onOpenChange,
    filterOptions,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filterOptions: CrewAssignmentFilterOptions;
    value: CrewAssignmentFilters;
    onChange: (next: CrewAssignmentFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Current phase
                </Label>
                <AppSelect
                    value={value.phase}
                    onValueChange={(phase) => onChange({ ...value, phase })}
                    variant="dark"
                    placeholder="All phases"
                >
                    <AppSelectItem value="">All phases</AppSelectItem>
                    {Object.entries(CREW_PHASE_LABELS).map(([code, label]) => (
                        <AppSelectItem key={code} value={code}>
                            {code.toUpperCase()} · {label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Status
                </Label>
                <AppSelect
                    value={value.status}
                    onValueChange={(status) => onChange({ ...value, status })}
                    variant="dark"
                    placeholder="All statuses"
                >
                    {STATUS_OPTIONS.map((option) => (
                        <AppSelectItem
                            key={option.value || 'all'}
                            value={option.value}
                        >
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Tour status
                </Label>
                <AppSelect
                    value={value.tour_status}
                    onValueChange={(tourStatus) =>
                        onChange({ ...value, tour_status: tourStatus })
                    }
                    variant="dark"
                    placeholder="All tour statuses"
                >
                    {CREW_TOUR_STATUS_FILTER_OPTIONS.map((option) => (
                        <AppSelectItem
                            key={option.value || 'all'}
                            value={option.value}
                        >
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Relief status
                </Label>
                <AppSelect
                    value={value.relief_status}
                    onValueChange={(reliefStatus) =>
                        onChange({ ...value, relief_status: reliefStatus })
                    }
                    variant="dark"
                    placeholder="All relief statuses"
                >
                    <AppSelectItem value="">All relief statuses</AppSelectItem>
                    {(filterOptions.relief_statuses ?? []).map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Relief risk
                </Label>
                <AppSelect
                    value={value.relief_risk}
                    onValueChange={(reliefRisk) =>
                        onChange({ ...value, relief_risk: reliefRisk })
                    }
                    variant="dark"
                    placeholder="All relief risks"
                >
                    <AppSelectItem value="">All relief risks</AppSelectItem>
                    {(filterOptions.relief_risks ?? []).map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Vessel
                </Label>
                <AppSelect
                    value={value.vessel_id}
                    onValueChange={(vesselId) =>
                        onChange({ ...value, vessel_id: vesselId })
                    }
                    variant="dark"
                    placeholder="All vessels"
                    searchPlaceholder="Search vessel..."
                >
                    <AppSelectItem value="">All vessels</AppSelectItem>
                    {filterOptions.vessels.map((vessel) => (
                        <AppSelectItem
                            key={vessel.id}
                            value={String(vessel.id)}
                        >
                            {vessel.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Rank
                </Label>
                <AppSelect
                    value={value.rank_id}
                    onValueChange={(rankId) =>
                        onChange({ ...value, rank_id: rankId })
                    }
                    variant="dark"
                    placeholder="All ranks"
                    searchPlaceholder="Search rank..."
                >
                    <AppSelectItem value="">All ranks</AppSelectItem>
                    {filterOptions.ranks.map((rank) => (
                        <AppSelectItem key={rank.id} value={String(rank.id)}>
                            {rank.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Client
                </Label>
                <AppSelect
                    value={value.client_id}
                    onValueChange={(clientId) =>
                        onChange({ ...value, client_id: clientId })
                    }
                    variant="dark"
                    placeholder="All clients"
                    searchPlaceholder="Search client..."
                >
                    <AppSelectItem value="">All clients</AppSelectItem>
                    {filterOptions.clients.map((client) => (
                        <AppSelectItem
                            key={client.id}
                            value={String(client.id)}
                        >
                            {client.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Employee
                </Label>
                <AppSelect
                    value={value.employee_id}
                    onValueChange={(employeeId) =>
                        onChange({ ...value, employee_id: employeeId })
                    }
                    variant="dark"
                    placeholder="All employees"
                    searchPlaceholder="Search employee..."
                >
                    <AppSelectItem value="">All employees</AppSelectItem>
                    {filterOptions.employees.map((employee) => (
                        <AppSelectItem
                            key={employee.id}
                            value={String(employee.id)}
                        >
                            {employee.name}
                            {employee.employee_no
                                ? ` (${employee.employee_no})`
                                : ''}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Planned join from
                    </Label>
                    <Input
                        type="date"
                        value={value.planned_join_from}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                planned_join_from: event.target.value,
                            })
                        }
                        className="h-11"
                    />
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Planned join to
                    </Label>
                    <Input
                        type="date"
                        value={value.planned_join_to}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                planned_join_to: event.target.value,
                            })
                        }
                        className="h-11"
                    />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Planned sign-off from
                    </Label>
                    <Input
                        type="date"
                        value={value.planned_signoff_from}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                planned_signoff_from: event.target.value,
                            })
                        }
                        className="h-11"
                    />
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Planned sign-off to
                    </Label>
                    <Input
                        type="date"
                        value={value.planned_signoff_to}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                planned_signoff_to: event.target.value,
                            })
                        }
                        className="h-11"
                    />
                </div>
            </div>

            <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">Needs attention</p>
                    <p className="text-xs text-muted-foreground">
                        Overdue plans, stale phases, missing vessel/rank
                    </p>
                </div>
                <Switch
                    checked={value.movement_attention}
                    onCheckedChange={(checked) =>
                        onChange({ ...value, movement_attention: checked })
                    }
                />
            </div>

            <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">Relief not ready</p>
                    <p className="text-xs text-muted-foreground">
                        On-vessel crew without ready-to-join relief
                    </p>
                </div>
                <Switch
                    checked={value.relief_not_ready}
                    onCheckedChange={(checked) =>
                        onChange({ ...value, relief_not_ready: checked })
                    }
                />
            </div>

            <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">
                        Sign-off within 14 days — no relief
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Planned sign-off soon without a relief planned
                    </p>
                </div>
                <Switch
                    checked={value.signoff_within_14_no_relief}
                    onCheckedChange={(checked) =>
                        onChange({
                            ...value,
                            signoff_within_14_no_relief: checked,
                        })
                    }
                />
            </div>

            <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                <div>
                    <p className="text-sm font-medium">Include completed</p>
                    <p className="text-xs text-muted-foreground">
                        Show closed and cancelled assignments
                    </p>
                </div>
                <Switch
                    checked={value.include_completed}
                    onCheckedChange={(checked) =>
                        onChange({ ...value, include_completed: checked })
                    }
                />
            </div>
        </FiltersSheet>
    );
}
