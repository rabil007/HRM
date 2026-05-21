import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            {/* ── Left decorative panel (desktop only) ── */}
            <div className="relative hidden h-full flex-col overflow-hidden lg:flex">
                {/* Background image */}
                <div
                    className="absolute inset-0 bg-cover bg-center"
                    style={{ backgroundImage: 'url(/images/login-bg.png)' }}
                />

                {/* Dark overlay with gradient */}
                <div className="absolute inset-0 bg-gradient-to-br from-black/70 via-black/50 to-transparent" />

                {/* Animated accent orbs */}
                <div className="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-primary/20 blur-3xl animate-pulse" />
                <div
                    className="absolute bottom-0 right-0 h-80 w-80 rounded-full bg-blue-500/15 blur-3xl animate-pulse"
                    style={{ animationDelay: '1.5s' }}
                />
                <div
                    className="absolute top-1/2 left-1/3 h-64 w-64 rounded-full bg-indigo-500/10 blur-3xl animate-pulse"
                    style={{ animationDelay: '0.75s' }}
                />

                {/* Content */}
                <div className="relative z-20 flex h-full flex-col p-10">
                    {/* Logo & app name */}
                    <Link
                        href={home()}
                        className="flex items-center gap-3 text-xl font-semibold tracking-tight text-white transition-opacity hover:opacity-80"
                    >
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 backdrop-blur-sm ring-1 ring-white/20 shadow-lg">
                            <AppLogoIcon className="size-6 fill-current text-white" />
                        </div>
                        <span className="drop-shadow-md">{name}</span>
                    </Link>

                    {/* Centre badge */}
                    <div className="flex flex-1 items-center justify-start">
                        <div className="max-w-sm space-y-4">
                            <div className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-1.5 backdrop-blur-sm">
                                <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />
                                <span className="text-xs font-medium tracking-wide text-white/90 uppercase">
                                    HR Management Platform
                                </span>
                            </div>
                            <h2 className="text-4xl font-bold leading-tight text-white drop-shadow-md">
                                Manage your workforce,{' '}
                                <span className="bg-gradient-to-r from-blue-300 to-indigo-300 bg-clip-text text-transparent">
                                    effortlessly.
                                </span>
                            </h2>
                            <p className="text-base text-white/70 leading-relaxed">
                                Streamline HR operations, track employee performance, and drive organisational growth — all in one place.
                            </p>
                        </div>
                    </div>

                    {/* Stats cards row */}
                    <div className="grid grid-cols-3 gap-3">
                        {[
                            { label: 'Employees', value: '500+', icon: '👥' },
                            { label: 'Departments', value: '24', icon: '🏢' },
                            { label: 'Uptime', value: '99.9%', icon: '⚡' },
                        ].map((stat) => (
                            <div
                                key={stat.label}
                                className="rounded-2xl border border-white/10 bg-white/8 p-4 backdrop-blur-md transition-all hover:bg-white/12 hover:border-white/20"
                            >
                                <div className="text-xl mb-1">{stat.icon}</div>
                                <div className="text-2xl font-bold text-white">
                                    {stat.value}
                                </div>
                                <div className="text-xs text-white/60 mt-0.5">
                                    {stat.label}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Quote */}
                    <blockquote className="mt-6 border-l-2 border-white/30 pl-4">
                        <p className="text-sm text-white/70 leading-relaxed italic">
                            "The platform that helps us focus on people, not paperwork."
                        </p>
                        <footer className="mt-1 text-xs font-semibold text-white/50 uppercase tracking-wider">
                            — The {name} Team
                        </footer>
                    </blockquote>
                </div>
            </div>

            {/* ── Right form panel ── */}
            <div className="relative flex h-full w-full flex-col items-center justify-center bg-background px-6 lg:px-12">
                {/* Subtle background pattern */}
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,hsl(var(--primary)/0.08),transparent)] dark:bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,hsl(var(--primary)/0.15),transparent)]" />

                <div className="relative z-10 mx-auto w-full max-w-[400px] space-y-8">
                    {/* Mobile logo */}
                    <Link
                        href={home()}
                        className="relative z-20 mx-auto flex w-fit items-center gap-3 lg:hidden"
                    >
                        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-foreground shadow-md">
                            <AppLogoIcon className="size-6 fill-current text-background" />
                        </div>
                        <span className="text-lg font-semibold tracking-tight">{name}</span>
                    </Link>

                    {/* Heading */}
                    <div className="flex flex-col gap-2 text-center">
                        <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
                        <p className="text-sm text-muted-foreground">{description}</p>
                    </div>

                    {/* Form card */}
                    <div className="rounded-2xl border border-border/60 bg-card px-8 py-9 shadow-sm ring-1 ring-black/[0.04] dark:ring-white/[0.04]">
                        {children}
                    </div>

                    {/* Footer note */}
                    <p className="text-center text-xs text-muted-foreground/70 select-none">
                        By continuing, you agree to our{' '}
                        <span className="underline underline-offset-2 cursor-pointer hover:text-foreground transition-colors">
                            Terms of Service
                        </span>{' '}
                        and{' '}
                        <span className="underline underline-offset-2 cursor-pointer hover:text-foreground transition-colors">
                            Privacy Policy
                        </span>
                        .
                    </p>
                </div>
            </div>
        </div>
    );
}
