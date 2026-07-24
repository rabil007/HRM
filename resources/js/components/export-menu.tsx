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

export function ExportMenu({
    getUrl,
    label = 'Export',
    buttonVariant = 'outline',
    buttonClassName,
    align = 'end',
    selectedCount = 0,
    getSelectedUrl,
}: {
    getUrl: (format: ExportFormat) => string;
    label?: string;
    buttonVariant?: React.ComponentProps<typeof Button>['variant'];
    buttonClassName?: string;
    align?: 'start' | 'center' | 'end';
    selectedCount?: number;
    getSelectedUrl?: (format: ExportFormat) => string;
}) {
    const go = (format: ExportFormat) => {
        window.location.href = getUrl(format);
    };

    const goSelected = (format: ExportFormat) => {
        if (getSelectedUrl) {
            window.location.href = getSelectedUrl(format);
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
                <DropdownMenuItem onClick={() => go('csv')}>
                    CSV
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => go('xlsx')}>
                    Excel
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => go('pdf')}>
                    PDF
                </DropdownMenuItem>
                {hasSelectedExport ? (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {selectedCount} selected
                        </DropdownMenuLabel>
                        <DropdownMenuItem onClick={() => goSelected('csv')}>
                            CSV
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => goSelected('xlsx')}>
                            Excel
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => goSelected('pdf')}>
                            PDF
                        </DropdownMenuItem>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
