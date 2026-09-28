import { Link } from '@inertiajs/react';
import { Folder } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { cn } from '@/lib/utils';

export type DocumentsBreadcrumbItem = {
    title: string;
    href?: string;
};

export function DocumentsBreadcrumbs({
    items,
    className,
}: {
    items: DocumentsBreadcrumbItem[];
    className?: string;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Breadcrumb className={cn('mb-5', className)}>
            <BreadcrumbList>
                <BreadcrumbItem>
                    <Folder
                        className="h-3.5 w-3.5 text-muted-foreground/70"
                        aria-hidden
                    />
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <Fragment key={`${item.title}-${index}`}>
                            <BreadcrumbItem>
                                {isLast || !item.href ? (
                                    <BreadcrumbPage className="max-w-[14rem] truncate font-medium sm:max-w-xs">
                                        {item.title}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <Link
                                            href={item.href}
                                            className="max-w-[10rem] truncate font-medium sm:max-w-xs"
                                        >
                                            {item.title}
                                        </Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast ? <BreadcrumbSeparator /> : null}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
