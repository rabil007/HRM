import { Head, Link, router } from '@inertiajs/react';
import { Folder } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
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
                <div
                    className="grid gap-x-2 gap-y-6 sm:gap-x-4"
                    style={{
                        gridTemplateColumns:
                            'repeat(auto-fill, minmax(6.5rem, 1fr))',
                    }}
                >
                    {employees.map((employee) => (
                        <EmployeeFolderItem key={employee.employee_id} employee={employee} />
                    ))}
                </div>
            )}
        </Main>
    );
}

function EmployeeFolderItem({ employee }: { employee: EmployeeFolder }) {
    const fileLabel =
        employee.document_count === 1
            ? '1 file'
            : `${employee.document_count} files`;

    return (
        <Link
            href={`/organization/documents/employees/${employee.employee_id}`}
            title={`${employee.employee_name} (${employee.employee_no}) — ${fileLabel}`}
            className={cn(
                'group flex max-w-34 flex-col items-center gap-1.5 rounded-lg px-2 py-2.5 text-center',
                'transition-colors hover:bg-muted/40',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            )}
        >
            <Folder
                className="h-14 w-14 shrink-0 text-amber-400/90 drop-shadow-sm transition-transform duration-150 group-hover:scale-105 group-hover:text-amber-300"
                strokeWidth={1.25}
                fill="currentColor"
                fillOpacity={0.22}
                aria-hidden
            />
            <span className="line-clamp-2 w-full text-xs leading-snug font-medium text-foreground">
                {employee.employee_name}
            </span>
            <span className="w-full truncate font-mono text-[10px] text-muted-foreground/70">
                {employee.employee_no}
            </span>
        </Link>
    );
}
