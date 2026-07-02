import { useCallback } from 'react';
import { toast } from '@/lib/toast';

export type EnsuredEmployee = {
    id: number;
    name: string;
    employee_no: string;
};

type UseEnsureEmployeeOptions = {
    employeeId: number | null;
    getDraftName: () => string;
    selectedProfileTemplateId: number | null;
    onEnsured: (employee: EnsuredEmployee) => void;
};

function csrfToken(): string {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return token ?? '';
}

export function useEnsureEmployee({
    employeeId,
    getDraftName,
    selectedProfileTemplateId,
    onEnsured,
}: UseEnsureEmployeeOptions): () => Promise<number> {
    return useCallback(async (): Promise<number> => {
        if (employeeId !== null && employeeId > 0) {
            return employeeId;
        }

        const name = getDraftName().trim();

        if (name === '') {
            toast.error('Employee name is required before saving.');

            throw new Error('name_required');
        }

        const response = await fetch('/organization/employees/ensure', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                name,
                employee_profile_template_id: selectedProfileTemplateId,
            }),
        });

        if (!response.ok) {
            toast.error('Could not create employee record.');

            throw new Error('ensure_failed');
        }

        const payload = (await response.json()) as {
            employee?: EnsuredEmployee;
        };
        const ensured = payload.employee;

        if (!ensured?.id) {
            toast.error('Could not create employee record.');

            throw new Error('ensure_invalid');
        }

        onEnsured(ensured);

        return ensured.id;
    }, [employeeId, getDraftName, onEnsured, selectedProfileTemplateId]);
}
