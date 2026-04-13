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

type Country = {
    id: number;
    code: string;
    name: string;
    dial_code: string | null;
    is_active: boolean;
};

export default function Countries({ countries }: { countries: Country[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Country | null>(null);

    const form = useForm({
        code: '',
        name: '',
        dial_code: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return countries;
        }

        return countries.filter((c) => {
            return (
                c.code.toLowerCase().includes(q) ||
                c.name.toLowerCase().includes(q) ||
                (c.dial_code ?? '').toLowerCase().includes(q)
            );
        });
    }, [countries, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            code: '',
            name: '',
            dial_code: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (country: Country) => {
        setCurrent(country);
        form.reset();
        form.clearErrors();
        form.setData({
            code: country.code,
            name: country.name,
            dial_code: country.dial_code ?? '',
            is_active: country.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/countries/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });
            return;
        }

        form.post('/settings/master-data/countries', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (country: Country) => {
        setCurrent(country);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/countries/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (country: Country) => {
        router.put(
            `/settings/master-data/countries/${country.id}`,
            {
                code: country.code,
                name: country.name,
                dial_code: country.dial_code,
                is_active: !country.is_active,
            },
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <>
            <Head title="Countries" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Countries"
                    description="Manage country codes used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by code, name, dial code..."
                        />
                    </div>
                    <Button onClick={openCreate}>Add country</Button>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[720px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                        <div className="col-span-2">Code</div>
                        <div className="col-span-4">Name</div>
                        <div className="col-span-2">Dial</div>
                        <div className="col-span-1">Active</div>
                        <div className="col-span-3 text-right">Actions</div>
                    </div>
                    {rows.map((c) => (
                        <div key={c.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                            <div className="col-span-2 font-mono text-sm">{c.code}</div>
                            <div className="col-span-4 text-sm truncate">{c.name}</div>
                            <div className="col-span-2 text-sm text-muted-foreground">{c.dial_code ?? '—'}</div>
                            <div className="col-span-1 flex items-center">
                                <Switch checked={c.is_active} onCheckedChange={() => toggleActive(c)} />
                            </div>
                            <div className="col-span-3 flex justify-end gap-2 flex-nowrap">
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
                                <div className="px-4 py-10 text-sm text-muted-foreground">No countries found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="w-full sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>{current ? 'Edit country' : 'New country'}</SheetTitle>
                        <SheetDescription>Codes must be 3 letters.</SheetDescription>
                    </SheetHeader>

                    <div className="mt-6 space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                placeholder="UAE"
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
                                placeholder="United Arab Emirates"
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.name}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dial_code">Dial code</Label>
                            <Input
                                id="dial_code"
                                value={form.data.dial_code}
                                onChange={(e) => form.setData('dial_code', e.target.value)}
                                placeholder="+971"
                            />
                            {form.errors.dial_code ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.dial_code}</div>
                            ) : null}
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
                        <AlertDialogTitle>Delete country</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will delete the country if it is not in use. If it is in use, it will be deactivated.
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

