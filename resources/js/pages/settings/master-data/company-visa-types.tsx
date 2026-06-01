import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import {
    MasterDataActiveToggle,
    MasterDataField,
    MasterDataFormSheet,
    MasterDataFormSheetFooter,
    masterDataInputClass,
} from '@/components/settings/master-data-form-sheet';
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
import { Switch } from '@/components/ui/switch';

type CompanyVisaType = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function CompanyVisaTypes({ company_visa_types }: { company_visa_types: CompanyVisaType[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<CompanyVisaType | null>(null);

    const form = useForm({
        name: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return company_visa_types;
        }

        return company_visa_types.filter((v) => v.name.toLowerCase().includes(q));
    }, [query, company_visa_types]);

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

    const openEdit = (companyVisaType: CompanyVisaType) => {
        setCurrent(companyVisaType);
        form.reset();
        form.clearErrors();
        form.setData({
            name: companyVisaType.name,
            is_active: companyVisaType.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/company-visa-types/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/company-visa-types', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (companyVisaType: CompanyVisaType) => {
        setCurrent(companyVisaType);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/company-visa-types/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (companyVisaType: CompanyVisaType) => {
        router.put(
            `/settings/master-data/company-visa-types/${companyVisaType.id}`,
            {
                name: companyVisaType.name,
                is_active: !companyVisaType.is_active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Company visa types" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Company visa types"
                    description="Manage company visa type titles used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search company visa types..."
                            className={masterDataInputClass}
                        />
                    </div>
                    <Button onClick={openCreate}>Add company visa type</Button>
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className="grid grid-cols-12 gap-2 whitespace-nowrap bg-muted/30 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                <div className="col-span-7">Title</div>
                                <div className="col-span-2">Active</div>
                                <div className="col-span-3 text-right">Actions</div>
                            </div>

                            {rows.map((v) => (
                                <div
                                    key={v.id}
                                    className="grid grid-cols-12 gap-2 whitespace-nowrap border-t border-border/60 px-4 py-3"
                                >
                                    <div className="col-span-7 truncate text-sm">{v.name}</div>
                                    <div className="col-span-2 flex items-center">
                                        <Switch checked={v.is_active} onCheckedChange={() => toggleActive(v)} />
                                    </div>
                                    <div className="col-span-3 flex flex-nowrap justify-end gap-2">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(v)}>
                                            Edit
                                        </Button>
                                        <Button variant="destructive" size="sm" onClick={() => requestDelete(v)}>
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No company visa types found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <MasterDataFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={current ? 'Edit company visa type' : 'New company visa type'}
                description="Enter the company visa type title only."
                footer={
                    <MasterDataFormSheetFooter
                        onCancel={() => setSheetOpen(false)}
                        onSubmit={submit}
                        processing={form.processing}
                        submitLabel={current ? 'Save changes' : 'Create company visa type'}
                    />
                }
            >
                <MasterDataField id="title" label="Title" error={form.errors.name}>
                    <Input
                        id="title"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Company Sponsored"
                        className={masterDataInputClass}
                    />
                </MasterDataField>

                <MasterDataActiveToggle
                    checked={form.data.is_active}
                    onCheckedChange={(value) => form.setData('is_active', value)}
                />
            </MasterDataFormSheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete company visa type</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current
                                ? `This will permanently delete “${current.name}”.`
                                : 'This will permanently delete this company visa type.'}
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
