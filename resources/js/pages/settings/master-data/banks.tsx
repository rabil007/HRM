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
                    <Button onClick={openCreate}>Add bank</Button>
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
                                        <Switch checked={b.is_active} onCheckedChange={() => toggleActive(b)} />
                                    </div>
                                    <div className="col-span-1 flex justify-end gap-2">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(b)}>
                                            Edit
                                        </Button>
                                        <Button variant="destructive" size="sm" onClick={() => requestDelete(b)}>
                                            Delete
                                        </Button>
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
                <SheetContent side="right" className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col">
                    <SheetHeader className="p-8 pb-6 border-b border-white/5">
                        <SheetTitle className="text-xl font-bold tracking-tight text-white">{current ? 'Edit bank' : 'New bank'}</SheetTitle>
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
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
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
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="country_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Country
                            </Label>
                            <select
                                id="country_id"
                                value={form.data.country_id === '' ? '' : String(form.data.country_id)}
                                onChange={(e) => form.setData('country_id', e.target.value ? Number(e.target.value) : '')}
                                className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                            >
                                <option value="">—</option>
                                {countries.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.name} ({c.code})
                                    </option>
                                ))}
                            </select>
                            {form.errors.country_id ? <div className="text-xs font-medium text-destructive">{form.errors.country_id}</div> : null}
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

