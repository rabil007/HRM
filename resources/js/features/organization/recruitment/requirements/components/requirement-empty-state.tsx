import { ClipboardList } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import type { RequirementTab } from '@/types/recruitment';
import { resolveRequirementEmptyState } from '../lib/requirement-empty-state';

type Props = {
    activeTab: RequirementTab;
    hasSearch?: boolean;
    hasActiveFilters?: boolean;
    canCreate?: boolean;
    onAddRequirement?: () => void;
    onClearFilters?: () => void;
};

export function RequirementEmptyState({
    activeTab,
    hasSearch = false,
    hasActiveFilters = false,
    canCreate = false,
    onAddRequirement,
    onClearFilters,
}: Props) {
    const copy = resolveRequirementEmptyState({
        activeTab,
        hasSearch,
        hasActiveFilters,
        canCreate,
    });

    return (
        <EmptyState
            icon={
                <ClipboardList
                    className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40"
                    aria-hidden="true"
                />
            }
            title={copy.title}
            description={copy.description}
            action={
                copy.showClearAction && onClearFilters ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onClearFilters}
                        className="gap-2"
                    >
                        Clear search & filters
                    </Button>
                ) : copy.showCreateAction && onAddRequirement ? (
                    <Button
                        type="button"
                        size="sm"
                        onClick={onAddRequirement}
                        className="gap-2"
                    >
                        Create requirement
                    </Button>
                ) : undefined
            }
        />
    );
}
