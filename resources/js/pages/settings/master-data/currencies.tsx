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

type Currency = {
    id: number;
    code: string;
    name: string;
    symbol: string | null;
    is_active: boolean;
};

type CurrencyFormData = {
    code: string;
    name: string;
    symbol: string;
    is_active: boolean;
};

const initialForm: CurrencyFormData = {
    code: '',
    name: '',
    symbol: '',
    is_active: true,
};

export default function Currencies({ currencies }: { currencies: Currency[] }) {
    const can = useSettingsMasterDataCan('currencies');

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
    } = useMasterDataCrud<Currency, CurrencyFormData>({
        items: currencies,
        baseUrl: '/settings/master-data/currencies',
        initialForm,
        filterItem: (currency, q) =>
            currency.code.toLowerCase().includes(q) ||
            currency.name.toLowerCase().includes(q) ||
            (currency.symbol ?? '').toLowerCase().includes(q),
        toFormData: (currency) => ({
            code: currency.code,
            name: currency.name,
            symbol: currency.symbol ?? '',
            is_active: currency.is_active,
        }),
        toTogglePayload: (currency) => ({
            code: currency.code,
            name: currency.name,
            symbol: currency.symbol,
            is_active: !currency.is_active,
        }),
    });

    return (
        <MasterDataListShell
            headTitle="Currencies"
            title="Currencies"
            description="Manage currency codes used across the system."
            searchPlaceholder="Search by code, name, symbol..."
            query={query}
            onQueryChange={setQuery}
            canCreate={can.create}
            createButtonLabel="Add currency"
            onCreate={openCreate}
            tableMinWidth="min-w-[760px]"
            isEmpty={rows.length === 0}
            emptyLabel="No currencies found."
            deleteOpen={deleteOpen}
            onDeleteOpenChange={setDeleteOpen}
            deleteTitle="Delete currency"
            deleteDescription="This will delete the currency if it is not in use. If it is in use, it will be deactivated."
            deleteConfirmText="Confirm"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit currency' : 'New currency'}
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
                            placeholder="AED"
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
                            placeholder="UAE Dirham"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="symbol"
                        label="Symbol"
                        error={form.errors.symbol}
                    >
                        <Input
                            id="symbol"
                            value={form.data.symbol}
                            onChange={(event) =>
                                form.setData('symbol', event.target.value)
                            }
                            placeholder="د.إ"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>
                </MasterDataFormSheet>
            }
        >
            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                <div className="col-span-2">Code</div>
                <div className="col-span-5">Name</div>
                <div className="col-span-2">Symbol</div>
                <div className="col-span-1">Active</div>
                <div className="col-span-2 text-right">Actions</div>
            </div>
            {rows.map((currency) => (
                <div
                    key={currency.id}
                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                >
                    <div className="col-span-2 font-mono text-sm">
                        {currency.code}
                    </div>
                    <div className="col-span-5 truncate text-sm">
                        {currency.name}
                    </div>
                    <div className="col-span-2 text-sm text-muted-foreground">
                        {currency.symbol ?? '—'}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={currency.is_active}
                            onCheckedChange={() => toggleActive(currency)}
                        />
                    </div>
                    <div className="col-span-2 flex flex-nowrap justify-end gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(currency)}
                            >
                                Edit
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => requestDelete(currency)}
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
