import { CalendarRange, Ship } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { CrewPlanningView } from '@/features/organization/crew-planning/types';
import { cn } from '@/lib/utils';

export function CrewPlanningViewSwitcher({
    value,
    onChange,
    canViewOnboard,
}: {
    value: CrewPlanningView;
    onChange: (view: CrewPlanningView) => void;
    canViewOnboard: boolean;
}) {
    if (!canViewOnboard) {
        return null;
    }

    return (
        <div
            className="flex items-center rounded-xl glass-card p-1"
            role="group"
            aria-label="Crew planning view"
        >
            <Button
                type="button"
                variant={value === 'planning' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-3 sm:px-4',
                    value !== 'planning' && 'hover:bg-accent',
                )}
                aria-pressed={value === 'planning'}
                onClick={() => onChange('planning')}
            >
                <CalendarRange className="h-4 w-4" aria-hidden />
                <span className="hidden sm:inline">Planning</span>
                <span className="sm:hidden">Plan</span>
            </Button>
            <Button
                type="button"
                variant={value === 'onboard-vessels' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-3 sm:px-4',
                    value !== 'onboard-vessels' && 'hover:bg-accent',
                )}
                aria-pressed={value === 'onboard-vessels'}
                onClick={() => onChange('onboard-vessels')}
            >
                <Ship className="h-4 w-4" aria-hidden />
                <span className="hidden sm:inline">Onboard by Vessel</span>
                <span className="sm:hidden">Onboard</span>
            </Button>
        </div>
    );
}
