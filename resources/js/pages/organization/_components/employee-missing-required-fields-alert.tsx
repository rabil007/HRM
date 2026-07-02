import { AlertCircle } from 'lucide-react';
import type { ReactElement } from 'react';
import { employeeRequiredFieldLabel } from '@/pages/organization/_lib/employee-required-field-labels';

export function EmployeeMissingRequiredFieldsAlert({
    missingFields,
    onFocusField,
}: {
    missingFields: string[];
    onFocusField: (field: string) => void;
}): ReactElement | null {
    if (missingFields.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            className="rounded-2xl border border-rose-500/35 bg-rose-500/10 p-4 shadow-lg shadow-black/10 backdrop-blur-xl"
        >
            <div className="flex gap-3">
                <AlertCircle
                    className="mt-0.5 h-5 w-5 shrink-0 text-rose-400"
                    aria-hidden
                />
                <div className="min-w-0 flex-1 space-y-2">
                    <p className="text-sm font-semibold text-rose-100">
                        Required fields are missing
                    </p>
                    <p className="text-xs text-rose-200/80">
                        Fill in the highlighted fields below, or select a field
                        to jump to it.
                    </p>
                    <ul className="flex flex-wrap gap-2">
                        {missingFields.map((field) => (
                            <li key={field}>
                                <button
                                    type="button"
                                    className="rounded-full border border-rose-500/40 bg-rose-500/15 px-2.5 py-1 text-xs font-medium text-rose-100 transition-colors hover:bg-rose-500/25"
                                    onClick={() => onFocusField(field)}
                                >
                                    {employeeRequiredFieldLabel(field)}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}
