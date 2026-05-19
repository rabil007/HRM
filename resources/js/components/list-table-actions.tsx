import type { MouseEvent } from 'react';
import { TableRowActions, type TableRowActionItem } from '@/components/table-row-actions';

type ListTableCrudActionsProps = {
    onView?: (event: MouseEvent<HTMLButtonElement>) => void;
    viewHref?: string;
    onEdit?: (event: MouseEvent<HTMLButtonElement>) => void;
    onDelete?: (event: MouseEvent<HTMLButtonElement>) => void;
    showView?: boolean;
    showEdit?: boolean;
    showDelete?: boolean;
};

/** Standard View / Edit / Delete text actions for organization list tables. */
export function ListTableCrudActions({
    onView,
    viewHref,
    onEdit,
    onDelete,
    showView = true,
    showEdit = true,
    showDelete = true,
}: ListTableCrudActionsProps) {
    const actions: TableRowActionItem[] = [
        {
            label: 'View',
            variant: 'primary',
            href: viewHref,
            onClick: onView,
            hidden: !showView || (!viewHref && !onView),
        },
        {
            label: 'Edit',
            onClick: onEdit,
            hidden: !showEdit || !onEdit,
        },
        {
            label: 'Delete',
            variant: 'danger',
            onClick: onDelete,
            hidden: !showDelete || !onDelete,
        },
    ];

    return <TableRowActions actions={actions} />;
}
