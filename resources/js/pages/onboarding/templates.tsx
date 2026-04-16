import { Head, router, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

type Template = {
    id: number;
    name: string;
    description: string | null;
    tasks: unknown;
    is_default: boolean;
    created_at: string;
};

export default function OnboardingTemplates({ templates }: { templates: Template[] }) {
    const [query, setQuery] = useState('');
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Template | null>(null);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return templates;
        }

        return templates.filter((t) => {
            return t.name.toLowerCase().includes(q) || (t.description ?? '').toLowerCase().includes(q);
        });
    }, [query, templates]);

    const requestDelete = (t: Template) => {
        setCurrent(t);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/onboarding/templates/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleDefault = (t: Template) => {
        router.put(
            `/onboarding/templates/${t.id}`,
            {
                name: t.name,
                description: t.description,
                tasks_json: JSON.stringify(t.tasks ?? {}, null, 2),
                is_default: !t.is_default,
            },
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <>
            <Head title="Onboarding templates" />
            <Main>
                <PageHeader
                    kicker="Onboarding"
                    title="Onboarding templates"
                    description="Define stages and module requirements for onboarding."
                    right={
                        <Button asChild>
                            <Link href="/onboarding/templates/create">
                                <Plus className="h-4 w-4" />
                                Add template
                            </Link>
                        </Button>
                    }
                />

                <SearchBar value={query} onChange={setQuery} placeholder="Search templates..." />

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[880px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-4">Name</div>
                                <div className="col-span-5">Description</div>
                                <div className="col-span-1">Default</div>
                                <div className="col-span-2 text-right">Actions</div>
                            </div>

                            {rows.map((t) => (
                                <div
                                    key={t.id}
                                    className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap"
                                >
                                    <div className="col-span-4 text-sm font-medium truncate">{t.name}</div>
                                    <div className="col-span-5 text-sm text-muted-foreground truncate">{t.description ?? '—'}</div>
                                    <div className="col-span-1 flex items-center">
                                        <Switch checked={t.is_default} onCheckedChange={() => toggleDefault(t)} />
                                    </div>
                                    <div className="col-span-2 flex justify-end gap-2 flex-nowrap">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon"
                                            className="h-9 w-9 rounded-lg hover:bg-accent dark:hover:bg-white/10"
                                            title="Edit"
                                        >
                                            <Link href={`/onboarding/templates/${t.id}/edit`}>
                                                <Pencil className="h-4 w-4" />
                                            </Link>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-9 w-9 rounded-lg hover:bg-destructive/10 text-destructive hover:text-destructive"
                                            onClick={() => requestDelete(t)}
                                            title="Delete"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No templates found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </Main>

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete template?"
                description="This action cannot be undone."
                confirmText="Delete"
                onConfirm={confirmDelete}
            />
        </>
    );
}
