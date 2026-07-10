import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type {
    CompanyVisaTypeOption,
    CountryOption,
    GenderOption,
    ApprovalLocationOption,
    ManagerOption,
    PositionOption,
    RankOption,
    RoleOption,
    SssaOption,
    VisaTypeOption,
} from '../types';

export type EmployeeFilters = {
    department_id: string;
    position_id: string;
    status: string;
    manager_id: string;
    gender_id: string;
    nationality_id: string;
    visa_type_id: string;
    company_visa_type_id: string;
    rank_id: string;
    approval_location_id: string;
    sssa_option_id: string;
    crew_status: string;
    role_id: string;
};

export const EMPTY_EMPLOYEE_FILTERS: EmployeeFilters = {
    department_id: '',
    position_id: '',
    status: '',
    manager_id: '',
    gender_id: '',
    nationality_id: '',
    visa_type_id: '',
    company_visa_type_id: '',
    rank_id: '',
    approval_location_id: '',
    sssa_option_id: '',
    crew_status: '',
    role_id: '',
};

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
    roles: RoleOption[];
}) {
    const selectedApprovalLocationIds = csvIdSet(value.approval_location_id);
    const selectedSssaOptionIds = csvIdSet(value.sssa_option_id);

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                {/* Employment Group */}
                <div className="border-b border-border/40 pt-2 pb-2 first:pt-0 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Employment
                    </span>
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

                {/* Identity & Visa Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Identity & Visa
                    </span>
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

                {/* Status Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Status
                    </span>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        HR status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(v) => onChange({ ...value, status: v })}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="active">Active</AppSelectItem>
                        <AppSelectItem value="inactive">Inactive</AppSelectItem>
                        <AppSelectItem value="on_leave">On leave</AppSelectItem>
                        <AppSelectItem value="terminated">
                            Terminated
                        </AppSelectItem>
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Crew status
                    </Label>
                    <AppSelect
                        value={value.crew_status}
                        onValueChange={(v) =>
                            onChange({ ...value, crew_status: v })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="available">
                            Available
                        </AppSelectItem>
                        <AppSelectItem value="on_vessel">
                            On vessel
                        </AppSelectItem>
                        <AppSelectItem value="join_standby">
                            Join standby
                        </AppSelectItem>
                        <AppSelectItem value="leave_standby">
                            Leave standby
                        </AppSelectItem>
                        <AppSelectItem value="arrived">Arrived</AppSelectItem>
                        <AppSelectItem value="travel">Travelled</AppSelectItem>
                        <AppSelectItem value="disembarked">
                            Disembarked
                        </AppSelectItem>
                        <AppSelectItem value="in_home">In home</AppSelectItem>
                    </AppSelect>
                </div>

                {/* Deployment & Association Group */}
                <div className="border-b border-border/40 pt-4 pb-2 sm:col-span-2">
                    <span className="text-[11px] font-bold tracking-wider text-primary uppercase">
                        Deployment & Association
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
            </div>
        </FiltersSheet>
    );
}
