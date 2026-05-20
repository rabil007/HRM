import { Badge } from '@/components/ui/badge';
import {
    expiryStatusClass,
    expiryStatusLabel,
    expiryStatusVariant,
} from '@/features/organization/documents/document-expiry';
import { cn } from '@/lib/utils';

export function DocumentExpiryBadge({
    status,
    className,
}: {
    status: string | null | undefined;
    className?: string;
}) {
    return (
        <Badge
            variant={expiryStatusVariant(status)}
            className={cn('font-normal whitespace-nowrap', expiryStatusClass(status), className)}
        >
            {expiryStatusLabel(status)}
        </Badge>
    );
}
