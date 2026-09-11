import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

export type CreateEmployeeUserFormData = {
    role_id: string;
    email: string;
    name: string;
};

function defaultEmailFromEmployee(employee: EmployeeDetails): string {
    return employee.work_email?.trim() || employee.personal_email?.trim() || '';
}

function buildInitialForm(
    employee: EmployeeDetails,
): CreateEmployeeUserFormData {
    return {
        role_id: '',
        email: defaultEmailFromEmployee(employee),
        name: employee.name?.trim() ?? '',
    };
}

export function CreateEmployeeUserDialog({
    open,
    onOpenChange,
    employee,
    roles,
    onSuccess,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: EmployeeDetails;
    roles: { id: number; name: string }[];
    onSuccess?: () => void;
}): ReactElement {
    const form = useForm<CreateEmployeeUserFormData>(
        buildInitialForm(employee),
    );

    const handleOpenChange = (nextOpen: boolean): void => {
        if (nextOpen) {
            form.clearErrors();
            form.setData(buildInitialForm(employee));
        }

        onOpenChange(nextOpen);
    };

    const submit = (): void => {
        form.post(`/organization/employees/${employee.id}/user`, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
                onSuccess?.();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Invite user account</DialogTitle>
                    <DialogDescription>
                        Send {employee.name} an invitation email to create their
                        login and set their own password.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="space-y-2">
                        <Label htmlFor="create-user-role">Role</Label>
                        <AppSelect
                            value={form.data.role_id}
                            onValueChange={(value) =>
                                form.setData('role_id', value)
                            }
                            variant="card"
                            placeholder="Select role"
                        >
                            {roles.map((role) => (
                                <AppSelectItem
                                    key={role.id}
                                    value={String(role.id)}
                                >
                                    {role.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.role_id ? (
                            <p className="text-xs text-destructive">
                                {form.errors.role_id}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="create-user-email">Email</Label>
                        <Input
                            id="create-user-email"
                            type="email"
                            autoComplete="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        {form.errors.email ? (
                            <p className="text-xs text-destructive">
                                {form.errors.email}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="create-user-name">Name</Label>
                        <Input
                            id="create-user-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        {form.errors.name ? (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={form.processing}
                    >
                        Send invitation
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
