import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type ExportFormat = 'csv' | 'xlsx' | 'pdf';

const FORMAT_LABELS: Record<ExportFormat, string> = {
    csv: 'CSV',
    xlsx: 'Excel',
    pdf: 'PDF',
};

export function ExportMenu({
    getUrl,
    label = 'Export',
    buttonVariant = 'outline',
    buttonClassName,
    align = 'end',
    selectedCount = 0,
    getSelectedUrl,
    formats = ['csv', 'xlsx', 'pdf'],
}: {
    getUrl: (format: ExportFormat) => string;
    label?: string;
    buttonVariant?: React.ComponentProps<typeof Button>['variant'];
    buttonClassName?: string;
    align?: 'start' | 'center' | 'end';
    selectedCount?: number;
    getSelectedUrl?: (format: ExportFormat) => string;
    formats?: ExportFormat[];
}) {
    const go = (format: ExportFormat) => {
        window.location.assign(getUrl(format));
    };

    const goSelected = (format: ExportFormat) => {
        if (getSelectedUrl) {
            window.location.assign(getSelectedUrl(format));
        }
    };

    const hasSelectedExport = selectedCount > 0 && getSelectedUrl !== undefined;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant={buttonVariant} className={buttonClassName}>
                    <Download className="mr-2 h-4 w-4" />
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={align} className="w-44">
                {hasSelectedExport ? (
                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                        All filtered records
                    </DropdownMenuLabel>
                ) : null}
                {formats.map((format) => (
                    <DropdownMenuItem key={format} onClick={() => go(format)}>
                        {FORMAT_LABELS[format]}
                    </DropdownMenuItem>
                ))}
                {hasSelectedExport ? (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {selectedCount} selected
                        </DropdownMenuLabel>
                        {formats.map((format) => (
                            <DropdownMenuItem
                                key={`selected-${format}`}
                                onClick={() => goSelected(format)}
                            >
                                {FORMAT_LABELS[format]}
                            </DropdownMenuItem>
                        ))}
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
