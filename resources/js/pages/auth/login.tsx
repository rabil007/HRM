import { AtSign, Lock } from 'lucide-react';
import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

/* Reusable dark-glass input wrapper with a leading icon */
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
                    'group relative flex items-center overflow-hidden rounded-xl border bg-white/5 backdrop-blur-sm transition-all duration-200',
                    error
                        ? 'border-red-500/40 focus-within:border-red-500/70 focus-within:ring-2 focus-within:ring-red-500/20'
                        : 'border-white/10 focus-within:border-indigo-500/60 focus-within:ring-2 focus-within:ring-indigo-500/15',
                )}
            >
                {/* Icon column */}
                <div className="flex h-full shrink-0 items-center justify-center px-3.5 text-white/30 transition-colors group-focus-within:text-indigo-400">
                    {icon}
                </div>

                {/* Vertical separator */}
                <div className="h-5 w-px bg-white/10 group-focus-within:bg-indigo-500/30 transition-colors shrink-0" />

                {/* Input slot — full override of default styling */}
                <div className="flex-1 [&_input]:border-0 [&_input]:bg-transparent [&_input]:shadow-none [&_input]:ring-0 [&_input]:h-11 [&_input]:px-3.5 [&_input]:text-sm [&_input]:text-white/90 [&_input]:placeholder:text-white/25 [&_input]:outline-none [&_input]:focus-visible:ring-0 [&_input]:focus-visible:border-0 [&_button]:text-white/30 [&_button:hover]:text-white/70">
                    {children}
                </div>
            </div>
            {error && (
                <p className="flex items-center gap-1.5 text-xs text-red-400">
                    <svg viewBox="0 0 16 16" fill="currentColor" className="size-3.5 shrink-0">
                        <path fillRule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm0-9.5a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0v-4A.75.75 0 0 1 8 5.5Zm0 7a.875.875 0 1 0 0-1.75.875.875 0 0 0 0 1.75Z" clipRule="evenodd" />
                    </svg>
                    {error}
                </p>
            )}
        </div>
    );
}

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Sign in" />

            {/* Status banner */}
            {status && (
                <div className="mb-6 flex items-center gap-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                    <span className="relative flex h-2 w-2 shrink-0">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
                    </span>
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-3.5"
            >
                {({ processing, errors }) => (
                    <>
                        {/* ── Email ── */}
                        <div className="flex flex-col gap-1.5">
                            <label
                                htmlFor="email"
                                className="text-xs font-semibold tracking-wide text-white/50 uppercase"
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

                        {/* ── Password ── */}
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between">
                                <label
                                    htmlFor="password"
                                    className="text-xs font-semibold tracking-wide text-white/50 uppercase"
                                >
                                    Password
                                </label>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-xs font-medium text-indigo-400 hover:text-indigo-300 transition-colors"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
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

                        {/* ── Remember me ── */}
                        <div className="flex items-center gap-2.5 pt-1">
                            <Checkbox
                                id="remember"
                                name="remember"
                                tabIndex={3}
                                className="border-white/20 data-[state=checked]:bg-indigo-600 data-[state=checked]:border-indigo-600"
                            />
                            <label
                                htmlFor="remember"
                                className="cursor-pointer text-sm text-white/40 select-none"
                            >
                                Keep me signed in for 30 days
                            </label>
                        </div>

                        {/* ── Submit ── */}
                        <button
                            type="submit"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                            className={cn(
                                'relative mt-2 flex h-11 w-full items-center justify-center gap-2 overflow-hidden rounded-xl text-sm font-semibold text-white transition-all duration-200',
                                'bg-gradient-to-r from-indigo-600 to-violet-600',
                                'shadow-[0_0_24px_rgba(99,102,241,0.35)]',
                                'hover:from-indigo-500 hover:to-violet-500 hover:shadow-[0_0_32px_rgba(99,102,241,0.5)]',
                                'active:scale-[0.98]',
                                'disabled:opacity-60 disabled:pointer-events-none',
                                // shimmer sweep on hover
                                'before:absolute before:inset-0 before:-translate-x-full before:bg-gradient-to-r before:from-transparent before:via-white/10 before:to-transparent hover:before:translate-x-full before:transition-transform before:duration-700',
                            )}
                        >
                            {processing ? (
                                <>
                                    <Spinner className="text-white" />
                                    <span>Signing in…</span>
                                </>
                            ) : (
                                <>
                                    <span>Sign in</span>
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth={2} className="size-4 -mr-0.5 transition-transform group-hover:translate-x-0.5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 8h10M9 4l4 4-4 4" />
                                    </svg>
                                </>
                            )}
                        </button>
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
