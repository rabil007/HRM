import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    Branch,
    Department,
    DepartmentFormData,
    DepartmentParentOption,
    Manager,
} from '../types';

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
    const canAssignManager = !form.data.parent_id;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {department ? 'Edit Department' : 'New Department'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {department
                            ? 'Update department details.'
                            : 'Add a new department.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Status
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'status',
                                            v as 'active' | 'inactive',
                                        )
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="active">
                                        Active
                                    </AppSelectItem>
                                    <AppSelectItem value="inactive">
                                        Inactive
                                    </AppSelectItem>
                                </AppSelect>
                                {form.errors.status ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.status}
                                    </div>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label
                                    htmlFor="code"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Code
                                </Label>
                                <Input
                                    id="code"
                                    placeholder="HR"
                                    className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                />
                                {form.errors.code ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.code}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Department Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Human Resources"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="branch_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Branch (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.branch_id ?? '')}
                                onValueChange={(v) =>
                                    form.setData(
                                        'branch_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="card"
                                placeholder="All branches"
                            >
                                <AppSelectItem value="">
                                    All branches
                                </AppSelectItem>
                                {availableBranches.map((branch) => (
                                    <AppSelectItem
                                        key={branch.id}
                                        value={String(branch.id)}
                                    >
                                        {branch.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.branch_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.branch_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="parent_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Parent department (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.parent_id ?? '')}
                                onValueChange={(v) => {
                                    const parentId = v ? Number(v) : '';

                                    form.setData((data) => ({
                                        ...data,
                                        parent_id: parentId,
                                        manager_id: parentId
                                            ? ''
                                            : data.manager_id,
                                    }));
                                }}
                                variant="card"
                                placeholder="No parent"
                            >
                                <AppSelectItem value="">
                                    No parent
                                </AppSelectItem>
                                {availableParents.map((parent) => (
                                    <AppSelectItem
                                        key={parent.id}
                                        value={String(parent.id)}
                                    >
                                        {parent.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.parent_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.parent_id}
                                </div>
                            ) : null}
                        </div>

                        {canAssignManager ? (
                            <div className="space-y-2">
                                <Label
                                    htmlFor="manager_id"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Manager (optional)
                                </Label>
                                <AppSelect
                                    value={String(form.data.manager_id ?? '')}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'manager_id',
                                            v ? Number(v) : '',
                                        )
                                    }
                                    variant="card"
                                    placeholder="No manager"
                                >
                                    <AppSelectItem value="">
                                        No manager
                                    </AppSelectItem>
                                    {managers.map((manager) => (
                                        <AppSelectItem
                                            key={manager.id}
                                            value={String(manager.id)}
                                        >
                                            {manager.employee_no
                                                ? `${manager.employee_no} — ${manager.name}`
                                                : manager.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {form.errors.manager_id ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.manager_id}
                                    </div>
                                ) : null}
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                Managers can only be assigned to parent
                                departments. Remove the parent department to
                                assign a manager.
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {department ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
