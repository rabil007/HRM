import type { InertiaFormProps } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { Branch, Department, DepartmentFormData, DepartmentParentOption, Manager } from '../types';

export function DepartmentFormSheet({
    open,
    onOpenChange,
    department,
    branches,
    parents,
    managers,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    department: Department | null;
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
    form: InertiaFormProps<DepartmentFormData>;
    onSubmit: () => void;
}) {
    const availableBranches = branches ?? [];
    const availableParents = parents ?? [];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-md p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {department ? 'Edit Department' : 'New Department'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {department ? 'Update department details.' : 'Add a new department.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Status
                                </Label>
                                <select
                                    id="status"
                                    className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')}
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                {form.errors.status ? <div className="text-xs font-medium text-destructive">{form.errors.status}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="code" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Code
                                </Label>
                                <Input
                                    id="code"
                                    placeholder="HR"
                                    className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                />
                                {form.errors.code ? <div className="text-xs font-medium text-destructive">{form.errors.code}</div> : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Department Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Human Resources"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="branch_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Branch (optional)
                            </Label>
                            <select
                                id="branch_id"
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">All branches</option>
                                {availableBranches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.branch_id ? <div className="text-xs font-medium text-destructive">{form.errors.branch_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="parent_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Parent department (optional)
                            </Label>
                            <select
                                id="parent_id"
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.parent_id}
                                onChange={(e) => form.setData('parent_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">No parent</option>
                                {availableParents.map((parent) => (
                                    <option key={parent.id} value={parent.id}>
                                        {parent.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.parent_id ? <div className="text-xs font-medium text-destructive">{form.errors.parent_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="manager_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Manager (optional)
                            </Label>
                            <select
                                id="manager_id"
                                className="w-full rounded-xl border border-border bg-card h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                value={form.data.manager_id}
                                onChange={(e) => form.setData('manager_id', e.target.value ? Number(e.target.value) : '')}
                            >
                                <option value="">No manager</option>
                                {managers.map((manager) => (
                                    <option key={manager.id} value={manager.id}>
                                        {manager.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.manager_id ? <div className="text-xs font-medium text-destructive">{form.errors.manager_id}</div> : null}
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-border/60 bg-background/40 flex gap-3">
                    <Button
                        type="button"
                        variant="ghost"
                        className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button className="rounded-xl h-11 px-6 flex-1 font-semibold" type="button" onClick={onSubmit} disabled={form.processing}>
                        {department ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

