import { Form, Head } from '@inertiajs/react';
import { ArrowRight, AtSign, Lock } from 'lucide-react';
import { useState } from 'react';
import PasswordInput from '@/components/password-input';
import { masterDataFieldLabelClass } from '@/components/settings/master-data-form-sheet';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

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
                <div className="flex-1 [&_button]:text-muted-foreground [&_button]:transition-colors [&_button:hover]:text-foreground [&_input]:h-11 [&_input]:border-0 [&_input]:bg-transparent [&_input]:px-3.5 [&_input]:text-sm [&_input]:text-foreground [&_input]:input-autofill-reset [&_input]:shadow-none [&_input]:ring-0 [&_input]:outline-none [&_input]:placeholder:text-muted-foreground/60 [&_input]:focus-visible:border-0 [&_input]:focus-visible:ring-0">
                    {children}
                </div>
            </div>
            {error ? (
                <p className="text-xs font-medium text-destructive">{error}</p>
            ) : null}
        </div>
    );
}

export default function Login({ status, canResetPassword }: Props) {
    const [remember, setRemember] = useState(false);

    return (
        <>
            <Head title="Sign in" />

            {status ? (
                <div className="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">
                    <span className="relative flex size-2 shrink-0">
                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" />
                        <span className="relative inline-flex size-2 rounded-full bg-success" />
                    </span>
                    {status}
                </div>
            ) : null}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="email"
                                className={masterDataFieldLabelClass}
                            >
                                Email address
                            </label>
                            <IconInput
                                icon={<AtSign className="size-4" />}
                                error={errors.email}
                            >
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="you@company.com"
                                />
                            </IconInput>
                        </div>

                        <div className="flex flex-col gap-2">
                            <div className="flex items-center justify-between">
                                <label
                                    htmlFor="password"
                                    className={masterDataFieldLabelClass}
                                >
                                    Password
                                </label>
                                {canResetPassword ? (
                                    <TextLink
                                        href={request()}
                                        className="text-xs font-medium text-primary hover:text-primary/80"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                ) : null}
                            </div>
                            <IconInput
                                icon={<Lock className="size-4" />}
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="••••••••"
                                />
                            </IconInput>
                        </div>

                        <div className="flex items-center gap-2.5 pt-0.5">
                            <Checkbox
                                id="remember"
                                checked={remember}
                                tabIndex={3}
                                className="border-border data-[state=checked]:border-primary data-[state=checked]:bg-primary"
                                onCheckedChange={(checked) =>
                                    setRemember(checked === true)
                                }
                            />
                            {remember ? (
                                <input
                                    type="hidden"
                                    name="remember"
                                    value="1"
                                />
                            ) : null}
                            <label
                                htmlFor="remember"
                                className="cursor-pointer text-sm text-muted-foreground select-none"
                            >
                                Keep me signed in for 30 days
                            </label>
                        </div>

                        <div className="h-px bg-border/60" />

                        <Button
                            type="submit"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                            className="h-11 w-full rounded-xl font-semibold"
                        >
                            {processing ? (
                                <>
                                    <Spinner />
                                    Signing in…
                                </>
                            ) : (
                                <>
                                    Sign in
                                    <ArrowRight className="size-4" />
                                </>
                            )}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Sign in to your account to continue',
};
