import type { InertiaFormProps } from '@inertiajs/react';
import { ImageDown, Upload, UserRound } from 'lucide-react';
import { useEffect, useId, useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { employeesAvailableForUser, formatEmployeeLinkLabel } from '../lib/employees-for-user-link';
import type { EmployeeForLinking, User, UserFormData } from '../types';

export function UserFormSheet({
    open,
    onOpenChange,
    user,
    roles,
    employeesForLinking,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User | null;
    roles: { id: number; name: string }[];
    employeesForLinking: EmployeeForLinking[];
    form: InertiaFormProps<UserFormData>;
    onSubmit: () => void;
}) {
    const avatarId = useId();
    const userId = user?.id;

    const selectableEmployees = useMemo(
        () => employeesAvailableForUser(employeesForLinking, userId),
        [employeesForLinking, userId],
    );

    const selectedEmployee = useMemo(() => {
        if (!form.data.employee_id) {
            return null;
        }

        return employeesForLinking.find((employee) => employee.id === form.data.employee_id) ?? null;
    }, [employeesForLinking, form.data.employee_id]);

    const employeePhoto = selectedEmployee?.image_url ?? null;
    const canUseEmployeePhoto = Boolean(selectedEmployee?.image_url);

    const avatarFile = form.data.avatar;

    const uploadPreviewUrl = useMemo(() => {
        if (!avatarFile) {
            return null;
        }

        return URL.createObjectURL(avatarFile);
    }, [avatarFile]);

    useEffect(() => {
        if (!uploadPreviewUrl?.startsWith('blob:')) {
            return;
        }

        return () => URL.revokeObjectURL(uploadPreviewUrl);
    }, [uploadPreviewUrl]);

    const previewSrc =
        uploadPreviewUrl ??
        (form.data.use_employee_avatar && employeePhoto ? employeePhoto : null) ??
        user?.avatar ??
        null;

    const avatarFileName = avatarFile?.name?.trim();
    let avatarHint = 'Upload a profile image (optional).';

    if (uploadPreviewUrl && avatarFileName) {
        avatarHint = `New upload: ${avatarFileName}`;
    } else if (form.data.use_employee_avatar && selectedEmployee) {
        avatarHint = `Will copy photo from ${formatEmployeeLinkLabel(selectedEmployee)} when you save.`;
    } else if (user?.avatar) {
        avatarHint = 'Current profile photo. Upload a file or use the employee photo to replace it.';
    } else if (canUseEmployeePhoto) {
        avatarHint = 'No profile photo yet. Upload an image or use the linked employee photo.';
    } else if (!form.data.employee_id) {
        avatarHint = 'Link an employee below to enable “Use employee photo”, or upload an image.';
    }

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-lg p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">{user ? 'Edit User' : 'New User'}</SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {user ? 'Update user profile and access.' : 'Create a new user.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="status" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Status
                            </Label>
                            <AppSelect
                                value={form.data.status}
                                onValueChange={(v) => form.setData('status', v as UserFormData['status'])}
                                variant="card"
                            >
                                <AppSelectItem value="active">Active</AppSelectItem>
                                <AppSelectItem value="inactive">Inactive</AppSelectItem>
                                <AppSelectItem value="suspended">Suspended</AppSelectItem>
                            </AppSelect>
                            {form.errors.status ? <div className="text-xs font-medium text-destructive">{form.errors.status}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="employee_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Linked employee (optional)
                            </Label>
                            <AppSelect
                                value={form.data.employee_id === '' ? '' : String(form.data.employee_id)}
                                onValueChange={(value) => {
                                    const nextId = value ? Number(value) : '';
                                    const nextEmployee = value
                                        ? (employeesForLinking.find((e) => e.id === Number(value)) ?? null)
                                        : null;

                                    form.setData((data) => ({
                                        ...data,
                                        employee_id: nextId,
                                        use_employee_avatar:
                                            data.use_employee_avatar && Boolean(nextEmployee?.image_url),
                                    }));
                                }}
                                variant="card"
                                placeholder={
                                    selectableEmployees.length > 0
                                        ? 'No employee linked'
                                        : 'No employees available'
                                }
                            >
                                <AppSelectItem value="">No employee linked</AppSelectItem>
                                {selectableEmployees.map((employee) => (
                                    <AppSelectItem key={employee.id} value={String(employee.id)}>
                                        {formatEmployeeLinkLabel(employee)}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <p className="text-xs text-muted-foreground/80">
                                Map this login to an employee record. Only unlinked employees (or the current link) are listed.
                            </p>
                            {form.errors.employee_id ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.employee_id}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor={avatarId} className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Avatar (optional)
                            </Label>
                            <Input
                                id={avatarId}
                                type="file"
                                accept="image/*"
                                className="sr-only"
                                onChange={(e) => {
                                    const file = e.currentTarget.files?.[0] ?? null;
                                    form.setData((data) => ({
                                        ...data,
                                        avatar: file,
                                        use_employee_avatar: false,
                                    }));
                                }}
                            />
                            <div className="rounded-2xl border border-border/80 bg-card/50 p-4">
                                <div className="flex gap-4">
                                    <div
                                        className={cn(
                                            'relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-border/80 bg-muted/30',
                                            form.data.use_employee_avatar && 'ring-2 ring-primary/40 ring-offset-2 ring-offset-background',
                                        )}
                                    >
                                        {previewSrc ? (
                                            <img src={previewSrc} alt="" className="size-full object-cover" />
                                        ) : (
                                            <UserRound className="size-9 text-muted-foreground/50" />
                                        )}
                                    </div>

                                    <div className="flex min-w-0 flex-1 flex-col justify-center gap-3">
                                        <p className="text-xs leading-relaxed text-muted-foreground">{avatarHint}</p>
                                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                            <Button
                                                asChild
                                                type="button"
                                                variant="secondary"
                                                size="sm"
                                                className="h-9 rounded-xl px-3"
                                            >
                                                <label htmlFor={avatarId} className="cursor-pointer">
                                                    <Upload className="size-3.5" />
                                                    Upload photo
                                                </label>
                                            </Button>
                                            {canUseEmployeePhoto ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={form.data.use_employee_avatar ? 'default' : 'outline'}
                                                    className="h-9 rounded-xl px-3"
                                                    onClick={() => {
                                                        form.setData((data) => ({
                                                            ...data,
                                                            avatar: null,
                                                            use_employee_avatar: !data.use_employee_avatar,
                                                        }));
                                                    }}
                                                >
                                                    <ImageDown className="size-3.5" />
                                                    Use employee photo
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {form.errors.avatar ? <div className="text-xs font-medium text-destructive">{form.errors.avatar}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="role_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Role (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.role_id ?? '')}
                                onValueChange={(v) => form.setData('role_id', v ? Number(v) : '')}
                                variant="card"
                                placeholder="No role"
                            >
                                <AppSelectItem value="">No role</AppSelectItem>
                                {roles.map((r) => (
                                    <AppSelectItem key={r.id} value={String(r.id)}>
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.role_id ? <div className="text-xs font-medium text-destructive">{form.errors.role_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="John Doe"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="email" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Email
                            </Label>
                            <Input
                                id="email"
                                placeholder="user@company.com"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                            {form.errors.email ? <div className="text-xs font-medium text-destructive">{form.errors.email}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                {user ? 'Password (leave blank to keep current)' : 'Password'}
                            </Label>
                            <PasswordInput
                                id="password"
                                autoComplete="new-password"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground/70">
                                Use a long, random password with mixed case, numbers, and symbols.
                            </p>
                            {form.errors.password ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.password}</div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="password_confirmation"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                {user ? 'Confirm password' : 'Confirm password'}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                autoComplete="new-password"
                                className="rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            />
                            {form.errors.password_confirmation ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.password_confirmation}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-border/60 bg-background/40 flex gap-3">
                    <Button type="button" variant="ghost" className="rounded-xl h-11 px-6 text-muted-foreground flex-1" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button className="rounded-xl h-11 px-6 flex-1 font-semibold" type="button" onClick={onSubmit} disabled={form.processing}>
                        {user ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

