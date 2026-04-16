import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';

type DocumentType = {
    id: number;
    title: string;
    slug: string;
    is_active: boolean;
};

export default function DocumentTypes({ document_types }: { document_types: DocumentType[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<DocumentType | null>(null);

    const form = useForm({
        title: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return document_types;
        }

        return document_types.filter((d) => d.title.toLowerCase().includes(q) || d.slug.toLowerCase().includes(q));
    }, [document_types, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            title: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (doc: DocumentType) => {
        setCurrent(doc);
        form.reset();
        form.clearErrors();
        form.setData({
            title: doc.title,
            is_active: doc.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/document-types/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/document-types', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (doc: DocumentType) => {
        setCurrent(doc);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/document-types/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (doc: DocumentType) => {
        router.put(
            `/settings/master-data/document-types/${doc.id}`,
            {
                title: doc.title,
                is_active: !doc.is_active,
            },
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <>
            <Head title="Document types" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Document types"
                    description="Manage employee document type labels used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search by title or slug..." />
                    </div>
                    <Button onClick={openCreate}>Add document type</Button>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[820px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-5">Title</div>
                                <div className="col-span-4">Slug</div>
                                <div className="col-span-1">Active</div>
                                <div className="col-span-2 text-right">Actions</div>
                            </div>

                            {rows.map((d) => (
                                <div key={d.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-5 text-sm font-medium truncate">{d.title}</div>
                                    <div className="col-span-4 text-sm font-mono text-muted-foreground truncate">{d.slug}</div>
                                    <div className="col-span-1 flex items-center">
                                        <Switch checked={d.is_active} onCheckedChange={() => toggleActive(d)} />
                                    </div>
                                    <div className="col-span-2 flex justify-end gap-2 flex-nowrap">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(d)}>
                                            Edit
                                        </Button>
                                        <Button variant="destructive" size="sm" onClick={() => requestDelete(d)}>
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No document types found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent
                    side="right"
                    className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col"
                >
                    <SheetHeader className="p-8 pb-6 border-b border-white/5">
                        <SheetTitle className="text-xl font-bold tracking-tight text-white">
                            {current ? 'Edit document type' : 'New document type'}
                        </SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                            Used for employee documents and onboarding requirements.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                        className="flex-1 overflow-y-auto p-8 space-y-5"
                    >
                        <div className="space-y-2">
                            <Label
                                htmlFor="title"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Title
                            </Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.title ? <div className="text-xs text-destructive">{form.errors.title}</div> : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border bg-card p-3">
                            <div>
                                <div className="text-sm font-semibold">Active</div>
                                <div className="text-xs text-muted-foreground">Visible in dropdowns and templates.</div>
                            </div>
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </div>

                        <div className="pt-2 flex items-center justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setSheetOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {current ? 'Save changes' : 'Create'}
                            </Button>
                        </div>
                    </form>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete document type?</AlertDialogTitle>
                        <AlertDialogDescription>This action cannot be undone.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

