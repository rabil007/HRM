import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Template = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
};

export default function EmployeeProfileTemplatesIndex({
    templates,
}: {
    templates: Template[];
}) {
    const [query, setQuery] = useState('');
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Template | null>(null);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return templates;
        }

        return templates.filter((template) => {
            return (
                template.name.toLowerCase().includes(q) ||
                (template.description ?? '').toLowerCase().includes(q)
            );
        });
    }, [query, templates]);

    return (
        <>
            <Head title="Employee profile templates" />
            <Main>
                <PageHeader
                    kicker="Organization"
                    title="Employee profile templates"
                    description="Control which profile tabs and fields appear when creating employees."
                    right={
                        <Button asChild>
                            <Link href="/organization/templates/employee-profile/create">
                                <Plus className="h-4 w-4" />
                                Add template
                            </Link>
                        </Button>
                    }
                />

                <SearchBar
                    value={query}
                    onChange={setQuery}
                    placeholder="Search templates..."
                />

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30">
                        <div className="col-span-4">Name</div>
                        <div className="col-span-4">Description</div>
                        <div className="col-span-2">Status</div>
                        <div className="col-span-2 text-right">Actions</div>
                    </div>

                    {rows.map((template) => (
                        <div
                            key={template.id}
                            className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 items-center"
                        >
                            <div className="col-span-4 text-sm font-medium">{template.name}</div>
                            <div className="col-span-4 text-sm text-muted-foreground truncate">
                                {template.description ?? '—'}
                            </div>
                            <div className="col-span-2">
                                <Badge variant={template.is_active ? 'default' : 'secondary'}>
                                    {template.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>
                            <div className="col-span-2 flex justify-end gap-2">
                                <Button variant="outline" size="icon" asChild>
                                    <Link
                                        href={`/organization/templates/employee-profile/${template.id}/edit`}
                                    >
                                        <Pencil className="h-4 w-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => {
                                        setCurrent(template);
                                        setDeleteOpen(true);
                                    }}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    ))}

                    {rows.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-muted-foreground">
                            No templates found.
                        </div>
                    ) : null}
                </div>

                <ConfirmDeleteDialog
                    open={deleteOpen}
                    onOpenChange={setDeleteOpen}
                    title="Delete template"
                    description={`Delete "${current?.name ?? 'this template'}"? Employees already linked keep their data; only the template configuration is removed.`}
                    onConfirm={() => {
                        if (!current) {
                            return;
                        }

                        router.delete(
                            `/organization/templates/employee-profile/${current.id}`,
                            {
                                preserveScroll: true,
                                onFinish: () => {
                                    setDeleteOpen(false);
                                    setCurrent(null);
                                },
                            },
                        );
                    }}
                />
            </Main>
        </>
    );
}
