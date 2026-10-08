import { useCallback, useEffect, useRef } from 'react';
import {
    createDedupedEnsureEmployee,
    createEnsureIdempotencyKey,
    EnsureEmployeeRequestError,
    postEnsureEmployee,
} from '@/features/organization/employees/profile/ensure-employee-client';
import type { EnsuredEmployee } from '@/features/organization/employees/profile/ensure-employee-client';
import { toast } from '@/lib/toast';

export type { EnsuredEmployee };

type UseEnsureEmployeeOptions = {
    employeeId: number | null;
    getDraftName: () => string;
    selectedProfileTemplateId: number | null;
    onEnsured: (employee: EnsuredEmployee) => void;
};

export function useEnsureEmployee({
    employeeId,
    getDraftName,
    selectedProfileTemplateId,
    onEnsured,
}: UseEnsureEmployeeOptions): () => Promise<number> {
    const idempotencyKeyRef = useRef<string>(createEnsureIdempotencyKey());
    const deduperRef = useRef(
        createDedupedEnsureEmployee((body) => postEnsureEmployee(body)),
    );

    useEffect(() => {
        if (employeeId !== null && employeeId > 0) {
            return;
        }

        deduperRef.current.reset();
        idempotencyKeyRef.current = createEnsureIdempotencyKey();
    }, [employeeId]);

    return useCallback(async (): Promise<number> => {
        try {
            const ensured = await deduperRef.current.ensure(employeeId, {
                name: getDraftName(),
                employee_profile_template_id: selectedProfileTemplateId,
                idempotency_key: idempotencyKeyRef.current,
            });

            onEnsured(ensured);

            return ensured.id;
        } catch (error) {
            if (error instanceof EnsureEmployeeRequestError) {
                if (error.reason === 'name_required') {
                    toast.error('Employee name is required before saving.');
                } else {
                    toast.error('Could not create employee record.');
                }
            }

            throw error;
        }
    }, [employeeId, getDraftName, onEnsured, selectedProfileTemplateId]);
}
