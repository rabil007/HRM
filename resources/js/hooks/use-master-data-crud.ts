import type { InertiaFormProps } from '@inertiajs/react';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type UseMasterDataCrudOptions<
    TItem extends { id: number },
    TForm extends Record<string, unknown>,
> = {
    items: TItem[];
    baseUrl: string;
    initialForm: TForm;
    filterItem?: (item: TItem, query: string) => boolean;
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
    filterItem,
    toFormData,
    toTogglePayload,
    transformSubmit,
    onDeleteError,
}: UseMasterDataCrudOptions<TItem, TForm>) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<TItem | null>(null);

    const form = useForm<TForm>(initialForm);

    const rows = useMemo(() => {
        const normalized = query.trim().toLowerCase();

        if (!normalized) {
            return items;
        }

        if (!filterItem) {
            return items;
        }

        return items.filter((item) => filterItem(item, normalized));
    }, [filterItem, items, query]);

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
        query,
        setQuery,
        sheetOpen,
        setSheetOpen,
        deleteOpen,
        setDeleteOpen,
        current,
        form: form as InertiaFormProps<TForm>,
        rows,
        openCreate,
        openEdit,
        submit,
        requestDelete,
        confirmDelete,
        toggleActive,
    };
}
