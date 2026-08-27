import { useForm } from '@inertiajs/react';
import { Mail, CheckCircle2 } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { store as storeInvitation } from '@/routes/organization/user-invitations';

type InviteFormData = {
    email: string;
    name: string;
    role_id: number | '';
};

export function UserInvitationSheet({
    open,
    onOpenChange,
    roles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roles: { id: number; name: string }[];
}) {
    const form = useForm<InviteFormData>({
        email: '',
        name: '',
        role_id: '',
    });

    useEffect(() => {
        if (!open) {
            form.reset();
            form.clearErrors();
        }
    }, [open]);

    const onSubmit = () => {
        form.post(storeInvitation.url(), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Invitation sent successfully', {
                    icon: <CheckCircle2 className="size-5 text-emerald-500" />,
                });
                onOpenChange(false);
            },
            onError: () => {
                toast.error('Failed to send invitation');
            },
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                className="flex w-full flex-col p-0 sm:max-w-md border-l-0 shadow-2xl overflow-y-auto overflow-x-hidden"
                side="right"
            >
                <div className="flex items-center justify-between border-b border-border/60 bg-muted/20 p-6">
                    <SheetHeader className="space-y-1 text-left">
                        <SheetTitle className="flex items-center gap-2 text-xl font-bold tracking-tight">
                            <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Mail className="size-4" />
                            </div>
                            Invite User
                        </SheetTitle>
                    </SheetHeader>
                </div>

                <div className="flex-1 space-y-8 p-6">
                    <div className="space-y-6">
                        <div className="space-y-2">
                            <Label
                                htmlFor="email"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Email Address <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="email"
                                placeholder="jane@example.com"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                            {form.errors.email ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.email}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Name (Optional)
                            </Label>
                            <Input
                                id="name"
                                placeholder="Jane Doe"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                            <p className="text-xs text-muted-foreground">
                                Providing a name helps personalize the invitation email.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="role_id"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Assign Role (optional)
                            </Label>
                            <AppSelect
                                value={String(form.data.role_id ?? '')}
                                onValueChange={(v: string) =>
                                    form.setData('role_id', v ? Number(v) : '')
                                }
                                variant="card"
                                placeholder="No initial role"
                            >
                                <AppSelectItem value="">No role</AppSelectItem>
                                {roles.map((r) => (
                                    <AppSelectItem
                                        key={r.id}
                                        value={String(r.id)}
                                    >
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.role_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.role_id}
                                </div>
                            ) : null}
                        </div>

                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        Send Invitation
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
