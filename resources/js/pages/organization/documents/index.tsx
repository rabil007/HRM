import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import type { EmployeeFolder } from '@/features/organization/documents/types';
import { useDebouncedInertiaSearch } from '@/features/organization/documents/use-debounced-inertia-search';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

type Props = {
    employees: EmployeeFolder[];
    search: string;
};

export default function DocumentsIndex({ employees, search: initialSearch }: Props) {
    const { searchInput, isSearching, onSearchChange } = useDebouncedInertiaSearch({
        url: documents.url(),
        initialSearch,
        only: ['employees', 'search'],
    });

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
