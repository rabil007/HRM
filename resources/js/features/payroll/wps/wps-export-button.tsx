import { ChevronDown, Download, FileSpreadsheet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { submitWpsExport  } from './submit-wps-export';
import type {WpsExportFormat} from './submit-wps-export';

export function WpsExportButton({
    periodId,
    disabled = false,
    className,
    size = 'default',
}: {
    periodId: number;
    disabled?: boolean;
    className?: string;
    size?: 'default' | 'sm' | 'lg';
}) {
    const handleExport = (format: WpsExportFormat) => {
        submitWpsExport(periodId, format);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button className={className} size={size} disabled={disabled}>
                    <Download className="mr-2 h-4 w-4" />
                    Export WPS
                    <ChevronDown className="ml-2 h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem onClick={() => handleExport('sif')}>
                    <Download className="mr-2 h-4 w-4" />
                    SIF file (.sif)
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => handleExport('xlsx')}>
                    <FileSpreadsheet className="mr-2 h-4 w-4" />
                    Excel file (.xlsx)
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
