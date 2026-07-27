import { useState } from 'react';
import {
    useViewPreference,
    type ViewPreference,
} from '@/hooks/use-view-preference';

type UseOrganizationCrudListOptions = {
    viewKey?: string;
    defaultView?: ViewPreference;
};

export function useOrganizationCrudList<T extends { id: number }>({
    viewKey,
    defaultView = 'grid',
}: UseOrganizationCrudListOptions = {}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentEntity, setCurrentEntity] = useState<T | null>(null);

    const [view, setView] = useViewPreference(
        viewKey ?? '__organization_crud_list__',
        defaultView,
    );

    const openCreate = (reset: () => void): void => {
        setCurrentEntity(null);
        reset();
        setIsSheetOpen(true);
    };

    const openEdit = (entity: T, reset: () => void): void => {
        setCurrentEntity(entity);
        reset();
        setIsSheetOpen(true);
    };

    const openDelete = (entity: T): void => {
        setCurrentEntity(entity);
        setIsDeleteDialogOpen(true);
    };

    const closeSheet = (): void => {
        setIsSheetOpen(false);
    };

    const closeDeleteDialog = (): void => {
        setIsDeleteDialogOpen(false);
        setCurrentEntity(null);
    };

    const confirmDeleteFinish = (): void => {
        setIsDeleteDialogOpen(false);
        setCurrentEntity(null);
    };

    return {
        isSheetOpen,
        setIsSheetOpen,
        isDeleteDialogOpen,
        setIsDeleteDialogOpen,
        isFiltersOpen,
        setIsFiltersOpen,
        currentEntity,
        setCurrentEntity,
        openCreate,
        openEdit,
        openDelete,
        closeSheet,
        closeDeleteDialog,
        confirmDeleteFinish,
        view: viewKey ? view : undefined,
        setView: viewKey ? setView : undefined,
    };
}
