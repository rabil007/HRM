import type { CrewSalaryStructureView } from '@/features/payroll/types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const options: { value: CrewSalaryStructureView; label: string }[] = [
    { value: 'daily', label: 'Daily' },
    { value: 'monthly', label: 'Monthly' },
];

export function CrewSalaryStructureToggle({
    value,
    onChange,
}: {
    value: CrewSalaryStructureView;
    onChange: (value: CrewSalaryStructureView) => void;
}) {
    return (
        <div className="flex items-center rounded-xl glass-card p-1">
            {options.map((option) => {
                const isActive = value === option.value;

                return (
                    <Button
                        key={option.value}
                        type="button"
                        variant={isActive ? 'default' : 'ghost'}
                        className={cn(
                            'h-10 rounded-lg px-4 text-sm font-medium transition-all',
                            !isActive && 'hover:bg-accent',
                        )}
                        onClick={() => onChange(option.value)}
                    >
                        {option.label}
                    </Button>
                );
            })}
        </div>
    );
}
