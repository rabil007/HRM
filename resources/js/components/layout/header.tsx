import { useEffect, useRef } from 'react';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

type HeaderProps = React.HTMLAttributes<HTMLElement> & {
    fixed?: boolean;
    ref?: React.Ref<HTMLElement>;
};

export function Header({ className, fixed, children, ...props }: HeaderProps) {
    const headerRef = useRef<HTMLElement | null>(null);

    useEffect(() => {
        const el = headerRef.current;

        if (!el || !fixed) {
            return;
        }

        let rafId: number | null = null;
        let lastScrolled = false;

        const update = () => {
            rafId = null;

            const offset =
                document.documentElement.scrollTop ||
                document.body.scrollTop ||
                0;
            const scrolled = offset > 10;

            if (scrolled === lastScrolled) {
                return;
            }

            lastScrolled = scrolled;

            if (scrolled) {
                el.dataset.scrolled = 'true';
            } else {
                delete el.dataset.scrolled;
            }
        };

        const onScroll = () => {
            if (rafId !== null) {
                return;
            }

            rafId = window.requestAnimationFrame(update);
        };

        update();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            if (rafId !== null) {
                window.cancelAnimationFrame(rafId);
            }

            window.removeEventListener('scroll', onScroll);
        };
    }, [fixed]);

    return (
        <header
            ref={headerRef}
            className={cn(
                'z-50 h-16',
                fixed && 'header-fixed peer/header sticky top-0 w-[inherit]',
                fixed ? 'shadow-none data-[scrolled=true]:shadow' : '',
                className,
            )}
            {...props}
        >
            <div
                className={cn(
                    'relative flex h-full items-center gap-3 p-4 sm:gap-4',
                    fixed &&
                        'data-[scrolled=true]:after:absolute data-[scrolled=true]:after:inset-0 data-[scrolled=true]:after:-z-10 data-[scrolled=true]:after:bg-background/20 data-[scrolled=true]:after:backdrop-blur-lg',
                )}
            >
                <SidebarTrigger
                    variant="outline"
                    className="max-md:scale-125"
                />
                <Separator orientation="vertical" className="h-6" />
                {children}
            </div>
        </header>
    );
}
