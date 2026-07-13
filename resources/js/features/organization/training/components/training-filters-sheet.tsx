import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CountryOption } from '@/features/organization/employees/types';
import type { CourseOption } from '@/pages/organization/employee-page.types';

export type TrainingSheetFilters = {
    course_id: string;
    institute: string;
    country_id: string;
    issue_date: string;
};

export function TrainingFiltersSheet({
    open,
    onOpenChange,
    courses,
    countries,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    courses: CourseOption[];
    countries: CountryOption[];
    value: TrainingSheetFilters;
    onChange: (next: TrainingSheetFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Course
                </Label>
                <AppSelect
                    value={value.course_id}
                    onValueChange={(courseId) =>
                        onChange({ ...value, course_id: courseId })
                    }
                    variant="dark"
                    placeholder="All courses"
                    searchPlaceholder="Search course..."
                >
                    <AppSelectItem value="">All courses</AppSelectItem>
                    {courses.map((course) => (
                        <AppSelectItem
                            key={course.id}
                            value={String(course.id)}
                        >
                            {course.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="filter-institute"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    Institute
                </Label>
                <Input
                    id="filter-institute"
                    placeholder="e.g. Maritime Academy"
                    className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                    value={value.institute}
                    onChange={(event) =>
                        onChange({ ...value, institute: event.target.value })
                    }
                />
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Country
                </Label>
                <AppSelect
                    value={value.country_id}
                    onValueChange={(countryId) =>
                        onChange({ ...value, country_id: countryId })
                    }
                    variant="dark"
                    placeholder="All countries"
                    searchPlaceholder="Search country..."
                >
                    <AppSelectItem value="">All countries</AppSelectItem>
                    {countries.map((country) => (
                        <AppSelectItem
                            key={country.id}
                            value={String(country.id)}
                        >
                            {country.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="filter-issue-date"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    Issue date
                </Label>
                <Input
                    id="filter-issue-date"
                    type="date"
                    className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                    value={value.issue_date}
                    onChange={(event) =>
                        onChange({ ...value, issue_date: event.target.value })
                    }
                />
            </div>
        </FiltersSheet>
    );
}
