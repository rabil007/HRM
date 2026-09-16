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

type RoomType = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
} & MasterDataUsageFlags;

type RoomTypeFormData = {
    name: string;
    description: string;
    is_active: boolean;
};

const initialForm: RoomTypeFormData = {
    name: '',
    description: '',
    is_active: true,
};

export default function RoomTypes({
    room_types,
    pagination,
    search = '',
}: {
    room_types: RoomType[];
    pagination: PaginationMeta;
    search?: string;
}) {
    const can = useSettingsMasterDataCan('room-types');

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
    } = useMasterDataCrud<RoomType, RoomTypeFormData>({
        items: room_types,
        baseUrl: '/settings/master-data/room-types',
        initialForm,
        search,
        pagination,
        toFormData: (roomType) => ({
            name: roomType.name,
            description: roomType.description ?? '',
            is_active: roomType.is_active,
        }),
        toTogglePayload: (roomType) => ({
            name: roomType.name,
            description: roomType.description,
            is_active: !roomType.is_active,
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
                    'This room type could not be deleted.',
                ),
            );
        },
    });

    return (
        <MasterDataListShell
            headTitle="Room Types"
            title="Room Types"
            description="Manage room categories used for crew accommodation tracking."
            searchPlaceholder="Search room types..."
            searchInput={searchInput}
            onSearchChange={onSearchChange}
            pagination={paginationProps}
            paginationLabel="room types"
            canCreate={can.create}
            createButtonLabel="Add room type"
            onCreate={openCreate}
            tableMinWidth="min-w-[900px]"
            isEmpty={rows.length === 0}
            emptyLabel="No room types found."
            deleteOpen={deleteOpen}
            onDeleteOpenChange={setDeleteOpen}
            deleteTitle="Delete room type"
            deleteDescription={
                current
                    ? `This will permanently delete “${current.name}”.`
                    : 'This will permanently delete this room type.'
            }
            deleteConfirmText="Delete"
            deleteContentClassName="glass-card"
            onConfirmDelete={confirmDelete}
            sheet={
                <MasterDataFormSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    title={current ? 'Edit room type' : 'New room type'}
                    description="Add a room category name and optional description."
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
                            placeholder="Twin Sharing"
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
                            placeholder="Optional notes about this room type"
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

            {rows.map((roomType) => (
                <div
                    key={roomType.id}
                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                >
                    <div className="col-span-4 flex min-w-0 items-center gap-2 text-sm">
                        <span className="truncate">{roomType.name}</span>
                        <MasterDataInUseBadge item={roomType} />
                    </div>
                    <div className="col-span-5 truncate text-sm text-muted-foreground">
                        {roomType.description ?? '—'}
                    </div>
                    <div className="col-span-1 flex items-center">
                        <Switch
                            disabled={!can.update}
                            checked={roomType.is_active}
                            onCheckedChange={() => toggleActive(roomType)}
                        />
                    </div>
                    <div className="col-span-2 flex justify-end gap-2">
                        {can.update ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(roomType)}
                            >
                                Edit
                            </Button>
                        ) : null}
                        <MasterDataDeleteButton
                            item={roomType}
                            hasDeletePermission={can.delete}
                            onDelete={() => requestDelete(roomType)}
                        />
                    </div>
                </div>
            ))}
        </MasterDataListShell>
    );
}
