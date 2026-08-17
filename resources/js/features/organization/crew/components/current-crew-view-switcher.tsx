import { Ship, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { CurrentCrewView } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';

export function CurrentCrewViewSwitcher({
    value,
    onChange,
}: {
    value: CurrentCrewView;
    onChange: (view: CurrentCrewView) => void;
}) {
    return (
        <div
            className="flex items-center rounded-xl glass-card p-1"
            role="group"
            aria-label="Current crew view"
        >
            <Button
                type="button"
                variant={value === 'crew' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-3 sm:px-4',
                    value !== 'crew' && 'hover:bg-accent',
                )}
                aria-pressed={value === 'crew'}
                onClick={() => onChange('crew')}
            >
                <Users className="h-4 w-4" aria-hidden />
                <span className="hidden sm:inline">Crew View</span>
                <span className="sm:hidden">Crew</span>
            </Button>
            <Button
                type="button"
                variant={value === 'vessel' ? 'default' : 'ghost'}
                className={cn(
                    'h-11 rounded-lg px-3 sm:px-4',
                    value !== 'vessel' && 'hover:bg-accent',
                )}
                aria-pressed={value === 'vessel'}
                onClick={() => onChange('vessel')}
            >
                <Ship className="h-4 w-4" aria-hidden />
                <span className="hidden sm:inline">Vessel View</span>
                <span className="sm:hidden">Vessel</span>
            </Button>
        </div>
    );
}
