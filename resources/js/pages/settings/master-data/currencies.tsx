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

type Currency = {
    id: number;
    code: string;
    name: string;
    symbol: string | null;
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
                <SheetContent
                    side="right"
                    className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col"
                >
                    <SheetHeader className="p-8 pb-6 border-b border-white/5">
                        <SheetTitle className="text-xl font-bold tracking-tight text-white">
                            {current ? 'Edit currency' : 'New currency'}
                        </SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                            Codes must be 3 letters.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 overflow-y-auto p-8 space-y-5">
                        <div className="space-y-2">
                            <Label
                                htmlFor="code"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Code
                            </Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="AED"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                            />
                            {form.errors.code ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.code}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Name
                            </Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="UAE Dirham"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.name}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="symbol"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Symbol
                            </Label>
                            <Input
                                id="symbol"
                                value={form.data.symbol}
                                onChange={(e) => form.setData('symbol', e.target.value)}
                                placeholder="د.إ"
                                className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                            />
                            {form.errors.symbol ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.symbol}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="p-6 border-t border-white/5 bg-black/20 flex gap-3">
                        <Button
                            variant="ghost"
                            onClick={() => setSheetOpen(false)}
                            className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                        >
                            Cancel
                        </Button>
                        <Button
                            className="rounded-xl h-11 px-8 flex-1 font-semibold"
                            disabled={form.processing}
                            onClick={submit}
                        >
                            Save
                        </Button>
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

