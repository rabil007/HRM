import { Link } from '@inertiajs/react';
import { Briefcase } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';

export type RecruitmentBreadcrumbItem = {
    title: string;
    href?: string;
};

export function RecruitmentBreadcrumbs({
    items = [],
}: {
    items?: RecruitmentBreadcrumbItem[];
}) {
    return (
        <Breadcrumb className="mb-5">
            <BreadcrumbList>
                <BreadcrumbItem>
                    <Briefcase
                        className="h-3.5 w-3.5 text-muted-foreground/70"
                        aria-hidden
                    />
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    {items.length === 0 ? (
                        <BreadcrumbPage className="font-medium">
                            Recruitment
                        </BreadcrumbPage>
                    ) : (
                        <BreadcrumbLink asChild>
                            <Link
                                href="/organization/recruitment/requirements"
                                className="font-medium"
                            >
                                Recruitment
                            </Link>
                        </BreadcrumbLink>
                    )}
                </BreadcrumbItem>
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <Fragment key={`${item.title}-${index}`}>
                            <BreadcrumbSeparator />
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
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
