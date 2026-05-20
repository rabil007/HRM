import { Head, router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import type { EmployeeFolder } from '@/features/organization/documents/employee-folder-item';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import { cn } from '@/lib/utils';

type Props = {
    employees: EmployeeFolder[];
    search: string;
};

export default function DocumentsIndex({ employees, search: initialSearch }: Props) {
    const [draftSearch, setDraftSearch] = useState<string | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = draftSearch ?? initialSearch;

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const onSearchChange = useCallback((value: string) => {
        setDraftSearch(value);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            setIsSearching(true);
            router.get(
                '/organization/documents',
                { search: value || undefined },
                {
                    preserveState: true,
                    replace: true,
                    only: ['employees', 'search'],
                    onFinish: () => {
                        setIsSearching(false);
                        setDraftSearch(null);
                    },
                },
            );
        }, 400);
    }, []);

    const folderLabel =
        employees.length === 1 ? '1 employee folder' : `${employees.length} employee folders`;

    return (
        <Main>
            <Head title="Documents" />

            <DocumentsBreadcrumbs items={[{ title: 'Documents' }]} />

            <div className="mb-6 space-y-4">
                <SearchBar
                    placeholder="Search by employee name or number…"
                    value={searchInput}
                    onChange={onSearchChange}
                />

                {employees.length > 0 ? (
                    <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                        <span className="font-medium">{folderLabel}</span>
                        {isSearching ? (
                            <span className="inline-flex items-center gap-1.5 text-xs">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                Updating…
                            </span>
                        ) : null}
                    </div>
                ) : null}
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
                <section
                    className={cn(
                        'min-h-[320px] rounded-xl border border-white/5 bg-white/[0.02] p-6 sm:p-8',
                        'transition-opacity duration-200',
                        isSearching && 'pointer-events-none opacity-60',
                    )}
                    aria-busy={isSearching}
                >
                    <div
                        className="grid gap-x-3 gap-y-8 sm:gap-x-5"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fill, minmax(8.75rem, 1fr))',
                        }}
                    >
                        {employees.map((employee) => (
                            <EmployeeFolderItem key={employee.employee_id} employee={employee} />
                        ))}
                    </div>
                </section>
            )}
        </Main>
    );
}
