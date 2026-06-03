import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';

type Bank = {
    id: number;
    name: string;
    uae_routing_code_agent_id: string | null;
    country_id: number | null;
    country?: { id: number; name: string; code: string } | null;
    is_active: boolean;
};

type CountryOption = {
    id: number;
    name: string;
    code: string;
};

export default function Banks({ banks, countries }: { banks: Bank[]; countries: CountryOption[] }) {
    const can = useSettingsMasterDataCan('banks');

    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Bank | null>(null);

    const form = useForm({
        name: '',
        uae_routing_code_agent_id: '',
        country_id: '' as number | '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return banks;
        }

        return banks.filter((b) => {
            return (
                b.name.toLowerCase().includes(q) ||
                (b.uae_routing_code_agent_id ?? '').toLowerCase().includes(q) ||
                (b.country?.name ?? '').toLowerCase().includes(q)
            );
        });
    }, [banks, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            uae_routing_code_agent_id: '',
            country_id: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (bank: Bank) => {
        setCurrent(bank);
        form.reset();
        form.clearErrors();
        form.setData({
            name: bank.name,
            uae_routing_code_agent_id: bank.uae_routing_code_agent_id ?? '',
            country_id: bank.country_id ?? '',
            is_active: bank.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        const payload = {
            name: form.data.name,
            uae_routing_code_agent_id: form.data.uae_routing_code_agent_id || null,
            country_id: form.data.country_id || null,
            is_active: form.data.is_active,
        };

        if (current) {
            form.put(`/settings/master-data/banks/${current.id}`, {
                data: payload,
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/banks', {
            data: payload,
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (bank: Bank) => {
        setCurrent(bank);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/banks/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (bank: Bank) => {
        router.put(
            `/settings/master-data/banks/${bank.id}`,
            {
                name: bank.name,
                uae_routing_code_agent_id: bank.uae_routing_code_agent_id,
                country_id: bank.country_id,
                is_active: !bank.is_active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Banks" />

            <div className="space-y-6">
                <Heading variant="small" title="Banks" description="Manage banks and routing identifiers used across the system." />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search banks..." />
                    </div>
                    {can.create ? <Button onClick={openCreate}>Add bank</Button> : null}
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[980px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-4">Name</div>
                                <div className="col-span-2">Routing</div>
                                <div className="col-span-4">Country</div>
                                <div className="col-span-1">Active</div>
                                <div className="col-span-1 text-right">Actions</div>
                            </div>

                            {rows.map((b) => (
                                <div key={b.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-4 text-sm truncate">{b.name}</div>
                                    <div className="col-span-2 font-mono text-sm text-muted-foreground">{b.uae_routing_code_agent_id ?? '—'}</div>
                                    <div className="col-span-4 text-sm text-muted-foreground truncate">{b.country?.name ?? '—'}</div>
                                    <div className="col-span-1 flex items-center">
                                        <Switch disabled={!can.update} checked={b.is_active} onCheckedChange={() => toggleActive(b)} />
                                    </div>
                                    <div className="col-span-1 flex justify-end gap-2">
                                        {can.update ? <Button variant="outline" size="sm" onClick={() => openEdit(g)}>Edit</Button> : null}
                                        {can.delete ? <Button variant="destructive" size="sm" onClick={() => requestDelete(b)}>
                                            Delete
                                        </Button> : null}
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No banks found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="flex w-full flex-col rounded-none p-0 glass-card sm:max-w-md">
                    <SheetHeader className="p-8 pb-6 border-b border-border/60">
                        <SheetTitle className="text-xl font-bold tracking-tight">{current ? 'Edit bank' : 'New bank'}</SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">Add name and optional identifiers.</SheetDescription>
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
                                placeholder="ABU DHABI ISLAMIC BK"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="uae_routing_code_agent_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                UAE routing code / agent ID
                            </Label>
                            <Input
                                id="uae_routing_code_agent_id"
                                value={form.data.uae_routing_code_agent_id}
                                onChange={(e) => form.setData('uae_routing_code_agent_id', e.target.value)}
                                placeholder="405010101"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="country_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Country
                            </Label>
                            <AppSelect
                                value={form.data.country_id === '' ? '' : String(form.data.country_id)}
                                onValueChange={(v) => form.setData('country_id', v ? Number(v) : '')}
                                variant="dark"
                                placeholder="—"
                            >
                                <AppSelectItem value="">—</AppSelectItem>
                                {countries.map((c) => (
                                    <AppSelectItem key={c.id} value={String(c.id)}>
                                        {c.name} ({c.code})
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.country_id ? <div className="text-xs font-medium text-destructive">{form.errors.country_id}</div> : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-foreground">Active</div>
                                <div className="text-xs text-muted-foreground/80">Disable to hide from selections.</div>
                            </div>
                            <Switch disabled={!can.update} checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button type="button" variant="ghost" className="h-11 flex-1 rounded-xl px-6 text-muted-foreground" onClick={() => setSheetOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="button" className="h-11 flex-1 rounded-xl px-6 font-semibold" onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete bank</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current ? `This will permanently delete “${current.name}”.` : 'This will permanently delete this bank.'}
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

