import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="relative flex min-h-dvh w-full flex-col items-center justify-center overflow-x-hidden bg-[#080810] px-4 py-8 sm:px-6">
            <div className="pointer-events-none absolute inset-0" aria-hidden>
                <div className="absolute -top-32 left-1/2 h-[420px] w-[420px] -translate-x-1/2 rounded-full bg-indigo-600/25 blur-[100px]" />
                <div className="absolute -bottom-24 right-1/4 h-[320px] w-[320px] rounded-full bg-violet-700/15 blur-[90px]" />
            </div>

            <div
                className="auth-fade-up relative z-10 flex w-full max-w-[420px] flex-col"
                style={{ animationDelay: '0.1s' }}
            >
                <Link
                    href={home()}
                    className="mb-8 flex flex-col items-center gap-3 transition-opacity hover:opacity-80"
                >
                    <div className="flex size-10 items-center justify-center rounded-xl bg-white/8 ring-1 ring-white/10 backdrop-blur-md">
                        <AppLogoIcon className="size-5 fill-current text-white" />
                    </div>
                    <span className="text-sm font-semibold tracking-tight text-white/70">{name}</span>
                </Link>

                <div className="mb-6 text-center">
                    <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-indigo-500/20 bg-indigo-500/8 px-3 py-1">
                        <span className="relative flex size-1.5">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-indigo-400" />
                        </span>
                        <span className="text-[10px] font-bold tracking-[0.12em] text-indigo-300 uppercase">
                            HR &amp; Workforce
                        </span>
                    </div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">{title}</h1>
                    <p className="mt-1.5 text-sm text-white/40">{description}</p>
                </div>

                <div
                    className="rounded-2xl p-px"
                    style={{
                        background:
                            'linear-gradient(140deg, rgba(99,102,241,0.45) 0%, rgba(139,92,246,0.2) 50%, rgba(255,255,255,0.05) 100%)',
                    }}
                >
                    <div className="relative rounded-[calc(1rem-1px)] bg-[#0d0d1e] px-6 py-7 backdrop-blur-2xl sm:px-7">
                        <div className="pointer-events-none absolute inset-0 rounded-[calc(1rem-1px)] bg-linear-to-b from-white/4 to-transparent" />
                        <div className="relative z-10">{children}</div>
                    </div>
                </div>

                <p className="mt-6 text-center text-[11px] text-white/25 select-none">
                    Protected by enterprise-grade security &amp; encryption
                </p>
            </div>
        </div>
    );
}
