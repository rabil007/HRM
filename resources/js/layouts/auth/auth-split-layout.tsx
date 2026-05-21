import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/components/application-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    const { name, settings } = usePage().props;
    const loginBackground = settings?.branding?.login_background_url;

    return (
        <div className="relative flex h-dvh min-h-dvh w-full flex-col overflow-hidden bg-background">
            {/* Atmospheric full-screen background */}
            <div className="pointer-events-none absolute inset-0" aria-hidden>
                <div className="absolute inset-0 bg-background" />
                {loginBackground ? (
                    <div
                        className="absolute inset-0 bg-cover bg-center opacity-[0.18]"
                        style={{ backgroundImage: `url(${loginBackground})` }}
                    />
                ) : null}
                <div className="absolute top-1/2 left-1/2 h-[min(80vh,720px)] w-[min(90vw,900px)] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/10 blur-[120px]" />
                <div className="absolute top-1/2 left-1/2 h-[min(60vh,520px)] w-[min(70vw,640px)] -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent/8 blur-[100px]" />
                <div
                    className="absolute inset-0 opacity-[0.025]"
                    style={{
                        backgroundImage:
                            'linear-gradient(var(--border) 1px, transparent 1px), linear-gradient(90deg, var(--border) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                    }}
                />
                <div className="absolute inset-0 bg-gradient-to-b from-background/20 via-transparent to-background/80" />
            </div>

            {/* Centered login — full screen, one column */}
            <div className="relative z-10 flex min-h-0 flex-1 flex-col">
                <header className="flex shrink-0 items-center justify-center px-6 pt-8 sm:pt-10">
                    <Link
                        href={home()}
                        className="flex items-center gap-3 transition-opacity hover:opacity-80"
                    >
                        <div className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm ring-1 ring-primary/20">
                            <ApplicationLogo
                                variant="login"
                                imageClassName="h-7 w-auto max-w-[140px]"
                                iconClassName="size-5"
                            />
                        </div>
                        <span className="text-lg font-bold tracking-tight text-foreground">{name}</span>
                    </Link>
                </header>

                <main className="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto px-6 py-8 sm:px-8">
                    <div
                        className="auth-fade-up w-full max-w-[420px]"
                        style={{ animationDelay: '0.05s' }}
                    >
                        <div className="mb-8 text-center">
                            <p className="mb-3 text-[10px] font-bold tracking-[0.2em] text-primary uppercase">
                                HR & Workforce Platform
                            </p>
                            <h1 className="text-3xl font-bold tracking-tight text-foreground">{title}</h1>
                            <p className="mt-2 text-sm text-muted-foreground">{description}</p>
                        </div>

                        <div className="glass-card rounded-2xl border border-border/80 p-6 shadow-lg sm:p-8">
                            {children}
                        </div>
                    </div>
                </main>

                <footer className="shrink-0 px-6 pb-6 text-center sm:pb-8">
                    <p className="text-[11px] text-muted-foreground/70 select-none">
                        Protected by enterprise-grade security &amp; encryption
                    </p>
                </footer>
            </div>
        </div>
    );
}
