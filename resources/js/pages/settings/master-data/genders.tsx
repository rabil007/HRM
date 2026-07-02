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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';

type Gender = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function Genders({ genders }: { genders: Gender[] }) {
    const can = useSettingsMasterDataCan('genders');

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
                <Heading
                    variant="small"
                    title="Genders"
                    description="Manage genders used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search genders..."
                        />
                    </div>
                    {can.create ? (
                        <Button onClick={openCreate}>Add gender</Button>
                    ) : null}
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                                <div className="col-span-7">Name</div>
                                <div className="col-span-2">Active</div>
                                <div className="col-span-3 text-right">
                                    Actions
                                </div>
                            </div>

                            {rows.map((g) => (
                                <div
                                    key={g.id}
                                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                                >
                                    <div className="col-span-7 truncate text-sm">
                                        {g.name}
                                    </div>
                                    <div className="col-span-2 flex items-center">
                                        <Switch
                                            disabled={!can.update}
                                            checked={g.is_active}
                                            onCheckedChange={() =>
                                                toggleActive(g)
                                            }
                                        />
                                    </div>
                                    <div className="col-span-3 flex flex-nowrap justify-end gap-2">
                                        {can.update ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(g)}
                                            >
                                                Edit
                                            </Button>
                                        ) : null}
                                        {can.delete ? (
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => requestDelete(g)}
                                            >
                                                Delete
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">
                                    No genders found.
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
                >
                    <SheetHeader className="border-b border-border/60 p-8 pb-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            {current ? 'Edit gender' : 'New gender'}
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                            Keep names short and consistent.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-5 overflow-y-auto p-8">
                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Name
                            </Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Male"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-foreground">
                                    Active
                                </div>
                                <div className="text-xs text-muted-foreground/80">
                                    Disable to hide from selections.
                                </div>
                            </div>
                            <Switch
                                disabled={!can.update}
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v)
                                }
                            />
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                            onClick={() => setSheetOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="h-11 flex-1 rounded-xl px-6 font-semibold"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete gender</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current
                                ? `This will permanently delete “${current.name}”.`
                                : 'This will permanently delete this gender.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className="rounded-xl"
                            onClick={confirmDelete}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
