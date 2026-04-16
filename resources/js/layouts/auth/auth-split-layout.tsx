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
            <div className="relative hidden h-full flex-col p-10 text-white lg:flex dark:border-r overflow-hidden">
                <div 
                    className="absolute inset-0 bg-zinc-900 bg-cover bg-center" 
                    style={{ backgroundImage: 'url(/images/login-bg.png)' }}
                >
                    <div className="absolute inset-0 bg-black/40 backdrop-blur-[2px]" />
                </div>
                
                <Link
                    href={home()}
                    className="relative z-20 flex items-center text-xl font-semibold tracking-tight transition-all hover:opacity-80"
                >
                    <AppLogoIcon className="mr-3 size-10 fill-current text-white drop-shadow-md" />
                    {name}
                </Link>

                <div className="relative z-20 mt-auto">
                    <blockquote className="space-y-4">
                        <p className="text-lg text-white/90 leading-relaxed font-light tracking-wide">
                            "Streamline your workforce management with an intuitive platform designed to empower your team and drive growth."
                        </p>
                        <footer className="text-sm text-white/70 font-medium">
                            The {name} Team
                        </footer>
                    </blockquote>
                </div>
            </div>
            
            <div className="w-full lg:p-8">
                <div className="mx-auto flex w-full flex-col justify-center space-y-8 sm:w-[400px]">
                    <div className="flex flex-col items-center gap-6">
                        <Link
                            href={home()}
                            className="relative z-20 flex items-center justify-center lg:hidden"
                        >
                            <AppLogoIcon className="h-12 w-auto fill-current text-[var(--foreground)] sm:h-14" />
                        </Link>
                        <div className="flex flex-col items-center gap-3 text-center">
                            <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
                            <p className="text-base text-balance text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    
                    <div className="bg-card w-full rounded-2xl border bg-card/50 px-8 py-10 shadow-sm backdrop-blur-sm sm:px-10">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
