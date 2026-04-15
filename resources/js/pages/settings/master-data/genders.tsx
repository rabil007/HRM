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

type Gender = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function Genders({ genders }: { genders: Gender[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Gender | null>(null);

    const form = useForm({
        name: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return genders;
        }

        return genders.filter((g) => g.name.toLowerCase().includes(q));
    }, [genders, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (gender: Gender) => {
        setCurrent(gender);
        form.reset();
        form.clearErrors();
        form.setData({
            name: gender.name,
            is_active: gender.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/genders/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/genders', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (gender: Gender) => {
        setCurrent(gender);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/genders/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (gender: Gender) => {
        router.put(
            `/settings/master-data/genders/${gender.id}`,
            {
                name: gender.name,
                is_active: !gender.is_active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Genders" />

            <div className="space-y-6">
                <Heading variant="small" title="Genders" description="Manage genders used across the system." />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search genders..." />
                    </div>
                    <Button onClick={openCreate}>Add gender</Button>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-7">Name</div>
                                <div className="col-span-2">Active</div>
                                <div className="col-span-3 text-right">Actions</div>
                            </div>

                            {rows.map((g) => (
                                <div key={g.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-7 text-sm truncate">{g.name}</div>
                                    <div className="col-span-2 flex items-center">
                                        <Switch checked={g.is_active} onCheckedChange={() => toggleActive(g)} />
                                    </div>
                                    <div className="col-span-3 flex justify-end gap-2 flex-nowrap">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(g)}>
                                            Edit
                                        </Button>
                                        <Button variant="destructive" size="sm" onClick={() => requestDelete(g)}>
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No genders found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col">
                    <SheetHeader className="p-8 pb-6 border-b border-white/5">
                        <SheetTitle className="text-xl font-bold tracking-tight text-white">{current ? 'Edit gender' : 'New gender'}</SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">Keep names short and consistent.</SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 overflow-y-auto p-8 space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Name
                            </Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="Male"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 p-4">
                            <div>
                                <div className="text-sm font-semibold text-white">Active</div>
                                <div className="text-xs text-muted-foreground/80">Disable to hide from selections.</div>
                            </div>
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </div>
                    </div>

                    <div className="p-6 border-t border-white/5 bg-black/40">
                        <div className="flex items-center justify-end gap-3">
                            <Button variant="outline" onClick={() => setSheetOpen(false)}>
                                Cancel
                            </Button>
                            <Button onClick={submit} disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save'}
                            </Button>
                        </div>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete gender</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current ? `This will permanently delete “${current.name}”.` : 'This will permanently delete this gender.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="glass-card rounded-xl hover:bg-accent">Cancel</AlertDialogCancel>
                        <AlertDialogAction className="rounded-xl" onClick={confirmDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

