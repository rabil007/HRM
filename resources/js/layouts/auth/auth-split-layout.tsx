import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/components/application-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const features = [
    {
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="size-5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
        ),
        title: 'Employee Management',
        description: 'Centralize records, contracts & org charts',
    },
    {
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="size-5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
        ),
        title: 'Leave & Attendance',
        description: 'Smart scheduling with real-time tracking',
    },
    {
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="size-5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
            </svg>
        ),
        title: 'Payroll & Benefits',
        description: 'Automated payroll with tax compliance',
    },
    {
        icon: (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="size-5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
            </svg>
        ),
        title: 'Analytics & Reports',
        description: 'Insights to drive workforce decisions',
    },
];

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    const { name, settings } = usePage().props;
    const loginBackground = settings?.branding?.login_background_url;

    return (
        <div className="relative flex min-h-dvh w-full overflow-hidden bg-[#06060f]">
            {/* ── Left panel ── */}
            <div className="relative hidden lg:flex lg:w-[52%] xl:w-[55%] flex-col justify-between overflow-hidden p-10 xl:p-14">
                {/* Background layers */}
                <div className="pointer-events-none absolute inset-0" aria-hidden>
                    {/* Base gradient */}
                    <div className="absolute inset-0 bg-gradient-to-br from-indigo-950 via-[#0c0c22] to-violet-950" />
                    {loginBackground ? (
                        <div
                            className="absolute inset-0 bg-cover bg-center opacity-30"
                            style={{ backgroundImage: `url(${loginBackground})` }}
                        />
                    ) : null}
                    {/* Animated blobs */}
                    <div className="absolute -top-24 -left-24 h-[500px] w-[500px] rounded-full bg-indigo-600/20 blur-[120px] animate-pulse" style={{ animationDuration: '6s' }} />
                    <div className="absolute bottom-0 right-0 h-[400px] w-[400px] rounded-full bg-violet-600/15 blur-[100px] animate-pulse" style={{ animationDuration: '8s', animationDelay: '2s' }} />
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[300px] w-[300px] rounded-full bg-indigo-500/10 blur-[80px] animate-pulse" style={{ animationDuration: '10s', animationDelay: '1s' }} />
                    {/* Grid pattern */}
                    <div
                        className="absolute inset-0 opacity-[0.035]"
                        style={{
                            backgroundImage:
                                'linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px)',
                            backgroundSize: '48px 48px',
                        }}
                    />
                    {/* Vignette overlay */}
                    <div className="absolute inset-0 bg-gradient-to-r from-transparent via-transparent to-[#06060f]/60" />
                </div>

                {/* Logo */}
                <Link
                    href={home()}
                    className="relative z-10 flex items-center gap-3 w-fit transition-opacity hover:opacity-80"
                >
                    <div className="flex size-10 items-center justify-center rounded-xl bg-indigo-600 shadow-[0_0_24px_rgba(99,102,241,0.5)]">
                        <ApplicationLogo variant="login" imageClassName="h-6 w-auto max-w-[120px]" iconClassName="size-5 text-white" />
                    </div>
                    <span className="text-base font-bold tracking-tight text-white">{name}</span>
                </Link>

                {/* Hero content */}
                <div className="relative z-10 flex flex-col gap-10">
                    <div className="flex flex-col gap-5">
                        {/* Badge */}
                        <div className="inline-flex w-fit items-center gap-2 rounded-full border border-indigo-500/25 bg-indigo-500/10 px-3 py-1.5">
                            <span className="relative flex size-1.5">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75" />
                                <span className="relative inline-flex size-1.5 rounded-full bg-indigo-400" />
                            </span>
                            <span className="text-[10px] font-bold tracking-[0.15em] text-indigo-300 uppercase">
                                HR & Workforce Platform
                            </span>
                        </div>

                        <div>
                            <h2 className="text-4xl xl:text-5xl font-bold leading-[1.1] tracking-tight text-white">
                                Manage your team
                                <br />
                                <span className="bg-gradient-to-r from-indigo-400 to-violet-400 bg-clip-text text-transparent">
                                    with confidence.
                                </span>
                            </h2>
                            <p className="mt-4 text-base text-white/45 leading-relaxed max-w-sm">
                                A complete HR system built for modern organizations — from onboarding to payroll, all in one place.
                            </p>
                        </div>
                    </div>

                    {/* Feature cards */}
                    <div className="grid grid-cols-2 gap-3">
                        {features.map((feature) => (
                            <div
                                key={feature.title}
                                className="group flex flex-col gap-3 rounded-2xl border border-white/6 bg-white/4 p-4 backdrop-blur-sm transition-all duration-300 hover:border-indigo-500/30 hover:bg-white/6"
                            >
                                <div className="flex size-9 items-center justify-center rounded-lg bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/20 transition-colors group-hover:bg-indigo-500/25">
                                    {feature.icon}
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-white/80">{feature.title}</p>
                                    <p className="mt-0.5 text-xs text-white/35 leading-relaxed">{feature.description}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Bottom testimonial / trust badge */}
                <div className="relative z-10 flex items-center justify-between border-t border-white/8 pt-6">
                    <div className="flex items-center gap-3">
                        {/* Avatar stack */}
                        <div className="flex -space-x-2">
                            {['bg-indigo-500', 'bg-violet-500', 'bg-pink-500', 'bg-blue-500'].map((bg, i) => (
                                <div
                                    key={i}
                                    className={`flex size-7 items-center justify-center rounded-full ${bg} ring-2 ring-[#0c0c22] text-[10px] font-bold text-white`}
                                >
                                    {String.fromCharCode(65 + i)}
                                </div>
                            ))}
                        </div>
                        <div>
                            <p className="text-xs font-semibold text-white/60">Trusted by teams worldwide</p>
                            <p className="text-[10px] text-white/30">Enterprise-grade security & encryption</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-1">
                        {[1, 2, 3, 4, 5].map((s) => (
                            <svg key={s} viewBox="0 0 16 16" fill="currentColor" className="size-3 text-amber-400">
                                <path d="M7.657 1.25a.4.4 0 0 1 .686 0l1.718 2.923 3.277.556a.4.4 0 0 1 .214.667L11.1 7.702l.438 3.29a.4.4 0 0 1-.569.41L8 9.943l-2.97 1.458a.4.4 0 0 1-.569-.41l.438-3.29L2.448 5.396a.4.4 0 0 1 .214-.667l3.277-.556L7.657 1.25Z" />
                            </svg>
                        ))}
                        <span className="ml-1 text-[10px] font-semibold text-white/40">4.9</span>
                    </div>
                </div>
            </div>

            {/* ── Right panel (form) ── */}
            <div className="relative flex flex-1 flex-col items-center justify-center px-5 py-10 sm:px-8">
                {/* Background glow for right side */}
                <div className="pointer-events-none absolute inset-0" aria-hidden>
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[400px] w-[400px] rounded-full bg-indigo-600/12 blur-[100px]" />
                </div>

                {/* Mobile-only logo */}
                <Link
                    href={home()}
                    className="relative z-10 mb-8 flex flex-col items-center gap-3 transition-opacity hover:opacity-80 lg:hidden"
                >
                    <div className="flex size-10 items-center justify-center rounded-xl bg-indigo-600 shadow-[0_0_24px_rgba(99,102,241,0.5)]">
                        <ApplicationLogo variant="login" imageClassName="h-6 w-auto max-w-[120px]" iconClassName="size-5 text-white" />
                    </div>
                    <span className="text-sm font-bold tracking-tight text-white/80">{name}</span>
                </Link>

                {/* Form card */}
                <div
                    className="auth-fade-up relative z-10 w-full max-w-[400px]"
                    style={{ animationDelay: '0.05s' }}
                >
                    {/* Header */}
                    <div className="mb-7 text-center">
                        <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-indigo-500/20 bg-indigo-500/8 px-3 py-1 lg:hidden">
                            <span className="relative flex size-1.5">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75" />
                                <span className="relative inline-flex size-1.5 rounded-full bg-indigo-400" />
                            </span>
                            <span className="text-[10px] font-bold tracking-[0.12em] text-indigo-300 uppercase">
                                HR & Workforce
                            </span>
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight text-white">{title}</h1>
                        <p className="mt-1.5 text-sm text-white/40">{description}</p>
                    </div>

                    {/* Card */}
                    <div
                        className="rounded-2xl p-px"
                        style={{
                            background:
                                'linear-gradient(145deg, rgba(99,102,241,0.5) 0%, rgba(139,92,246,0.25) 40%, rgba(255,255,255,0.06) 100%)',
                        }}
                    >
                        <div className="relative rounded-[calc(1rem-1px)] bg-[#0d0d1e] px-6 py-7 backdrop-blur-2xl sm:px-7">
                            {/* Inner glow top */}
                            <div className="pointer-events-none absolute inset-0 rounded-[calc(1rem-1px)] bg-gradient-to-b from-white/[0.05] to-transparent" />
                            {/* Inner glow corner */}
                            <div className="pointer-events-none absolute -top-px left-10 right-10 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent" />
                            <div className="relative z-10">{children}</div>
                        </div>
                    </div>

                    <p className="mt-5 text-center text-[11px] text-white/20 select-none">
                        Protected by enterprise-grade security &amp; encryption
                    </p>
                </div>
            </div>
        </div>
    );
}
