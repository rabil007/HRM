import { useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    EMPTY_EMPLOYEE_FILTERS,
    filterProjectsByClient,
    resolveProjectOnClientChange,
} from '@/features/organization/employees/lib/employee-client-project-filter';
import type { EmployeeFilters } from '@/features/organization/employees/lib/employee-client-project-filter';
import {
    applyEmiratesIdPresence,
    completenessChips,
    emiratesIdPresenceValue,
    removeCompletenessKey,
    STATUS_OPTION_LABELS,
} from '@/features/organization/employees/lib/employee-smart-search';
import type {
    ApprovalLocationOption,
    ClientOption,
    CompanyVisaTypeOption,
    CountryOption,
    GenderOption,
    ManagerOption,
    PositionOption,
    ProjectOption,
    RankOption,
    RoleOption,
    SssaOption,
    VisaTypeOption,
} from '../types';

export { EMPTY_EMPLOYEE_FILTERS, type EmployeeFilters };

function csvIdSet(csv: string): Set<string> {
    return new Set(
        csv
            .split(',')
            .map((v) => v.trim())
            .filter((v) => v !== ''),
    );
}

function toggleCsvId(csv: string, id: string, checked: boolean): string {
    const ids = csv
        .split(',')
        .map((v) => v.trim())
        .filter((v) => v !== '');

    if (checked) {
        return ids.includes(id) ? ids.join(',') : [...ids, id].join(',');
    }

    return ids.filter((value) => value !== id).join(',');
}

