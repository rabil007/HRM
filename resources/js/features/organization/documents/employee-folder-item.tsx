import { Link } from '@inertiajs/react';
import { ChevronRight, Download, Folder } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { EmployeeFolder } from '@/features/organization/documents/types';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

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

    const downloadUrl = documentRoutes.employee.download.url({
        employee: employee.employee_id,
    });

    return (
        <div
            className={cn(
                'group relative flex w-full min-w-0 flex-col rounded-xl border bg-card shadow-sm',
                'transition-[border-color,box-shadow,background-color] duration-150',
                'hover:border-primary/30 hover:bg-muted/25 hover:shadow-md',
                selected &&
                    'border-primary/25 bg-primary/5 ring-1 ring-primary/30',
            )}
        >
            {selectionMode ? (
                <div className="absolute top-3 right-3 z-10">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) =>
                            onSelectedChange?.(value === true)
                        }
                        aria-label={`Select ${employee.employee_name}`}
                        onClick={(event) => event.stopPropagation()}
                    />
                </div>
            ) : null}

            <Link
                href={documentRoutes.employee.url({
                    employee: employee.employee_id,
                })}
                title={`${employee.employee_name} (${employee.employee_no})`}
                className={cn(
                    'flex h-full min-h-36 w-full flex-col items-start gap-3 rounded-xl p-4 text-left',
                    'cursor-pointer',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                )}
            >
                <Folder
                    className="size-9 shrink-0 text-amber-600 dark:text-amber-400"
                    strokeWidth={1.15}
                    fill="currentColor"
                    fillOpacity={0.2}
                    aria-hidden
                />
                <div className="flex w-full min-w-0 flex-col gap-1">
                    <span className="line-clamp-2 w-full text-sm leading-snug font-semibold text-foreground">
                        {employee.employee_name}
                    </span>
                    <span className="w-full truncate font-mono text-xs text-muted-foreground">
                        {employee.employee_no}
                    </span>
                    <div className="mt-2 flex items-center justify-between border-t pt-2 text-xs text-muted-foreground">
                        <span className="tabular-nums">{fileLabel}</span>
                        <ChevronRight className="size-3.5" aria-hidden />
                    </div>
                </div>
            </Link>

            {!selectionMode && canDownload ? (
                <Button
                    variant="ghost"
                    size="icon"
                    className="absolute top-1 right-1 size-7 rounded-lg text-muted-foreground/70 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-white/10 hover:text-foreground focus-visible:opacity-100"
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
