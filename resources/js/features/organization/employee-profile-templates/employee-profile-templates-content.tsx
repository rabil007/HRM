import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useViewPreference } from '@/hooks/use-view-preference';
import { TemplateCard } from './components/template-card';
import { TemplateDeleteDialog } from './components/template-delete-dialog';
import type { EmployeeProfileTemplate } from './types';

function editHref(template: EmployeeProfileTemplate): string {
    return `/organization/templates/employee-profile/${template.id}/edit`;
}

export function EmployeeProfileTemplatesContent({
    templates,
}: {
    templates: EmployeeProfileTemplate[];
}) {
    const [searchInput, setSearchInput] = useState('');
    const [view, setView] = useViewPreference('employee-profile-templates:view', 'grid');
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentTemplate, setCurrentTemplate] = useState<EmployeeProfileTemplate | null>(null);

    const rows = useMemo(() => {
        const query = searchInput.trim().toLowerCase();

        if (!query) {
            return templates;
        }

        return templates.filter((template) => {
            return (
                template.name.toLowerCase().includes(query) ||
                (template.description ?? '').toLowerCase().includes(query)
            );
        });
    }, [searchInput, templates]);

    const handleDelete = (template: EmployeeProfileTemplate) => {
        setCurrentTemplate(template);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentTemplate) {
            return;
        }

        router.delete(`/organization/templates/employee-profile/${currentTemplate.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentTemplate(null);
            },
        });
    };

    return (
        <Main>
            <PageHeader
                title="Employee profile templates"
                description="Control which profile tabs and fields appear when creating employees."
                right={
                    <Button
                        asChild
                        className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                    >
                        <Link href="/organization/templates/employee-profile/create">
                            <Plus className="mr-2 h-4 w-4" />
                            Add template
                        </Link>
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search templates by name or description..."
                value={searchInput}
                onChange={setSearchInput}
                right={<ViewToggle value={view} onChange={setView} />}
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {rows.map((template) => (
                        <TemplateCard
                            key={template.id}
                            template={template}
                            onDelete={handleDelete}
                        />
                    ))}
                </div>
            ) : (
                <OrganizationDataTable minWidth="min-w-[900px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="pl-5">Name</DataTableHead>
                            <DataTableHead>Description</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((template) => (
                            <TableRow
                                key={template.id}
                                className={dataTableBodyRowClass()}
                                onClick={() => router.visit(editHref(template))}
                            >
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    {template.name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {template.description?.trim() || '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant={template.is_active ? 'default' : 'secondary'}
                                        className="text-[10px] font-bold tracking-wider uppercase"
                                    >
                                        {template.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </TableCell>
                                <TableCell className={dataTableActionsCellClass()}>
                                    <ListTableCrudActions
                                        showView={false}
                                        onEdit={(event) => {
                                            event.stopPropagation();
                                            router.visit(editHref(template));
                                        }}
                                        onDelete={(event) => {
                                            event.stopPropagation();
                                            handleDelete(template);
                                        }}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {rows.length === 0 ? (
                <EmptyState
                    title={
                        templates.length === 0
                            ? 'No templates found.'
                            : 'No templates match your search.'
                    }
                />
            ) : null}

            <TemplateDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                template={currentTemplate}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
