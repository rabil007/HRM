import { Link } from '@inertiajs/react';
import { Download, Folder } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { EmployeeFolder } from '@/features/organization/documents/types';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

export type { EmployeeFolder };

export function EmployeeFolderItem({
    employee,
    canDownload = false,
    selected = false,
    onSelectedChange,
    selectionMode = false,
}: {
    employee: EmployeeFolder;
    canDownload?: boolean;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
    selectionMode?: boolean;
}) {
    const fileLabel =
        employee.document_count === 1
            ? '1 file'
            : `${employee.document_count} files`;

    const downloadUrl = documents.employee.download.url({ employee: employee.employee_id });

    return (
        <div
            className={cn(
                'group relative flex w-full flex-col items-center rounded-xl',
                selected && 'ring-1 ring-primary/30 bg-primary/5',
            )}
        >
            {selectionMode ? (
                <div className="absolute top-2 left-2 z-10">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) => onSelectedChange?.(value === true)}
                        aria-label={`Select ${employee.employee_name}`}
                        onClick={(event) => event.stopPropagation()}
                    />
                </div>
            ) : null}

            <Link
                href={documents.employee.url({ employee: employee.employee_id })}
                title={`${employee.employee_name} (${employee.employee_no})`}
                className={cn(
                    'flex w-full flex-col items-center gap-2 rounded-xl px-2 py-3 text-center sm:px-3 sm:py-4',
                    'transition-all duration-150 hover:bg-muted/40',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                )}
            >
                <Folder
                    className="h-16 w-16 shrink-0 text-amber-400/95 drop-shadow-sm transition-transform duration-150 group-hover:scale-[1.03] group-hover:text-amber-300 sm:h-[4.25rem] sm:w-[4.25rem]"
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

            {!selectionMode && canDownload ? (
                <Button
                    variant="ghost"
                    size="icon"
                    className="absolute top-1 right-1 size-7 rounded-lg text-muted-foreground/70 opacity-0 transition-opacity hover:bg-white/10 hover:text-foreground group-hover:opacity-100 focus-visible:opacity-100"
                    asChild
                >
                    <a
                        href={downloadUrl}
                        title="Download all documents as ZIP"
                        aria-label={`Download all documents for ${employee.employee_name}`}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <Download className="size-3.5" />
                    </a>
                </Button>
            ) : null}
        </div>
    );
}
