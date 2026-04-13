import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
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

type Currency = {
    id: number;
    code: string;
    name: string;
    symbol: string | null;
    precision: number;
    is_active: boolean;
};

export default function Currencies({ currencies }: { currencies: Currency[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Currency | null>(null);

    const form = useForm({
        code: '',
        name: '',
        symbol: '',
        precision: 2,
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return currencies;
        }

        return currencies.filter((c) => {
            return (
                c.code.toLowerCase().includes(q) ||
                c.name.toLowerCase().includes(q) ||
                (c.symbol ?? '').toLowerCase().includes(q)
            );
        });
    }, [currencies, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            code: '',
            name: '',
            symbol: '',
            precision: 2,
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (currency: Currency) => {
        setCurrent(currency);
        form.reset();
        form.clearErrors();
        form.setData({
            code: currency.code,
            name: currency.name,
            symbol: currency.symbol ?? '',
            precision: currency.precision,
            is_active: currency.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/currencies/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });
            return;
        }

        form.post('/settings/master-data/currencies', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (currency: Currency) => {
        setCurrent(currency);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/currencies/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (currency: Currency) => {
        router.put(
            `/settings/master-data/currencies/${currency.id}`,
            {
                code: currency.code,
                name: currency.name,
                symbol: currency.symbol,
                precision: currency.precision,
                is_active: !currency.is_active,
            },
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <>
            <Head title="Currencies" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Currencies"
                    description="Manage currency codes used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by code, name, symbol..."
                        />
                    </div>
                    <Button onClick={openCreate}>Add currency</Button>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[760px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                        <div className="col-span-2">Code</div>
                        <div className="col-span-5">Name</div>
                        <div className="col-span-2">Symbol</div>
                        <div className="col-span-1">Active</div>
                        <div className="col-span-2 text-right">Actions</div>
                    </div>
                    {rows.map((c) => (
                        <div key={c.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                            <div className="col-span-2 font-mono text-sm">{c.code}</div>
                            <div className="col-span-5 text-sm truncate">{c.name}</div>
                            <div className="col-span-2 text-sm text-muted-foreground">{c.symbol ?? '—'}</div>
                            <div className="col-span-1 flex items-center">
                                <Switch checked={c.is_active} onCheckedChange={() => toggleActive(c)} />
                            </div>
                            <div className="col-span-2 flex justify-end gap-2 flex-nowrap">
                                <Button variant="outline" size="sm" onClick={() => openEdit(c)}>
                                    Edit
                                </Button>
                                <Button variant="destructive" size="sm" onClick={() => requestDelete(c)}>
                                    Delete
                                </Button>
                            </div>
                        </div>
                    ))}
                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No currencies found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="w-full sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>{current ? 'Edit currency' : 'New currency'}</SheetTitle>
                        <SheetDescription>Codes must be 3 letters.</SheetDescription>
                    </SheetHeader>

                    <div className="mt-6 space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="AED"
                            />
                            {form.errors.code ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.code}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="UAE Dirham"
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.name}</div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="symbol">Symbol</Label>
                                <Input
                                    id="symbol"
                                    value={form.data.symbol}
                                    onChange={(e) => form.setData('symbol', e.target.value)}
                                    placeholder="د.إ"
                                />
                                {form.errors.symbol ? (
                                    <div className="text-xs font-medium text-destructive">{form.errors.symbol}</div>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex gap-3 pt-2">
                            <Button variant="outline" className="flex-1" onClick={() => setSheetOpen(false)}>
                                Cancel
                            </Button>
                            <Button className="flex-1" disabled={form.processing} onClick={submit}>
                                Save
                            </Button>
                        </div>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete currency</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will delete the currency if it is not in use. If it is in use, it will be deactivated.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>Confirm</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

