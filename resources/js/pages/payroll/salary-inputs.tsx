import { Head } from '@inertiajs/react';
import { SalaryInputsContent } from '@/features/payroll/salary-inputs/salary-inputs-content';
import type { SalaryInputTypeRecord } from '@/features/payroll/salary-inputs/types';
import type { PaginationMeta } from '@/types/pagination';

export default function SalaryInputs({
    salary_input_types,
    pagination,
    search,
}: {
    salary_input_types: SalaryInputTypeRecord[];
    pagination: PaginationMeta;
    search: string;
}) {
    return (
        <>
            <Head title="Salary inputs" />
            <SalaryInputsContent
                salary_input_types={salary_input_types}
                pagination={pagination}
                search={search}
            />
        </>
    );
}