export function EmployeeFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
    positions,
    managers,
    genders,
    countries,
    visaTypes,
    companyVisaTypes,
    approvalLocations,
    sssaOptions,
    ranks,
    clients,
    projects,
    roles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: EmployeeFilters;
    onChange: (next: EmployeeFilters) => void;
    onReset: () => void;
    positions: PositionOption[];
    managers: ManagerOption[];
    genders: GenderOption[];
    countries: CountryOption[];
    visaTypes: VisaTypeOption[];
    companyVisaTypes: CompanyVisaTypeOption[];
    approvalLocations: ApprovalLocationOption[];
    sssaOptions: SssaOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    projects: ProjectOption[];
    roles: RoleOption[];
}) {
    const selectedApprovalLocationIds = csvIdSet(value.approval_location_id);
    const selectedSssaOptionIds = csvIdSet(value.sssa_option_id);

    const filteredProjects = useMemo(
        () => filterProjectsByClient(projects, value.client_id),
        [projects, value.client_id],
    );

    const handleClientChange = (nextClientId: string) => {
        const nextProjectId = resolveProjectOnClientChange(
            value.project_id,
            nextClientId,
            projects,
        );

        onChange({
            ...value,
            client_id: nextClientId,
            project_id: nextProjectId,
        });
    };

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                {/* Employment Group */}
                <div className="border-b border-border/40 pt-2 pb-2 first:pt-0 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Employment
                    </span>
                </div>

                {/* Row 1: HR Status | Position */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        HR status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) => onChange({ ...value, status: v })}
                        variant="dark"
                        placeholder={STATUS_OPTION_LABELS['']}
                    >
                        <AppSelectItem value="">
                            {STATUS_OPTION_LABELS['']}
                        </AppSelectItem>
                        <AppSelectItem value="all">
                            {STATUS_OPTION_LABELS.all}
                        </AppSelectItem>
                        <AppSelectItem value="active">
                            {STATUS_OPTION_LABELS.active}
                        </AppSelectItem>
                        <AppSelectItem value="inactive">
                            {STATUS_OPTION_LABELS.inactive}
                        </AppSelectItem>
                        <AppSelectItem value="on_leave">
                            {STATUS_OPTION_LABELS.on_leave}
                        </AppSelectItem>
                        <AppSelectItem value="terminated">
                            {STATUS_OPTION_LABELS.terminated}
                        </AppSelectItem>
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Position
                    </Label>
                    <AppSelect
                        value={value.position_id}
                        onValueChange={(v) =>
                            onChange({ ...value, position_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {positions.map((p) => (
                            <AppSelectItem key={p.id} value={String(p.id)}>
                                {p.title ?? `#${p.id}`}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Row 2: Manager | Role */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Manager
                    </Label>
                    <AppSelect
                        value={value.manager_id}
                        onValueChange={(v) =>
                            onChange({ ...value, manager_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {managers.map((m) => (
                            <AppSelectItem key={m.id} value={String(m.id)}>
                                {m.name} ({m.employee_no})
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Role
                    </Label>
                    <AppSelect
                        value={value.role_id}
                        onValueChange={(v) =>
                            onChange({ ...value, role_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {roles.map((r) => (
                            <AppSelectItem key={r.id} value={String(r.id)}>
                                {r.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Work Assignment Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Work Assignment
                    </span>
                </div>

                {/* Row 1: Client | Project */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Client
                    </Label>
                    <AppSelect
                        value={value.client_id}
                        onValueChange={handleClientChange}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {clients.map((client) => (
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
                        Project
                    </Label>
                    <AppSelect
                        value={value.project_id}
                        onValueChange={(v) =>
                            onChange({ ...value, project_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
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

                {/* Row 2: Rank */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Rank
                    </Label>
                    <AppSelect
                        value={value.rank_id}
                        onValueChange={(v) =>
                            onChange({ ...value, rank_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {ranks.map((r) => (
                            <AppSelectItem key={r.id} value={String(r.id)}>
                                {r.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Identity & Visa Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Identity & Visa
                    </span>
                </div>

                {/* Row 1: Nationality | Gender */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Nationality
                    </Label>
                    <AppSelect
                        value={value.nationality_id}
                        onValueChange={(v) =>
                            onChange({ ...value, nationality_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {countries.map((c) => (
                            <AppSelectItem key={c.id} value={String(c.id)}>
                                {c.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Gender
                    </Label>
                    <AppSelect
                        value={value.gender_id}
                        onValueChange={(v) =>
                            onChange({ ...value, gender_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {genders.map((g) => (
                            <AppSelectItem key={g.id} value={String(g.id)}>
                                {g.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Row 2: Emirates ID | Visa Type */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Emirates ID
                    </Label>
                    <AppSelect
                        value={emiratesIdPresenceValue(value)}
                        onValueChange={(v) =>
                            onChange(applyEmiratesIdPresence(value, v))
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="missing">Missing</AppSelectItem>
                        <AppSelectItem value="present">Present</AppSelectItem>
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Visa type
                    </Label>
                    <AppSelect
                        value={value.visa_type_id}
                        onValueChange={(v) =>
                            onChange({ ...value, visa_type_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {visaTypes.map((v) => (
                            <AppSelectItem key={v.id} value={String(v.id)}>
                                {v.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Row 3: Sponsor */}
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Sponsor
                    </Label>
                    <AppSelect
                        value={value.company_visa_type_id}
                        onValueChange={(v) =>
                            onChange({ ...value, company_visa_type_id: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {companyVisaTypes.map((v) => (
                            <AppSelectItem key={v.id} value={String(v.id)}>
                                {v.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                {/* Work Eligibility Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Work Eligibility
                    </span>
                </div>

                <div className="space-y-2 sm:col-span-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Approval location
                    </Label>
                    <div className="grid grid-cols-2 gap-2">
                        {approvalLocations.map((location) => {
                            const id = String(location.id);
                            const checked = selectedApprovalLocationIds.has(id);

                            return (
                                <label
                                    key={location.id}
                                    className="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-muted/20 px-2 py-1.5 transition-colors hover:bg-muted/40 dark:border-white/5 dark:bg-white/[0.02] dark:hover:bg-white/[0.04]"
                                >
                                    <Checkbox
                                        checked={checked}
                                        onCheckedChange={(v) =>
                                            onChange({
                                                ...value,
                                                approval_location_id:
                                                    toggleCsvId(
                                                        value.approval_location_id,
                                                        id,
                                                        v === true,
                                                    ),
                                            })
                                        }
                                    />
                                    <span className="min-w-0 text-sm text-foreground">
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span className="block cursor-help truncate">
                                                    {location.name}
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                side="top"
                                                align="start"
                                            >
                                                {location.name}
                                            </TooltipContent>
                                        </Tooltip>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </div>

                <div className="space-y-2 sm:col-span-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        SSSA
                    </Label>
                    <div className="grid grid-cols-2 gap-2">
                        {sssaOptions.map((option) => {
                            const id = String(option.id);
                            const checked = selectedSssaOptionIds.has(id);

                            return (
                                <label
                                    key={option.id}
                                    className="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-muted/20 px-2 py-1.5 transition-colors hover:bg-muted/40 dark:border-white/5 dark:bg-white/[0.02] dark:hover:bg-white/[0.04]"
                                >
                                    <Checkbox
                                        checked={checked}
                                        onCheckedChange={(v) =>
                                            onChange({
                                                ...value,
                                                sssa_option_id: toggleCsvId(
                                                    value.sssa_option_id,
                                                    id,
                                                    v === true,
                                                ),
                                            })
                                        }
                                    />
                                    <span className="min-w-0 text-sm text-foreground">
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span className="block cursor-help truncate">
                                                    {option.name}
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                side="top"
                                                align="start"
                                            >
                                                {option.name}
                                            </TooltipContent>
                                        </Tooltip>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </div>

                {completenessChips(value).length > 0 ? (
                    <div className="space-y-2 sm:col-span-2">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Data completeness
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            {completenessChips(value).map((chip) => {
                                const [concept, operator] = chip.key.split(':');

                                return (
                                    <Button
                                        key={chip.key}
                                        type="button"
                                        variant="outline"
                                        className="h-8 rounded-full px-3 text-xs font-normal"
                                        onClick={() =>
                                            onChange(
                                                removeCompletenessKey(
                                                    value,
                                                    operator === 'present'
                                                        ? 'present'
                                                        : 'missing',
                                                    concept,
                                                ),
                                            )
                                        }
                                        aria-label={`Remove ${chip.label} ${chip.title}`}
                                    >
                                        {chip.label} · {chip.title}
                                        <span aria-hidden="true">×</span>
                                    </Button>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </div>
        </FiltersSheet>
    );
}
