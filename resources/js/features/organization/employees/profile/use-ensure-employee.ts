import { useCallback, useEffect, useRef } from 'react';
import {
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
    const inFlightRef = useRef<Promise<number> | null>(null);
    const cachedEnsuredRef = useRef<EnsuredEmployee | null>(null);

    useEffect(() => {
        if (employeeId !== null && employeeId > 0) {
            return;
        }

        cachedEnsuredRef.current = null;
        inFlightRef.current = null;
    }, [employeeId]);

    return useCallback(async (): Promise<number> => {
        if (employeeId !== null && employeeId > 0) {
            return employeeId;
        }

        if (cachedEnsuredRef.current !== null) {
            onEnsured(cachedEnsuredRef.current);

            return cachedEnsuredRef.current.id;
        }

        if (inFlightRef.current !== null) {
            return inFlightRef.current;
        }

        const promise = (async (): Promise<number> => {
            try {
                const ensured = await postEnsureEmployee({
                    name: getDraftName(),
                    employee_profile_template_id: selectedProfileTemplateId,
                });

                cachedEnsuredRef.current = ensured;
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
            } finally {
                inFlightRef.current = null;
            }
        })();

        inFlightRef.current = promise;

        return promise;
    }, [employeeId, getDraftName, onEnsured, selectedProfileTemplateId]);
}
