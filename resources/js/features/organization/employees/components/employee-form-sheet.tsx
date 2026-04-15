import type { InertiaFormProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { Employee, EmployeeFormData, BranchOption, DepartmentOption, PositionOption, ManagerOption, UserOption } from '../types';

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

