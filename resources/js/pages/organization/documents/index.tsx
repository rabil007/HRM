import { Head, Link, router } from '@inertiajs/react';
import { Folder } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type EmployeeFolder = {
    employee_id: number;
    employee_name: string;
    employee_no: string;
    document_count: number;
};

type Props = {
    employees: EmployeeFolder[];
    search: string;
};

export default function DocumentsIndex({ employees, search: initialSearch }: Props) {
    const [searchInput, setSearchInput] = useState(initialSearch);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        setSearchInput(initialSearch);
    }, [initialSearch]);

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const onSearchChange = useCallback((value: string) => {
        setSearchInput(value);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            router.get(
                '/organization/documents',
                { search: value || undefined },
                { preserveState: true, replace: true, only: ['employees', 'search'] },
            );
        }, 400);
    }, []);

    return (
        <Main>
            <Head title="Documents" />

            <PageHeader
                title="Documents"
                description="Browse employee document folders. Only employees with uploaded files are shown."
            />

            <div className="mb-6">
                <SearchBar
                    placeholder="Search by employee name or number…"
                    value={searchInput}
                    onChange={onSearchChange}
                />
            </div>

            {employees.length === 0 ? (
                <EmptyState
                    title="No employee folders found."
                    description={
                        initialSearch
                            ? 'Try a different search or upload documents from an employee profile.'
                            : 'Upload documents from an employee profile to see folders here.'
                    }
                />
            ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {employees.map((employee) => (
                        <EmployeeFolderCard key={employee.employee_id} employee={employee} />
                    ))}
                </div>
            )}
        </Main>
    );
}

function EmployeeFolderCard({ employee }: { employee: EmployeeFolder }) {
    const fileLabel =
        employee.document_count === 1
            ? '1 file'
            : `${employee.document_count} files`;

    return (
        <Link
            href={`/organization/documents/employees/${employee.employee_id}`}
            className="group block h-full"
        >
            <Card
                className={cn(
                    'glass-card h-full transition-all duration-200',
                    'hover:border-primary/30 hover:shadow-md',
                )}
            >
                <CardContent className="flex flex-col items-center gap-3 p-6 text-center">
                    <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 text-primary transition-colors group-hover:bg-primary/15">
                        <Folder className="h-9 w-9" strokeWidth={1.5} aria-hidden />
                    </div>
                    <div className="min-w-0 w-full space-y-1">
                        <p className="truncate text-base font-semibold text-foreground">
                            {employee.employee_name}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground tabular-nums">
                            {employee.employee_no}
                        </p>
                        <p className="text-xs text-muted-foreground/80">{fileLabel}</p>
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}
