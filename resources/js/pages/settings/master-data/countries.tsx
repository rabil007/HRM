import { MasterDataDeleteButton } from '@/components/settings/master-data-delete-button';
import {
    MasterDataField,
    MasterDataFormSheet,
    MasterDataFormSheetFooter,
    masterDataInputClass,
} from '@/components/settings/master-data-form-sheet';
import { MasterDataInUseBadge } from '@/components/settings/master-data-in-use-badge';
import { MasterDataListShell } from '@/components/settings/master-data-list-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { useMasterDataCrud } from '@/hooks/use-master-data-crud';
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';
import type { PaginationMeta } from '@/types/pagination';

type Country = {
    id: number;
    code: string;
    name: string;
    dial_code: string | null;
    is_active: boolean;
} & MasterDataUsageFlags;

type CountryFormData = {
    code: string;
    name: string;
    dial_code: string;
    is_active: boolean;
};

const initialForm: CountryFormData = {
    code: '',
    name: '',
    dial_code: '',
    is_active: true,
};

export default function Countries({
    countries,
    pagination,
    search = '',
}: {
    countries: Country[];
    pagination: PaginationMeta;
    search?: string;
}) {
    const can = useSettingsMasterDataCan('countries');

    const {
        searchInput,
        onSearchChange,
        paginationProps,
        sheetOpen,
        setSheetOpen,
        deleteOpen,
        setDeleteOpen,
        current,
        form,
        rows,
        openCreate,
        openEdit,
        submit,
        requestDelete,
        confirmDelete,
        toggleActive,
    } = useMasterDataCrud<Country, CountryFormData>({
        items: countries,
        baseUrl: '/settings/master-data/countries',
        initialForm,
        search,
        pagination,
        toFormData: (country) => ({
            code: country.code,
            name: country.name,
            dial_code: country.dial_code ?? '',
            is_active: country.is_active,
        }),
        toTogglePayload: (country) => ({
            code: country.code,
            name: country.name,
            dial_code: country.dial_code,
            is_active: !country.is_active,
        }),
    });

    return (
        <MasterDataListShell
            headTitle="Countries"
            title="Countries"
            description="Manage country codes used across the system."
            searchPlaceholder="Search by code, name, dial code..."
            searchInput={searchInput}
            onSearchChange={onSearchChange}
            pagination={paginationProps}
            paginationLabel="countries"
            canCreate={can.create}
            createButtonLabel="Add country"
            onCreate={openCreate}
            tableMinWidth="min-w-[720px]"
            isEmpty={rows.length === 0}
            emptyLabel="No countries found."
            deleteOpen={deleteOpen}
            onDeleteOpenChange={setDeleteOpen}
            deleteTitle="Delete country"
            deleteDescription={
                current
                    ? `This will permanently delete “${current.name}”.`
                    : 'This will permanently delete this country.'
            }
            deleteConfirmText="Delete"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit country' : 'New country'}
                    description="Codes must be 3 letters."
                    footer={
                        <MasterDataFormSheetFooter
                            onCancel={() => setSheetOpen(false)}
                            onSubmit={submit}
                            processing={form.processing}
                            submitLabel="Save"
                        />
                    }
                >
                    <MasterDataField
                        id="code"
                        label="Code"
                        error={form.errors.code}
                    >
                        <Input
                            id="code"
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData('code', event.target.value)
                            }
                            placeholder="UAE"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="name"
                        label="Name"
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="United Arab Emirates"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="dial_code"
                        label="Dial code"
                        error={form.errors.dial_code}
                    >
                        <Input
                            id="dial_code"
                            value={form.data.dial_code}
                            onChange={(event) =>
                                form.setData('dial_code', event.target.value)
                            }
                            placeholder="+971"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>
                </MasterDataFormSheet>
            }
        >
            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                <div className="col-span-2">Code</div>
                <div className="col-span-4">Name</div>
                <div className="col-span-2">Dial</div>
                <div className="col-span-1">Active</div>
                <div className="col-span-3 text-right">Actions</div>
            </div>
            {rows.map((country) => (
                <div
                    key={country.id}
                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                >
                    <div className="col-span-2 font-mono text-sm">
                        {country.code}
                    </div>
                    <div className="col-span-4 flex min-w-0 items-center gap-2 text-sm">
                        <span className="truncate">{country.name}</span>
                        <MasterDataInUseBadge item={country} />
                    </div>
                    <div className="col-span-2 text-sm text-muted-foreground">
                        {country.dial_code ?? '—'}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={country.is_active}
                            onCheckedChange={() => toggleActive(country)}
                        />
                    </div>
                    <div className="col-span-3 flex flex-nowrap justify-end gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(country)}
                            >
                                Edit
                            </Button>
                        ) : null}
                        <MasterDataDeleteButton
                            item={country}
                            hasDeletePermission={can.delete}
                            onDelete={() => requestDelete(country)}
                        />
                    </div>
                </div>
            ))}
        </MasterDataListShell>
    );
}
