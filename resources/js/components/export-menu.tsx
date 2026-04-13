import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

export type ExportFormat = 'csv' | 'xlsx' | 'pdf';

export function ExportMenu({
    getUrl,
    label = 'Export',
    buttonVariant = 'outline',
    buttonClassName,
    align = 'end',
}: {
    getUrl: (format: ExportFormat) => string;
    label?: string;
    buttonVariant?: React.ComponentProps<typeof Button>['variant'];
    buttonClassName?: string;
    align?: 'start' | 'center' | 'end';
}) {
    const go = (format: ExportFormat) => {
        window.location.href = getUrl(format);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant={buttonVariant} className={buttonClassName}>
                    <Download className="mr-2 h-4 w-4" />
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={align} className="w-44">
                <DropdownMenuItem onClick={() => go('csv')}>CSV</DropdownMenuItem>
                <DropdownMenuItem onClick={() => go('xlsx')}>Excel</DropdownMenuItem>
                <DropdownMenuItem onClick={() => go('pdf')}>PDF</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

