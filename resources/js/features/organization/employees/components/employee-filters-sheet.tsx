import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type {
    BranchOption,
    CompanyVisaTypeOption,
    CountryOption,
    GenderOption,
    ManagerOption,
    PositionOption,
    RankOption,
    VisaTypeOption,
} from '../types';

export type EmployeeFilters = {
    branch_id: string;
    department_id: string;
    position_id: string;
    status: string;
    manager_id: string;
    gender_id: string;
    nationality_id: string;
    visa_type_id: string;
    company_visa_type_id: string;
    rank_id: string;
};

export const EMPTY_EMPLOYEE_FILTERS: EmployeeFilters = {
    branch_id: '',
    department_id: '',
    position_id: '',
    status: '',
    manager_id: '',
    gender_id: '',
    nationality_id: '',
    visa_type_id: '',
    company_visa_type_id: '',
    rank_id: '',
};

export function EmployeeFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
    branches,
    positions,
    managers,
    genders,
    countries,
    visaTypes,
    companyVisaTypes,
    ranks,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: EmployeeFilters;
    onChange: (next: EmployeeFilters) => void;
    onReset: () => void;
    branches: BranchOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    genders: GenderOption[];
    countries: CountryOption[];
    visaTypes: VisaTypeOption[];
    companyVisaTypes: CompanyVisaTypeOption[];
    ranks: RankOption[];
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Branch
                    </Label>
                    <AppSelect
                        value={value.branch_id}
                        onValueChange={(v) => onChange({ ...value, branch_id: v })}
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        {branches.map((b) => (
                            <AppSelectItem key={b.id} value={String(b.id)}>
                                {b.name ?? `#${b.id}`}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Position
                    </Label>
                    <AppSelect
                        value={value.position_id}
                        onValueChange={(v) => onChange({ ...value, position_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Manager
                    </Label>
                    <AppSelect
                        value={value.manager_id}
                        onValueChange={(v) => onChange({ ...value, manager_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Rank
                    </Label>
                    <AppSelect
                        value={value.rank_id}
                        onValueChange={(v) => onChange({ ...value, rank_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Gender
                    </Label>
                    <AppSelect
                        value={value.gender_id}
                        onValueChange={(v) => onChange({ ...value, gender_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Nationality
                    </Label>
                    <AppSelect
                        value={value.nationality_id}
                        onValueChange={(v) => onChange({ ...value, nationality_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Visa type
                    </Label>
                    <AppSelect
                        value={value.visa_type_id}
                        onValueChange={(v) => onChange({ ...value, visa_type_id: v })}
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
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Company visa type
                    </Label>
                    <AppSelect
                        value={value.company_visa_type_id}
                        onValueChange={(v) => onChange({ ...value, company_visa_type_id: v })}
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

                <div className="space-y-2 sm:col-span-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Status
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
                        <AppSelectItem value="terminated">Terminated</AppSelectItem>
                    </AppSelect>
                </div>
            </div>
        </FiltersSheet>
    );
}
