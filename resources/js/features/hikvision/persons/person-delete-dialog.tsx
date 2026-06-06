import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { HikvisionPerson } from './types';

export function HikvisionPersonDeleteDialog({
    open,
    onOpenChange,
    person,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    person: HikvisionPerson | null;
    onConfirm: () => void;
}) {
    return (
        <ConfirmDeleteDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Delete Hikvision person"
            description={
                person
                    ? `This will permanently delete “${person.full_name ?? person.person_code ?? 'this person'}” from Hik-Connect and remove the local record.`
                    : 'This will permanently delete this person from Hik-Connect.'
            }
            onConfirm={onConfirm}
        />
    );
}
