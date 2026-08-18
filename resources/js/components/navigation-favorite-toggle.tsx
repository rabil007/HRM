import { Star } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useNavigationFavorites } from '@/hooks/use-navigation-favorites';
import { cn } from '@/lib/utils';

export function NavigationFavoriteToggle({
    className,
}: {
    className?: string;
}) {
    const { canToggleCurrent, currentIsFavorite, toggleCurrent } =
        useNavigationFavorites();

    if (!canToggleCurrent) {
        return null;
    }

    const label = currentIsFavorite
        ? 'Remove from favorites'
        : 'Add to favorites';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={cn(
                        'size-10 shrink-0 md:size-9',
                        currentIsFavorite && 'text-primary',
                        className,
                    )}
                    aria-label={label}
                    aria-pressed={currentIsFavorite}
                    onClick={toggleCurrent}
                >
                    <Star
                        className={cn(
                            'size-4',
                            currentIsFavorite && 'fill-current',
                        )}
                    />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
