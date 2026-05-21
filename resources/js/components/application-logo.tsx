import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

type Variant = 'main' | 'login';

type Props = {
    variant?: Variant;
    className?: string;
    imageClassName?: string;
    iconClassName?: string;
};

export default function ApplicationLogo({
    variant = 'main',
    className,
    imageClassName,
    iconClassName,
}: Props) {
    const { settings } = usePage().props;
    const branding = settings?.branding;

    const url =
        variant === 'login'
            ? branding?.login_logo_url ?? branding?.main_logo_url
            : branding?.main_logo_url;

    if (url) {
        return (
            <img
                src={url}
                alt={settings?.app_name ?? 'Application logo'}
                className={cn('object-contain', imageClassName ?? 'h-8 w-auto max-w-[160px]', className)}
            />
        );
    }

    return <AppLogoIcon className={cn('size-8 fill-current', iconClassName, className)} />;
}
