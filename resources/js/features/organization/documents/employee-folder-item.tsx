import { Link } from '@inertiajs/react';
import { Folder } from 'lucide-react';
import type { EmployeeFolder } from '@/features/organization/documents/types';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

export type { EmployeeFolder };

export function EmployeeFolderItem({ employee }: { employee: EmployeeFolder }) {
    const fileLabel =
        employee.document_count === 1
            ? '1 file'
            : `${employee.document_count} files`;

    return (
        <Link
            href={documents.employee.url({ employee: employee.employee_id })}
            title={`${employee.employee_name} (${employee.employee_no})`}
            className={cn(
                'group flex w-full max-w-[9.5rem] flex-col items-center gap-2 rounded-xl px-3 py-4 text-center',
                'transition-all duration-150 hover:bg-muted/50 hover:shadow-sm',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            )}
        >
            <Folder
                className="h-[4.25rem] w-[4.25rem] shrink-0 text-amber-400/95 drop-shadow-sm transition-transform duration-150 group-hover:scale-[1.04] group-hover:text-amber-300"
                strokeWidth={1.15}
                fill="currentColor"
                fillOpacity={0.2}
                aria-hidden
            />
            <div className="flex w-full min-w-0 flex-col items-center gap-0.5">
                <span className="line-clamp-2 w-full text-sm leading-snug font-semibold text-foreground">
                    {employee.employee_name}
                </span>
                <span className="w-full truncate font-mono text-[11px] text-muted-foreground/75">
                    {employee.employee_no}
                </span>
                <span className="mt-0.5 rounded-full bg-muted/60 px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums">
                    {fileLabel}
                </span>
            </div>
        </Link>
    );
}
