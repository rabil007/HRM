import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { SalaryAdjustmentStatus } from '../types';

const STATUS_STYLES: Record<SalaryAdjustmentStatus, string> = {
    pending: 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-200',
    approved: 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200',
    rejected: 'bg-red-500/10 text-red-700 border-red-500/20 dark:text-red-200',
    applied: 'bg-blue-500/10 text-blue-700 border-blue-500/20 dark:text-blue-200',
};

export function SalaryAdjustmentStatusBadge({ status, label }: { status: SalaryAdjustmentStatus; label: string }) {
    return (
        <Badge className={cn('border text-[10px] font-bold uppercase tracking-wider', STATUS_STYLES[status])}>
            {label}
        </Badge>
    );
}
