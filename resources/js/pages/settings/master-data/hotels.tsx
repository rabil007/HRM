import { Plus, Trash2 } from 'lucide-react';
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

type HotelRoomType = {
    id?: number;
    name: string;
    description: string;
    is_active: boolean;
} & Partial<MasterDataUsageFlags>;

type Hotel = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    room_types: HotelRoomType[];
} & MasterDataUsageFlags;

type HotelFormData = {
    name: string;
    description: string;
    is_active: boolean;
    room_types: HotelRoomType[];
};

const initialForm: HotelFormData = {
    name: '',
    description: '',
    is_active: true,
    room_types: [],
};

function emptyRoomType(): HotelRoomType {
    return {
        name: '',
        description: '',
        is_active: true,
    };
}

function roomTypeError(
    errors: Record<string, string>,
    index: number,
    field: 'name' | 'description' | 'is_active',
): string | undefined {
    return (
        errors[`room_types.${index}.${field}`] ??
        errors[`room_types.${index}`] ??
        undefined
    );
}

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
            room_types: (hotel.room_types ?? []).map((roomType) => ({
                id: roomType.id,
                name: roomType.name,
                description: roomType.description ?? '',
                is_active: roomType.is_active,
                is_in_use: roomType.is_in_use,
                can_delete: roomType.can_delete,
            })),
        }),
        toTogglePayload: (hotel) => ({
            name: hotel.name,
            description: hotel.description,
            is_active: !hotel.is_active,
            room_types: (hotel.room_types ?? []).map((roomType) => ({
                id: roomType.id,
                name: roomType.name,
                description: roomType.description ?? '',
                is_active: roomType.is_active,
            })),
        }),
        transformSubmit: (data) => ({
            name: data.name,
            description: data.description || null,
            is_active: data.is_active,
            room_types: data.room_types
                .filter((roomType) => roomType.name.trim() !== '')
                .map((roomType) => ({
                    id: roomType.id,
                    name: roomType.name.trim(),
                    description: roomType.description.trim() || null,
                    is_active: roomType.is_active,
                })),
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

    const addRoomType = (): void => {
        form.setData('room_types', [...form.data.room_types, emptyRoomType()]);
    };

    const updateRoomType = (
        index: number,
        patch: Partial<HotelRoomType>,
    ): void => {
        form.setData(
            'room_types',
            form.data.room_types.map((roomType, rowIndex) =>
                rowIndex === index ? { ...roomType, ...patch } : roomType,
            ),
        );
    };

    const removeRoomType = (index: number): void => {
        const roomType = form.data.room_types[index];

        if (roomType.is_in_use) {
            toast.error(
                'Referenced room types cannot be removed. Deactivate them instead.',
            );

            return;
        }

        form.setData(
            'room_types',
            form.data.room_types.filter((_, rowIndex) => rowIndex !== index),
        );
    };

    return (
        <MasterDataListShell
            headTitle="Hotels"
            title="Hotels"
            description="Manage hotels and their room types for crew accommodation tracking."
            searchPlaceholder="Search hotels or room types..."
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
                    ? `This will permanently delete “${current.name}” and any unused room types.`
                    : 'This will permanently delete this hotel and any unused room types.'
            }
            deleteConfirmText="Delete"
            deleteContentClassName="glass-card"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit hotel' : 'New hotel'}
                    description="Manage hotel details and the room types available at this property."
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

                    <div className="space-y-3 rounded-xl border border-border/60 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-foreground">
                                    Room types
                                </h3>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Room types belong to this hotel and are used
                                    when recording crew accommodation.
                                </p>
                            </div>
                            {can.create || can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addRoomType}
                                >
                                    <Plus className="size-4" />
                                    Add room type
                                </Button>
                            ) : null}
                        </div>

                        {form.data.room_types.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No room types yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {form.data.room_types.map((roomType, index) => (
                                    <div
                                        key={
                                            roomType.id
                                                ? `room-type-${roomType.id}`
                                                : `room-type-new-${index}`
                                        }
                                        className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-3"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <span className="text-sm font-medium text-foreground">
                                                    Room type {index + 1}
                                                </span>
                                                {roomType.is_in_use ? (
                                                    <MasterDataInUseBadge
                                                        item={roomType}
                                                    />
                                                ) : null}
                                            </div>
                                            {can.update &&
                                            (roomType.can_delete ?? true) &&
                                            !roomType.is_in_use ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-destructive"
                                                    onClick={() =>
                                                        removeRoomType(index)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            ) : null}
                                        </div>

                                        <MasterDataField
                                            id={`room-type-name-${index}`}
                                            label="Name"
                                            error={roomTypeError(
                                                form.errors,
                                                index,
                                                'name',
                                            )}
                                        >
                                            <Input
                                                id={`room-type-name-${index}`}
                                                value={roomType.name}
                                                disabled={!can.update}
                                                onChange={(event) =>
                                                    updateRoomType(index, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder="Single Room"
                                                className={masterDataInputClass}
                                            />
                                        </MasterDataField>

                                        <MasterDataField
                                            id={`room-type-description-${index}`}
                                            label="Description"
                                            error={roomTypeError(
                                                form.errors,
                                                index,
                                                'description',
                                            )}
                                        >
                                            <Textarea
                                                id={`room-type-description-${index}`}
                                                value={roomType.description}
                                                disabled={!can.update}
                                                onChange={(event) =>
                                                    updateRoomType(index, {
                                                        description:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="Optional notes"
                                                className={masterDataInputClass}
                                                rows={2}
                                            />
                                        </MasterDataField>

                                        <div className="flex items-center justify-between rounded-lg border border-border/60 bg-background/60 px-3 py-2">
                                            <span className="text-sm text-foreground">
                                                Active
                                            </span>
                                            <Switch
                                                disabled={!can.update}
                                                checked={roomType.is_active}
                                                onCheckedChange={(value) =>
                                                    updateRoomType(index, {
                                                        is_active: value,
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </MasterDataFormSheet>
            }
        >
            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                <div className="col-span-4">Name</div>
                <div className="col-span-4">Room types</div>
                <div className="col-span-1">Active</div>
                <div className="col-span-3 text-right">Actions</div>
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
                    <div className="col-span-4 truncate text-sm text-muted-foreground">
                        {(hotel.room_types ?? []).length === 0
                            ? '—'
                            : (hotel.room_types ?? [])
                                  .map((roomType) => roomType.name)
                                  .join(', ')}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={hotel.is_active}
                            onCheckedChange={() => toggleActive(hotel)}
                        />
                    </div>
                    <div className="col-span-3 flex justify-end gap-2">
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
