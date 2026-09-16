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
import { Textarea } from '@/components/ui/textarea';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { useMasterDataCrud } from '@/hooks/use-master-data-crud';
import { firstValidationError } from '@/lib/first-validation-error';
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';
import { toast } from '@/lib/toast';
import type { PaginationMeta } from '@/types/pagination';

type Hotel = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
} & MasterDataUsageFlags;

type HotelFormData = {
    name: string;
    description: string;
    is_active: boolean;
};

const initialForm: HotelFormData = {
    name: '',
    description: '',
    is_active: true,
};

export default function Hotels({
    hotels,
    pagination,
    search = '',
}: {
    hotels: Hotel[];
    pagination: PaginationMeta;
    search?: string;
}) {
    const can = useSettingsMasterDataCan('hotels');

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
    } = useMasterDataCrud<Hotel, HotelFormData>({
        items: hotels,
        baseUrl: '/settings/master-data/hotels',
        initialForm,
        search,
        pagination,
        toFormData: (hotel) => ({
            name: hotel.name,
            description: hotel.description ?? '',
            is_active: hotel.is_active,
        }),
        toTogglePayload: (hotel) => ({
            name: hotel.name,
            description: hotel.description,
            is_active: !hotel.is_active,
        }),
        transformSubmit: (data) => ({
            name: data.name,
            description: data.description || null,
            is_active: data.is_active,
        }),
        onDeleteError: (errors) => {
            toast.error(
                firstValidationError(
                    errors,
                    'record',
                    'This hotel could not be deleted.',
                ),
            );
        },
    });

    return (
        <MasterDataListShell
            headTitle="Hotels"
            title="Hotels"
            description="Manage hotels used for crew accommodation tracking."
            searchPlaceholder="Search hotels..."
            searchInput={searchInput}
            onSearchChange={onSearchChange}
            pagination={paginationProps}
            paginationLabel="hotels"
            canCreate={can.create}
            createButtonLabel="Add hotel"
            onCreate={openCreate}
            tableMinWidth="min-w-[900px]"
            isEmpty={rows.length === 0}
            emptyLabel="No hotels found."
            deleteOpen={deleteOpen}
            onDeleteOpenChange={setDeleteOpen}
            deleteTitle="Delete hotel"
            deleteDescription={
                current
                    ? `This will permanently delete “${current.name}”.`
                    : 'This will permanently delete this hotel.'
            }
            deleteConfirmText="Delete"
            deleteContentClassName="glass-card"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit hotel' : 'New hotel'}
                    description="Add a hotel name and optional description."
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
                            placeholder="Royal Rose Hotel"
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="description"
                        label="Description"
                        error={form.errors.description}
                    >
                        <Textarea
                            id="description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            placeholder="Optional notes about this hotel"
                            className={masterDataInputClass}
                            rows={3}
                        />
                    </MasterDataField>

                    <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                        <div>
                            <div className="text-sm font-semibold text-foreground">
                                Active
                            </div>
                            <div className="text-xs text-muted-foreground/80">
                                Disable to hide from new accommodation
                                selections.
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
                <div className="col-span-5">Description</div>
                <div className="col-span-1">Active</div>
                <div className="col-span-2 text-right">Actions</div>
            </div>

            {rows.map((hotel) => (
                <div
                    key={hotel.id}
                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                >
                    <div className="col-span-4 flex min-w-0 items-center gap-2 text-sm">
                        <span className="truncate">{hotel.name}</span>
                        <MasterDataInUseBadge item={hotel} />
                    </div>
                    <div className="col-span-5 truncate text-sm text-muted-foreground">
                        {hotel.description ?? '—'}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={hotel.is_active}
                            onCheckedChange={() => toggleActive(hotel)}
                        />
                    </div>
                    <div className="col-span-2 flex justify-end gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(hotel)}
                            >
                                Edit
                            </Button>
                        ) : null}
                        <MasterDataDeleteButton
                            item={hotel}
                            hasDeletePermission={can.delete}
                            onDelete={() => requestDelete(hotel)}
                        />
                    </div>
                </div>
            ))}
        </MasterDataListShell>
    );
}
