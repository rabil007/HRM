import { Head } from '@inertiajs/react';
import { ApplicationBrandingSync } from '@/components/application-branding-sync';
import { ErrorPageActions } from '@/components/errors/error-page-actions';
import { ErrorPageIllustration } from '@/components/errors/error-page-illustration';
import type { ErrorPageConfig } from '@/lib/errors/error-page-config';

export function ErrorPage({
    config,
    retryAfterSeconds = null,
}: {
    config: ErrorPageConfig;
    retryAfterSeconds?: number | null;
}) {
    return (
        <>
            <Head title={config.title} />
            <ApplicationBrandingSync />
            <div className="flex min-h-dvh w-full flex-col overflow-x-hidden bg-background px-6 py-10 sm:py-16">
                <main
                    className="mx-auto flex w-full max-w-lg flex-1 flex-col items-center justify-center text-center"
                    role="alert"
                    aria-labelledby="error-page-heading"
                    aria-describedby="error-page-description"
                >
                    <ErrorPageIllustration
                        icon={config.icon}
                        label={config.statusLabel}
                    />
                    <p className="mt-6 text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                        {config.statusLabel}
                    </p>
                    <h1
                        id="error-page-heading"
                        className="mt-3 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {config.heading}
                    </h1>
                    <p
                        id="error-page-description"
                        className="mt-3 text-sm leading-relaxed text-muted-foreground"
                    >
                        {config.description}
                    </p>
                    <div className="mt-8 w-full">
                        <ErrorPageActions
                            key={String(retryAfterSeconds ?? 'none')}
                            actions={config.actions}
                            retryAfterSeconds={retryAfterSeconds}
                        />
                    </div>
                </main>
            </div>
        </>
    );
}
