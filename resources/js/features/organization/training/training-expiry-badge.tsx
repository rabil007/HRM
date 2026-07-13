import { Badge } from '@/components/ui/badge';
import {
    trainingExpiryStatusClass,
    trainingExpiryStatusLabel,
    trainingExpiryStatusVariant,
} from '@/features/organization/training/training-expiry';
import { cn } from '@/lib/utils';

export function TrainingExpiryBadge({
    status,
    className,
}: {
    status: string | null | undefined;
    className?: string;
}) {
    return (
        <Badge
            variant={trainingExpiryStatusVariant(status)}
            className={cn(
                'font-normal whitespace-nowrap',
                trainingExpiryStatusClass(status),
                className,
            )}
        >
            {trainingExpiryStatusLabel(status)}
        </Badge>
    );
}
