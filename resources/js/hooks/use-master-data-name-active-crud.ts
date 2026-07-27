import type { InertiaFormProps } from '@inertiajs/react';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export type MasterDataNameActiveItem = {
    id: number;
    name: string;
    is_active: boolean;
};

export type MasterDataNameActiveFormData = {
    name: string;
    is_active: boolean;
};

type UseMasterDataNameActiveCrudOptions<T extends MasterDataNameActiveItem> = {
    items: T[];
    baseUrl: string;
    filterItem?: (item: T, query: string) => boolean;
};

export function useMasterDataNameActiveCrud<
    T extends MasterDataNameActiveItem,
>({
    items,
    baseUrl,
    filterItem = (item, query) => item.name.toLowerCase().includes(query),
}: UseMasterDataNameActiveCrudOptions<T>) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<T | null>(null);

    const form = useForm<MasterDataNameActiveFormData>({
        name: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const normalized = query.trim().toLowerCase();

        if (!normalized) {
            return items;
        }

        return items.filter((item) => filterItem(item, normalized));
    }, [filterItem, items, query]);

    const openCreate = (): void => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (item: T): void => {
        setCurrent(item);
        form.reset();
        form.clearErrors();
        form.setData({
            name: item.name,
            is_active: item.is_active,
        });
        setSheetOpen(true);
    };

    const submit = (): void => {
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

    const requestDelete = (item: T): void => {
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
        });
    };

    const toggleActive = (item: T): void => {
        router.put(
            `${baseUrl}/${item.id}`,
            {
                name: item.name,
                is_active: !item.is_active,
            },
            { preserveScroll: true },
        );
    };

    return {
        query,
        setQuery,
        sheetOpen,
        setSheetOpen,
        deleteOpen,
        setDeleteOpen,
        current,
        form: form as InertiaFormProps<MasterDataNameActiveFormData>,
        rows,
        openCreate,
        openEdit,
        submit,
        requestDelete,
        confirmDelete,
        toggleActive,
    };
}
