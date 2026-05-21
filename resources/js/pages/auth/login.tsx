import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <div className="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-950/30 dark:text-emerald-400">
                    <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" />
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        {/* Email */}
                        <div className="grid gap-2">
                            <Label
                                htmlFor="email"
                                className="text-sm font-medium"
                            >
                                Email address
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="you@company.com"
                                className="h-11 rounded-xl text-sm transition-shadow focus-visible:shadow-md"
                            />
                            <InputError message={errors.email} />
                        </div>

                        {/* Password */}
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label
                                    htmlFor="password"
                                    className="text-sm font-medium"
                                >
                                    Password
                                </Label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="••••••••"
                                className="h-11 rounded-xl text-sm transition-shadow focus-visible:shadow-md"
                            />
                            <InputError message={errors.password} />
                        </div>

                        {/* Remember me */}
                        <div className="flex items-center gap-2.5">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <Label
                                htmlFor="remember"
                                className="cursor-pointer text-sm font-normal text-muted-foreground"
                            >
                                Keep me signed in
                            </Label>
                        </div>

                        {/* Submit */}
                        <Button
                            type="submit"
                            className="mt-2 h-11 w-full rounded-xl text-sm font-semibold shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            {processing ? 'Signing in…' : 'Sign in'}
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
