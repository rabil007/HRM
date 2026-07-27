import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import {
    MasterDataActiveToggle,
    MasterDataField,
    MasterDataFormSheet,
    MasterDataFormSheetFooter,
    masterDataInputClass,
} from '@/components/settings/master-data-form-sheet';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import {
    useMasterDataNameActiveCrud,
    type MasterDataNameActiveItem,
} from '@/hooks/use-master-data-name-active-crud';

type MasterDataNameActivePageProps<T extends MasterDataNameActiveItem> = {
    headTitle: string;
    title: string;
    description: string;
    resource: string;
    baseUrl: string;
    items: T[];
    entityLabel: string;
    searchPlaceholder: string;
    createButtonLabel: string;
    nameColumnLabel?: string;
    nameFieldLabel?: string;
    nameFieldId?: string;
    namePlaceholder: string;
    sheetDescription: string;
    emptyLabel: string;
    createSubmitLabel: string;
    filterItem?: (item: T, query: string) => boolean;
};

export function MasterDataNameActivePage<T extends MasterDataNameActiveItem>({
    headTitle,
    title,
    description,
    resource,
    baseUrl,
    items,
    entityLabel,
    searchPlaceholder,
    createButtonLabel,
    nameColumnLabel = 'Name',
    nameFieldLabel = 'Name',
    nameFieldId = 'name',
    namePlaceholder,
    sheetDescription,
    emptyLabel,
    createSubmitLabel,
    filterItem,
}: MasterDataNameActivePageProps<T>) {
    const can = useSettingsMasterDataCan(resource);
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
    } = useMasterDataNameActiveCrud({
        items,
        baseUrl,
        filterItem,
    });

    return (
        <>
            <Head title={headTitle} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={title}
                    description={description}
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={searchPlaceholder}
                            className={masterDataInputClass}
                        />
                    </div>
                    {can.create ? (
                        <Button onClick={openCreate}>{createButtonLabel}</Button>
                    ) : null}
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                                <div className="col-span-7">{nameColumnLabel}</div>
                                <div className="col-span-2">Active</div>
                                <div className="col-span-3 text-right">
                                    Actions
                                </div>
                            </div>

                            {rows.map((item) => (
                                <div
                                    key={item.id}
                                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                                >
                                    <div className="col-span-7 truncate text-sm">
                                        {item.name}
                                    </div>
                                    <div className="col-span-2 flex items-center">
                                        <Switch
                                            disabled={!can.update}
                                            checked={item.is_active}
                                            onCheckedChange={() =>
                                                toggleActive(item)
                                            }
                                        />
                                    </div>
                                    <div className="col-span-3 flex flex-nowrap justify-end gap-2">
                                        {can.update ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(item)}
                                            >
                                                Edit
                                            </Button>
                                        ) : null}
                                        {can.delete ? (
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    requestDelete(item)
                                                }
                                            >
                                                Delete
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">
                                    {emptyLabel}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <MasterDataFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    current
                        ? `Edit ${entityLabel}`
                        : `New ${entityLabel}`
                }
                description={sheetDescription}
                footer={
                    <MasterDataFormSheetFooter
                        onCancel={() => setSheetOpen(false)}
                        onSubmit={submit}
                        processing={form.processing}
                        submitLabel={
                            current ? 'Save changes' : createSubmitLabel
                        }
                    />
                }
            >
                <MasterDataField
                    id={nameFieldId}
                    label={nameFieldLabel}
                    error={form.errors.name}
                >
                    <Input
                        id={nameFieldId}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder={namePlaceholder}
                        className={masterDataInputClass}
                    />
                </MasterDataField>

                <MasterDataActiveToggle
                    checked={form.data.is_active}
                    onCheckedChange={(value) =>
                        form.setData('is_active', value)
                    }
                />
            </MasterDataFormSheet>

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title={`Delete ${entityLabel}`}
                description={
                    current
                        ? `This will permanently delete “${current.name}”.`
                        : `This will permanently delete this ${entityLabel}.`
                }
                confirmText="Delete"
                onConfirm={confirmDelete}
                contentClassName="glass-card"
            />
        </>
    );
}
