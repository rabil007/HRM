import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { EmployeeLinkOption, HikvisionPerson } from './types';

function formatEmployeeLabel(option: EmployeeLinkOption): string {
    const parts = [option.name?.trim(), option.employee_no ? `#${option.employee_no}` : null].filter(
        Boolean,
    );

    return parts.join(' · ') || `Employee #${option.id}`;
}

function buildOptions(
    employeesForLinking: EmployeeLinkOption[],
    person: HikvisionPerson,
): EmployeeLinkOption[] {
    const options = [...employeesForLinking];

    if (person.linked_employee && !options.some((option) => option.id === person.linked_employee?.id)) {
        options.unshift(person.linked_employee);
    }

    return options;
}

export function LinkPersonEmployeeDialog({
    open,
    onOpenChange,
    person,
    employeesForLinking,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    person: HikvisionPerson | null;
    employeesForLinking: EmployeeLinkOption[];
}): ReactElement | null {
    const form = useForm({
        employee_id: person?.linked_employee?.id ? String(person.linked_employee.id) : '',
    });

    useEffect(() => {
        if (!open || person === null) {
            return;
        }

        form.clearErrors();
        form.setData(
            'employee_id',
            person.linked_employee?.id ? String(person.linked_employee.id) : '',
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset form when dialog opens for a person
    }, [open, person]);

    if (person === null) {
        return null;
    }

    const options = buildOptions(employeesForLinking, person);

    const handleOpenChange = (nextOpen: boolean): void => {
        if (nextOpen) {
            form.clearErrors();
            form.setData(
                'employee_id',
                person.linked_employee?.id ? String(person.linked_employee.id) : '',
            );
        }

        onOpenChange(nextOpen);
    };

    const submit = (): void => {
        form.put(`/hikvision/persons/${person.id}/employee`, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
            },
        });
    };

    const unlink = (): void => {
        form.transform(() => ({ employee_id: null }));
        form.put(`/hikvision/persons/${person.id}/employee`, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
            },
            onFinish: () => {
                form.transform((data) => data);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Link employee</DialogTitle>
                    <DialogDescription>
                        Connect {person.full_name ?? 'this person'} to an employee record in your
                        organization.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="space-y-2">
                        <Label htmlFor="person-employee-link">Employee</Label>
                        <AppSelect
                            value={form.data.employee_id}
                            onValueChange={(value) => form.setData('employee_id', value)}
                            variant="card"
                            placeholder="Select employee"
                        >
                            <AppSelectItem value="">No linked employee</AppSelectItem>
                            {options.map((option) => (
                                <AppSelectItem key={option.id} value={String(option.id)}>
                                    {formatEmployeeLabel(option)}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.employee_id ? (
                            <p className="text-xs text-destructive">{form.errors.employee_id}</p>
                        ) : null}
                    </div>
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    {person.linked_employee ? (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive hover:text-destructive"
                            disabled={form.processing}
                            onClick={unlink}
                        >
                            Unlink
                        </Button>
                    ) : (
                        <span />
                    )}

                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="button" onClick={submit} disabled={form.processing}>
                            {form.processing ? <Spinner className="mr-2" /> : null}
                            Save link
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
