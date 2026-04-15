import type { InertiaFormProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentOption,
    Employee,
    EmployeeFormData,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
    VisaTypeOption,
} from '../types';

export function EmployeeFormSheet({
    open,
    onOpenChange,
    employee,
    form,
    onSubmit,
    branches,
    departments,
    positions,
    managers,
    users,
    countries,
    visaTypes,
    religions,
    genders,
    banks,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: Employee | null;
    form: InertiaFormProps<EmployeeFormData>;
    onSubmit: () => void;
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    visaTypes: VisaTypeOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
}) {
    const title = employee ? 'Edit employee' : 'Add employee';
    const description = employee ? 'Update employee profile and assignment.' : 'Create a new employee record.';

    const filteredPositions = form.data.department_id
        ? positions.filter((p) => String(p.department_id ?? '') === String(form.data.department_id))
        : positions;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="glass-card rounded-none sm:max-w-xl p-0 flex flex-col">
                <SheetHeader className="p-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">{title}</SheetTitle>
                    <SheetDescription className="text-muted-foreground/80">{description}</SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSubmit();
                    }}
                    className="flex-1 overflow-auto p-6 space-y-6"
                >
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="employee_no">Employee No</Label>
                            <Input
                                id="employee_no"
                                value={form.data.employee_no}
                                onChange={(e) => form.setData('employee_no', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.employee_no ? (
                                <div className="text-xs text-destructive">{form.errors.employee_no}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <select
                                id="status"
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value as EmployeeFormData['status'])}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="on_leave">On leave</option>
                                <option value="terminated">Terminated</option>
                            </select>
                            {form.errors.status ? <div className="text-xs text-destructive">{form.errors.status}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="first_name">First name</Label>
                            <Input
                                id="first_name"
                                value={form.data.first_name}
                                onChange={(e) => form.setData('first_name', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.first_name ? (
                                <div className="text-xs text-destructive">{form.errors.first_name}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="last_name">Last name</Label>
                            <Input
                                id="last_name"
                                value={form.data.last_name}
                                onChange={(e) => form.setData('last_name', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.last_name ? (
                                <div className="text-xs text-destructive">{form.errors.last_name}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="work_email">Work email</Label>
                            <Input
                                id="work_email"
                                value={form.data.work_email}
                                onChange={(e) => form.setData('work_email', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.work_email ? (
                                <div className="text-xs text-destructive">{form.errors.work_email}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="phone">Phone</Label>
                            <Input
                                id="phone"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.phone ? <div className="text-xs text-destructive">{form.errors.phone}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="personal_email">Personal email</Label>
                            <Input
                                id="personal_email"
                                value={form.data.personal_email}
                                onChange={(e) => form.setData('personal_email', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.personal_email ? (
                                <div className="text-xs text-destructive">{form.errors.personal_email}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="phone_home_country">Phone (home country)</Label>
                            <Input
                                id="phone_home_country"
                                value={form.data.phone_home_country}
                                onChange={(e) => form.setData('phone_home_country', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.phone_home_country ? (
                                <div className="text-xs text-destructive">{form.errors.phone_home_country}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="nearest_airport">Nearest airport</Label>
                            <Input
                                id="nearest_airport"
                                value={form.data.nearest_airport}
                                onChange={(e) => form.setData('nearest_airport', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.nearest_airport ? (
                                <div className="text-xs text-destructive">{form.errors.nearest_airport}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="cv_source">Source of CV</Label>
                            <Input
                                id="cv_source"
                                value={form.data.cv_source}
                                onChange={(e) => form.setData('cv_source', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.cv_source ? (
                                <div className="text-xs text-destructive">{form.errors.cv_source}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="hire_date">Hire date</Label>
                            <Input
                                id="hire_date"
                                type="date"
                                value={form.data.hire_date}
                                onChange={(e) => form.setData('hire_date', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.hire_date ? (
                                <div className="text-xs text-destructive">{form.errors.hire_date}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="contract_type">Contract type</Label>
                            <select
                                id="contract_type"
                                value={form.data.contract_type}
                                onChange={(e) =>
                                    form.setData('contract_type', e.target.value as EmployeeFormData['contract_type'])
                                }
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="unlimited">Unlimited</option>
                                <option value="limited">Limited</option>
                                <option value="part_time">Part time</option>
                                <option value="contract">Contract</option>
                            </select>
                            {form.errors.contract_type ? (
                                <div className="text-xs text-destructive">{form.errors.contract_type}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="date_of_birth">Birthday</Label>
                            <Input
                                id="date_of_birth"
                                type="date"
                                value={form.data.date_of_birth}
                                onChange={(e) => form.setData('date_of_birth', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.date_of_birth ? (
                                <div className="text-xs text-destructive">{form.errors.date_of_birth}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="place_of_birth">Place of birth</Label>
                            <Input
                                id="place_of_birth"
                                value={form.data.place_of_birth}
                                onChange={(e) => form.setData('place_of_birth', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.place_of_birth ? (
                                <div className="text-xs text-destructive">{form.errors.place_of_birth}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="gender">Gender</Label>
                            <select
                                id="gender"
                                value={form.data.gender_id === '' ? '' : String(form.data.gender_id)}
                                onChange={(e) => form.setData('gender_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {genders.map((g) => (
                                    <option key={g.id} value={String(g.id)}>
                                        {g.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.gender_id ? <div className="text-xs text-destructive">{form.errors.gender_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="religion">Religion</Label>
                            <select
                                id="religion"
                                value={form.data.religion_id === '' ? '' : String(form.data.religion_id)}
                                onChange={(e) => form.setData('religion_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {religions.map((r) => (
                                    <option key={r.id} value={String(r.id)}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.religion_id ? <div className="text-xs text-destructive">{form.errors.religion_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="nationality">Nationality</Label>
                            <select
                                id="nationality"
                                value={form.data.nationality}
                                onChange={(e) => form.setData('nationality', e.target.value)}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {countries.map((c) => (
                                    <option key={c.id} value={c.name}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.nationality ? (
                                <div className="text-xs text-destructive">{form.errors.nationality}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="marital_status">Marital status</Label>
                            <select
                                id="marital_status"
                                value={form.data.marital_status}
                                onChange={(e) => form.setData('marital_status', e.target.value as EmployeeFormData['marital_status'])}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                <option value="married">Yes</option>
                                <option value="single">No</option>
                            </select>
                            {form.errors.marital_status ? (
                                <div className="text-xs text-destructive">{form.errors.marital_status}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="spouse_name">Spouse name</Label>
                            <Input
                                id="spouse_name"
                                value={form.data.spouse_name}
                                onChange={(e) => form.setData('spouse_name', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.spouse_name ? (
                                <div className="text-xs text-destructive">{form.errors.spouse_name}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="spouse_birthdate">Spouse birthdate</Label>
                            <Input
                                id="spouse_birthdate"
                                type="date"
                                value={form.data.spouse_birthdate}
                                onChange={(e) => form.setData('spouse_birthdate', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.spouse_birthdate ? (
                                <div className="text-xs text-destructive">{form.errors.spouse_birthdate}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dependent_children_count">Dependent children</Label>
                            <Input
                                id="dependent_children_count"
                                type="number"
                                min={0}
                                value={form.data.dependent_children_count === '' ? '' : String(form.data.dependent_children_count)}
                                onChange={(e) =>
                                    form.setData('dependent_children_count', e.target.value ? Number(e.target.value) : '')
                                }
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.dependent_children_count ? (
                                <div className="text-xs text-destructive">{form.errors.dependent_children_count}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="emergency_contact">Emergency contact name</Label>
                            <Input
                                id="emergency_contact"
                                value={form.data.emergency_contact}
                                onChange={(e) => form.setData('emergency_contact', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.emergency_contact ? (
                                <div className="text-xs text-destructive">{form.errors.emergency_contact}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="emergency_phone">Emergency contact phone</Label>
                            <Input
                                id="emergency_phone"
                                value={form.data.emergency_phone}
                                onChange={(e) => form.setData('emergency_phone', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.emergency_phone ? (
                                <div className="text-xs text-destructive">{form.errors.emergency_phone}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="emergency_contact_home_country">Emergency contact name (home country)</Label>
                            <Input
                                id="emergency_contact_home_country"
                                value={form.data.emergency_contact_home_country}
                                onChange={(e) => form.setData('emergency_contact_home_country', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.emergency_contact_home_country ? (
                                <div className="text-xs text-destructive">{form.errors.emergency_contact_home_country}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="emergency_phone_home_country">Emergency contact phone (home country)</Label>
                            <Input
                                id="emergency_phone_home_country"
                                value={form.data.emergency_phone_home_country}
                                onChange={(e) => form.setData('emergency_phone_home_country', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.emergency_phone_home_country ? (
                                <div className="text-xs text-destructive">{form.errors.emergency_phone_home_country}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="visa_type">Visa type</Label>
                            <select
                                id="visa_type"
                                value={form.data.visa_type_id === '' ? '' : String(form.data.visa_type_id)}
                                onChange={(e) => form.setData('visa_type_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {visaTypes.map((v) => (
                                    <option key={v.id} value={String(v.id)}>
                                        {v.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.visa_type_id ? <div className="text-xs text-destructive">{form.errors.visa_type_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="bank_id">Bank</Label>
                            <select
                                id="bank_id"
                                value={form.data.bank_id === '' ? '' : String(form.data.bank_id)}
                                onChange={(e) => form.setData('bank_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {banks.map((b) => (
                                    <option key={b.id} value={String(b.id)}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.bank_id ? <div className="text-xs text-destructive">{form.errors.bank_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="labor_contract_id">Labor contract ID</Label>
                            <Input
                                id="labor_contract_id"
                                value={form.data.labor_contract_id}
                                onChange={(e) => form.setData('labor_contract_id', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.labor_contract_id ? (
                                <div className="text-xs text-destructive">{form.errors.labor_contract_id}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="passport_number">Passport No</Label>
                            <Input
                                id="passport_number"
                                value={form.data.passport_number}
                                onChange={(e) => form.setData('passport_number', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.passport_number ? (
                                <div className="text-xs text-destructive">{form.errors.passport_number}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="passport_issued_at">Passport issued</Label>
                            <Input
                                id="passport_issued_at"
                                type="date"
                                value={form.data.passport_issued_at}
                                onChange={(e) => form.setData('passport_issued_at', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.passport_issued_at ? (
                                <div className="text-xs text-destructive">{form.errors.passport_issued_at}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="passport_expiry">Passport expiry</Label>
                            <Input
                                id="passport_expiry"
                                type="date"
                                value={form.data.passport_expiry}
                                onChange={(e) => form.setData('passport_expiry', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.passport_expiry ? (
                                <div className="text-xs text-destructive">{form.errors.passport_expiry}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="emirates_id">Emirates ID</Label>
                            <Input
                                id="emirates_id"
                                value={form.data.emirates_id}
                                onChange={(e) => form.setData('emirates_id', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.emirates_id ? (
                                <div className="text-xs text-destructive">{form.errors.emirates_id}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="branch_id">Branch</Label>
                            <select
                                id="branch_id"
                                value={form.data.branch_id === '' ? '' : String(form.data.branch_id)}
                                onChange={(e) => form.setData('branch_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={String(b.id)}>
                                        {b.name ?? `#${b.id}`}
                                    </option>
                                ))}
                            </select>
                            {form.errors.branch_id ? <div className="text-xs text-destructive">{form.errors.branch_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="department_id">Department</Label>
                            <select
                                id="department_id"
                                value={form.data.department_id === '' ? '' : String(form.data.department_id)}
                                onChange={(e) => {
                                    const next = e.target.value ? Number(e.target.value) : '';
                                    form.setData((prev) => ({
                                        ...prev,
                                        department_id: next,
                                        position_id: '',
                                    }));
                                }}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={String(d.id)}>
                                        {d.name ?? `#${d.id}`}
                                    </option>
                                ))}
                            </select>
                            {form.errors.department_id ? (
                                <div className="text-xs text-destructive">{form.errors.department_id}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="position_id">Position</Label>
                            <select
                                id="position_id"
                                value={form.data.position_id === '' ? '' : String(form.data.position_id)}
                                onChange={(e) => form.setData('position_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {filteredPositions.map((p) => (
                                    <option key={p.id} value={String(p.id)}>
                                        {p.title ?? `#${p.id}`}
                                    </option>
                                ))}
                            </select>
                            {form.errors.position_id ? (
                                <div className="text-xs text-destructive">{form.errors.position_id}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="manager_id">Manager</Label>
                            <select
                                id="manager_id"
                                value={form.data.manager_id === '' ? '' : String(form.data.manager_id)}
                                onChange={(e) => form.setData('manager_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {managers.map((m) => (
                                    <option key={m.id} value={String(m.id)}>
                                        {m.employee_no} • {m.first_name} {m.last_name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.manager_id ? (
                                <div className="text-xs text-destructive">{form.errors.manager_id}</div>
                            ) : null}
                        </div>

                        <div className="col-span-2 space-y-2">
                            <Label htmlFor="user_id">Linked user (optional)</Label>
                            <select
                                id="user_id"
                                value={form.data.user_id === '' ? '' : String(form.data.user_id)}
                                onChange={(e) => form.setData('user_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {users.map((u) => (
                                    <option key={u.id} value={String(u.id)}>
                                        {u.name} ({u.email})
                                    </option>
                                ))}
                            </select>
                            {form.errors.user_id ? <div className="text-xs text-destructive">{form.errors.user_id}</div> : null}
                        </div>
                    </div>

                    <div className="pt-6 flex items-center justify-end gap-3 border-t border-border/60">
                        <Button
                            type="button"
                            variant="secondary"
                            className="glass-card rounded-xl h-11 px-5 hover:bg-accent"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" className="rounded-xl h-11 px-6" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}

