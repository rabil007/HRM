import { useForm, router } from '@inertiajs/react';
import type { ChangeEvent, ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/lib/toast';
import type {
    HikvisionPerson,
    HikvisionPersonFilterOption,
    HikvisionPersonFormData,
} from './types';

function buildInitialForm(person?: HikvisionPerson | null): HikvisionPersonFormData {
    return {
        first_name: person?.first_name ?? person?.full_name?.split(' ')[0] ?? '',
        last_name: person?.last_name ?? person?.full_name?.split(' ').slice(1).join(' ') ?? '',
        group_id: person?.group_id != null ? String(person.group_id) : '',
        person_code: person?.person_code ?? '',
        email: person?.email ?? '',
        phone: person?.phone ?? '',
    };
}

export function HikvisionPersonFormDialog({
    open,
    onOpenChange,
    person = null,
    groupOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    person?: HikvisionPerson | null;
    groupOptions: HikvisionPersonFilterOption[];
}): ReactElement {
    const isEdit = person !== null;
    const form = useForm<HikvisionPersonFormData>(buildInitialForm(person));
    const [uploadingPhoto, setUploadingPhoto] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.setData(buildInitialForm(person));
    }, [open, person]);

    const handlePhotoChange = (event: ChangeEvent<HTMLInputElement>): void => {
        const file = event.target.files?.[0];

        if (!file || !person) {
            return;
        }

        setUploadingPhoto(true);

        router.post(
            `/hikvision/persons/${person.id}/photo`,
            { photo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Person photo uploaded.');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.photo === 'string'
                            ? errors.photo
                            : 'Failed to upload person photo.';
                    toast.error(message);
                },
                onFinish: () => {
                    setUploadingPhoto(false);
                    event.target.value = '';
                },
            },
        );
    };

    const handleOpenChange = (nextOpen: boolean): void => {
        if (nextOpen) {
            form.clearErrors();
            form.setData(buildInitialForm(person));
        }

        onOpenChange(nextOpen);
    };

    const submit = (): void => {
        if (isEdit && person) {
            form.put(`/hikvision/persons/${person.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            });

            return;
        }

        form.post('/hikvision/persons', {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit person' : 'Add person'}</DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update this person in Hik-Connect. Changes sync to the access control system.'
                            : 'Create a new access-control person in Hik-Connect.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="person-first-name">First name</Label>
                            <Input
                                id="person-first-name"
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />
                            {form.errors.first_name ? (
                                <p className="text-xs text-destructive">{form.errors.first_name}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="person-last-name">Last name</Label>
                            <Input
                                id="person-last-name"
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                            />
                            {form.errors.last_name ? (
                                <p className="text-xs text-destructive">{form.errors.last_name}</p>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="person-group">Department</Label>
                        <AppSelect
                            value={form.data.group_id}
                            onValueChange={(value) => form.setData('group_id', value)}
                            variant="card"
                            placeholder="Select department"
                        >
                            <AppSelectItem value="">No department</AppSelectItem>
                            {groupOptions.map((option) => (
                                <AppSelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.group_id ? (
                            <p className="text-xs text-destructive">{form.errors.group_id}</p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="person-code">Employee no.</Label>
                        <Input
                            id="person-code"
                            value={form.data.person_code}
                            onChange={(event) => form.setData('person_code', event.target.value)}
                            readOnly={isEdit}
                            disabled={isEdit}
                        />
                        {isEdit ? (
                            <p className="text-xs text-muted-foreground">
                                Employee number is managed in Hik-Connect and cannot be changed here.
                            </p>
                        ) : null}
                        {form.errors.person_code ? (
                            <p className="text-xs text-destructive">{form.errors.person_code}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="person-email">Email</Label>
                            <Input
                                id="person-email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                            {form.errors.email ? (
                                <p className="text-xs text-destructive">{form.errors.email}</p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="person-phone">Phone</Label>
                            <Input
                                id="person-phone"
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                            />
                            {form.errors.phone ? (
                                <p className="text-xs text-destructive">{form.errors.phone}</p>
                            ) : null}
                        </div>
                    </div>

                    {form.errors.person ? (
                        <p className="text-xs text-destructive">{form.errors.person}</p>
                    ) : null}

                    {isEdit && person ? (
                        <div className="space-y-2">
                            <Label htmlFor="person-photo">Photo</Label>
                            <div className="flex items-center gap-3">
                                {person.photo_url ? (
                                    <img
                                        src={person.photo_url}
                                        alt=""
                                        className="h-12 w-12 rounded-full object-cover"
                                    />
                                ) : (
                                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-sm font-medium text-muted-foreground">
                                        {(person.full_name ?? '?').slice(0, 1).toUpperCase()}
                                    </div>
                                )}
                                <Input
                                    id="person-photo"
                                    type="file"
                                    accept="image/*"
                                    disabled={uploadingPhoto}
                                    onChange={handlePhotoChange}
                                />
                            </div>
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={form.processing}>
                        {form.processing ? <Spinner className="mr-2" /> : null}
                        {isEdit ? 'Save changes' : 'Create person'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
