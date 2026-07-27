import { AppSelect, AppSelectItem } from '@/components/app-select';
import { MasterDataListShell } from '@/components/settings/master-data-list-shell';
import {
    MasterDataField,
    MasterDataFormSheet,
    MasterDataFormSheetFooter,
    masterDataInputClass,
} from '@/components/settings/master-data-form-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { useMasterDataCrud } from '@/hooks/use-master-data-crud';
import { firstValidationError } from '@/lib/first-validation-error';
import { toast } from '@/lib/toast';

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

type BankFormData = {
    name: string;
    uae_routing_code_agent_id: string;
    country_id: number | '';
    is_active: boolean;
};

const initialForm: BankFormData = {
    name: '',
    uae_routing_code_agent_id: '',
    country_id: '',
    is_active: true,
};

export default function Banks({
    banks,
    countries,
}: {
    banks: Bank[];
    countries: CountryOption[];
}) {
    const can = useSettingsMasterDataCan('banks');

    const {
        query,
        setQuery,
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
    } = useMasterDataCrud<Bank, BankFormData>({
        items: banks,
        baseUrl: '/settings/master-data/banks',
        initialForm,
        filterItem: (bank, q) =>
            bank.name.toLowerCase().includes(q) ||
            (bank.uae_routing_code_agent_id ?? '')
                .toLowerCase()
                .includes(q) ||
            (bank.country?.name ?? '').toLowerCase().includes(q),
        toFormData: (bank) => ({
            name: bank.name,
            uae_routing_code_agent_id: bank.uae_routing_code_agent_id ?? '',
            country_id: bank.country_id ?? '',
            is_active: bank.is_active,
        }),
        toTogglePayload: (bank) => ({
            name: bank.name,
            uae_routing_code_agent_id: bank.uae_routing_code_agent_id,
            country_id: bank.country_id,
            is_active: !bank.is_active,
        }),
        transformSubmit: (data) => ({
            name: data.name,
            uae_routing_code_agent_id: data.uae_routing_code_agent_id || null,
            country_id: data.country_id || null,
            is_active: data.is_active,
        }),
        onDeleteError: (errors) => {
            toast.error(
                firstValidationError(
                    errors,
                    'bank',
                    'This bank could not be deleted.',
                ),
            );
        },
    });

    return (
        <MasterDataListShell
            headTitle="Banks"
            title="Banks"
            description="Manage banks and routing identifiers used across the system."
            searchPlaceholder="Search banks..."
            query={query}
            onQueryChange={setQuery}
            canCreate={can.create}
            createButtonLabel="Add bank"
            onCreate={openCreate}
            tableMinWidth="min-w-[980px]"
            isEmpty={rows.length === 0}
            emptyLabel="No banks found."
            deleteOpen={deleteOpen}
            onDeleteOpenChange={setDeleteOpen}
            deleteTitle="Delete bank"
            deleteDescription={
                current
                    ? `This will permanently delete “${current.name}”.`
                    : 'This will permanently delete this bank.'
            }
            deleteConfirmText="Delete"
            deleteContentClassName="glass-card"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit bank' : 'New bank'}
                    description="Add name and optional identifiers."
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
                            placeholder="ABU DHABI ISLAMIC BK"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="uae_routing_code_agent_id"
                        label="UAE routing code / agent ID"
                        error={form.errors.uae_routing_code_agent_id}
                    >
                        <Input
                            id="uae_routing_code_agent_id"
                            value={form.data.uae_routing_code_agent_id}
                            onChange={(event) =>
                                form.setData(
                                    'uae_routing_code_agent_id',
                                    event.target.value,
                                )
                            }
                            placeholder="405010101"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="country_id"
                        label="Country"
                        error={form.errors.country_id}
                    >
                        <AppSelect
                            value={
                                form.data.country_id === ''
                                    ? ''
                                    : String(form.data.country_id)
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'country_id',
                                    value ? Number(value) : '',
                                )
                            }
                            variant="dark"
                            placeholder="—"
                        >
                            <AppSelectItem value="">—</AppSelectItem>
                            {countries.map((country) => (
                                <AppSelectItem
                                    key={country.id}
                                    value={String(country.id)}
                                >
                                    {country.name} ({country.code})
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </MasterDataField>

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
                            onCheckedChange={(value) =>
                                form.setData('is_active', value)
                            }
                        />
                    </div>
                </MasterDataFormSheet>
            }
        >
            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                <div className="col-span-4">Name</div>
                <div className="col-span-2">Routing</div>
                <div className="col-span-4">Country</div>
                <div className="col-span-1">Active</div>
                <div className="col-span-1 text-right">Actions</div>
            </div>

            {rows.map((bank) => (
                <div
                    key={bank.id}
                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                >
                    <div className="col-span-4 truncate text-sm">{bank.name}</div>
                    <div className="col-span-2 font-mono text-sm text-muted-foreground">
                        {bank.uae_routing_code_agent_id ?? '—'}
                    </div>
                    <div className="col-span-4 truncate text-sm text-muted-foreground">
                        {bank.country?.name ?? '—'}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={bank.is_active}
                            onCheckedChange={() => toggleActive(bank)}
                        />
                    </div>
                    <div className="col-span-1 flex justify-end gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(bank)}
                            >
                                Edit
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => requestDelete(bank)}
                            >
                                Delete
                            </Button>
                        ) : null}
                    </div>
                </div>
            ))}
        </MasterDataListShell>
    );
}
