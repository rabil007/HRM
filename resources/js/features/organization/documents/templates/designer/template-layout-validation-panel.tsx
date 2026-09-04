import { AlertTriangle, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type {
    LayoutPreflightIssue,
    LayoutPreflightResult,
} from '../lib/layout-validation';

export function TemplateLayoutValidationPanel({
    result,
    onSelectIssue,
}: {
    result: LayoutPreflightResult | null;
    onSelectIssue: (issue: LayoutPreflightIssue) => void;
}) {
    if (!result) {
        return (
            <div className="space-y-1.5">
                <p className="text-xs font-semibold">Layout validation</p>
                <p className="text-[11px] text-muted-foreground">
                    Validate the template to check whether sample values fit
                    their boxes.
                </p>
            </div>
        );
    }

    const overflowIssues = result.issues.filter(
        (issue) => issue.code === 'LAYOUT_OVERFLOW',
    );
    const otherIssues = result.issues.filter(
        (issue) => issue.code !== 'LAYOUT_OVERFLOW',
    );

    if (result.valid) {
        return (
            <div className="space-y-1.5">
                <p className="text-xs font-semibold">Layout validation</p>
                <p className="flex items-center gap-1 text-[11px] text-emerald-700 dark:text-emerald-400">
                    <Check className="size-3.5" />
                    All configured text fields fit the validation data.
                </p>
                <p className="text-[11px] text-muted-foreground">
                    Validated with:{' '}
                    {result.validated_with.mode === 'employee'
                        ? (result.validated_with.employee_name ?? 'Employee')
                        : 'Sample data'}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-xs font-semibold">Layout validation</p>
            <p className="text-[11px] text-destructive">
                {result.issues.length === 1
                    ? '1 issue'
                    : `${result.issues.length} issues`}
            </p>
            {otherIssues.map((issue, index) => (
                <p
                    key={`${issue.code}-${index}`}
                    className="text-[11px] text-destructive"
                >
                    {issue.message}
                </p>
            ))}
            {overflowIssues.map((issue) => (
                <div
                    key={issue.placement_id ?? issue.message}
                    className="space-y-1 rounded-md border border-destructive/30 p-2"
                >
                    <p className="flex items-start gap-1 text-[11px] font-medium text-destructive">
                        <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                        {issue.field_label ?? 'Text box'}
                    </p>
                    {issue.page ? (
                        <p className="text-[11px] text-muted-foreground">
                            Page {issue.page}
                        </p>
                    ) : null}
                    <p className="text-[11px] text-foreground">
                        {issue.message}
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                        Suggested: Increase the field width or reduce the
                        configured font size.
                    </p>
                    {issue.placement_id ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-6 px-2 text-[11px]"
                            onClick={() => onSelectIssue(issue)}
                        >
                            Select field
                        </Button>
                    ) : null}
                </div>
            ))}
        </div>
    );
}

export function TemplateLayoutSelectedIssue({
    issue,
}: {
    issue: LayoutPreflightIssue | null;
}) {
    if (!issue) {
        return null;
    }

    return (
        <div className="space-y-1 rounded-md border border-destructive/30 p-2">
            <p className="text-[11px] font-semibold">Validation</p>
            <p className="text-[11px] text-destructive">Value does not fit</p>
            {issue.test_value ? (
                <p className="font-mono text-[11px] break-all text-muted-foreground">
                    Test value: {issue.test_value}
                </p>
            ) : null}
            <p className="text-[11px] text-muted-foreground">
                Increase width, increase height where wrapping is acceptable, or
                reduce the requested font size.
            </p>
        </div>
    );
}
