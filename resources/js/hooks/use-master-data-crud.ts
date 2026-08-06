import type { InertiaFormProps } from '@inertiajs/react';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import type { PaginationMeta } from '@/types/pagination';

type UseMasterDataCrudOptions<
    TItem extends { id: number },
    TForm extends Record<string, unknown>,
> = {
    items: TItem[];
    baseUrl: string;
    initialForm: TForm;
    search?: string;
    pagination: PaginationMeta;
    toFormData: (item: TItem) => TForm;
    toTogglePayload: (item: TItem) => Record<string, any>;
    transformSubmit?: (data: TForm) => Record<string, any>;
    onDeleteError?: (errors: Record<string, string>) => void;
};

export function useMasterDataCrud<
    TItem extends { id: number },
    TForm extends Record<string, any>,
>({
    items,
    baseUrl,
    initialForm,
    search = '',
    pagination,
    toFormData,
    toTogglePayload,
    transformSubmit,
    onDeleteError,
}: UseMasterDataCrudOptions<TItem, TForm>) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<TItem | null>(null);

    const form = useForm<TForm>(initialForm);

    const list = useServerPaginationFilters({
        url: baseUrl,
        search,
        filters: {},
        pagination,
    });

    const openCreate = (): void => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData(initialForm);
        setSheetOpen(true);
    };

    const openEdit = (item: TItem): void => {
        setCurrent(item);
        form.reset();
        form.clearErrors();
        form.setData(toFormData(item));
        setSheetOpen(true);
    };

    const submit = (): void => {
        if (transformSubmit) {
            form.transform(() => transformSubmit(form.data));
        }

        if (current) {
            form.put(`${baseUrl}/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post(baseUrl, {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (item: TItem): void => {
        setCurrent(item);
        setDeleteOpen(true);
    };

    const confirmDelete = (): void => {
        if (!current) {
            return;
        }

        router.delete(`${baseUrl}/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
            ...(onDeleteError ? { onError: onDeleteError } : {}),
        });
    };

    const toggleActive = (item: TItem): void => {
        router.put(`${baseUrl}/${item.id}`, toTogglePayload(item), {
            preserveScroll: true,
        });
    };

    return {
        searchInput: list.searchInput,
        onSearchChange: list.onSearchChange,
        paginationProps: list.paginationProps,
        sheetOpen,
        setSheetOpen,
        deleteOpen,
        setDeleteOpen,
        current,
        form: form as InertiaFormProps<TForm>,
        rows: items,
        openCreate,
        openEdit,
        submit,
        requestDelete,
        confirmDelete,
        toggleActive,
    };
}
