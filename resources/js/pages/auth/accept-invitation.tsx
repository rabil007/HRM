import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Lock, User as UserIcon } from 'lucide-react';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store } from '@/routes/invitations/accept';

type Props = {
    invitation: {
        email: string;
        name: string | null;
        company: string;
    };
    token: string;
    userExists: boolean;
};

const masterDataFieldLabelClass =
    'text-[10px] font-semibold uppercase tracking-wider text-muted-foreground';

function IconInput({
    icon,
    error,
    children,
}: {
    icon: React.ReactNode;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <div
                className={cn(
                    'group relative flex items-center overflow-hidden rounded-xl border bg-muted/30 transition-all duration-200',
                    error
                        ? 'border-destructive/40 focus-within:border-destructive focus-within:ring-2 focus-within:ring-destructive/20'
                        : 'border-border focus-within:border-primary/50 focus-within:ring-2 focus-within:ring-primary/20',
                )}
            >
                <div className="flex h-full shrink-0 items-center justify-center px-3.5 text-muted-foreground transition-colors duration-200 group-focus-within:text-primary">
                    {icon}
                </div>
                <div className="h-5 w-px shrink-0 bg-border transition-colors duration-200 group-focus-within:bg-primary/30" />
                <div className="min-w-0 flex-1 [&_button]:text-muted-foreground [&_button]:transition-colors [&_button:hover]:text-foreground [&_input]:h-11 [&_input]:w-full [&_input]:min-w-0 [&_input]:border-0 [&_input]:bg-transparent [&_input]:px-3.5 [&_input]:text-sm [&_input]:text-foreground [&_input]:input-autofill-reset [&_input]:shadow-none [&_input]:ring-0 [&_input]:outline-none [&_input]:placeholder:text-muted-foreground/60 [&_input]:focus-visible:border-0 [&_input]:focus-visible:ring-0 [&>div]:w-full">
                    {children}
                </div>
            </div>
            {error ? (
                <p className="text-xs font-medium text-destructive">{error}</p>
            ) : null}
        </div>
    );
}

export default function AcceptInvitation({ invitation, token, userExists }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        token: token,
        name: invitation.name || '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(store.url());
    };

    return (
        <>
            <Head title="Accept Invitation" />

            <div className="mb-6 rounded-xl border border-border bg-muted/30 p-4 text-center">
                <p className="text-sm text-muted-foreground">
                    You have been invited to join <span className="font-semibold text-foreground">{invitation.company}</span>
                    <br />
                    as <span className="font-semibold text-foreground">{invitation.email}</span>
                </p>
            </div>

            <form
                onSubmit={submit}
                className="flex flex-col gap-4"
            >
                {!userExists && (
                    <>
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="name"
                                className={masterDataFieldLabelClass}
                            >
                                Your Name
                            </label>
                            <IconInput
                                icon={<UserIcon className="size-4" />}
                                error={errors.name}
                            >
                                <input
                                    id="name"
                                    type="text"
                                    name="name"
                                    required
                                    autoFocus
                                    placeholder="Jane Doe"
                                    className="w-full"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                            </IconInput>
                        </div>

                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="password"
                                className={masterDataFieldLabelClass}
                            >
                                Create Password
                            </label>
                            <IconInput
                                icon={<Lock className="size-4" />}
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    placeholder="••••••••"
                                    value={data.password}
                                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => setData('password', e.target.value)}
                                />
                            </IconInput>
                        </div>

                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="password_confirmation"
                                className={masterDataFieldLabelClass}
                            >
                                Confirm Password
                            </label>
                            <IconInput
                                icon={<Lock className="size-4" />}
                            >
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    placeholder="••••••••"
                                    value={data.password_confirmation}
                                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => setData('password_confirmation', e.target.value)}
                                />
                            </IconInput>
                        </div>
                    </>
                )}

                <div className="h-px bg-border/60 my-2" />

                <Button
                    type="submit"
                    disabled={processing}
                    className="h-11 w-full rounded-xl font-semibold"
                >
                    {processing ? (
                        <>
                            <Spinner />
                            Accepting...
                        </>
                    ) : (
                        <>
                            Accept Invitation
                            <ArrowRight className="size-4" />
                        </>
                    )}
                </Button>
            </form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Accept Invitation',
    description: 'Complete your account setup to get started',
};
