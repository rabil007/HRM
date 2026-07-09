import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    Download,
    GripVertical,
    Plus,
    Search,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { downloadBinaryExport } from '@/features/organization/documents/shared/download-binary-export';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { EmployeeExportFieldOption } from '../types';

const DEFAULT_SELECTED_KEYS = [
    'id',
    'employee_no',
    'name',
    'branch',
    'department',
    'position',
    'manager',
    'work_email',
    'phone',
    'status',
    'hire_date',
    'created_at',
];

const GROUP_LABELS: Record<EmployeeExportFieldOption['group'], string> = {
    employee: 'Employee',
    contract: 'Contract',
    bank_account: 'Bank account',
};

const GROUP_COLORS: Record<
    EmployeeExportFieldOption['group'],
    { badge: string; dot: string }
> = {
    employee: {
        badge: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
        dot: 'bg-blue-500',
    },
    contract: {
        badge: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        dot: 'bg-amber-500',
    },
    bank_account: {
        badge: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
        dot: 'bg-emerald-500',
    },
};

export function EmployeeExportDialog({
    open,
    onOpenChange,
    fieldOptions,
    filters,
    exportUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    fieldOptions: EmployeeExportFieldOption[];
    filters: Record<string, string>;
    exportUrl: string;
}) {
    const [search, setSearch] = useState('');
    const [format, setFormat] = useState<'csv' | 'xlsx'>('xlsx');
    const [collapsedGroups, setCollapsedGroups] = useState<
        Set<EmployeeExportFieldOption['group']>
    >(new Set());
    const [selectedKeys, setSelectedKeys] = useState<string[]>(
        DEFAULT_SELECTED_KEYS,
    );
    const [isExporting, setIsExporting] = useState(false);

    const allowedOptions = useMemo(
        () => fieldOptions.filter((option) => option.allowed),
        [fieldOptions],
    );

    const optionByKey = useMemo(
        () =>
            new Map(
                allowedOptions.map((option) => [option.key, option] as const),
            ),
        [allowedOptions],
    );

    const availableOptions = useMemo(() => {
        const selected = new Set(selectedKeys);
        const term = search.trim().toLowerCase();

        return allowedOptions.filter((option) => {
            if (selected.has(option.key)) {
                return false;
            }

            if (term === '') {
                return true;
            }

            return (
                option.label.toLowerCase().includes(term) ||
                option.key.toLowerCase().includes(term) ||
                GROUP_LABELS[option.group].toLowerCase().includes(term)
            );
        });
    }, [allowedOptions, search, selectedKeys]);

    const groupedAvailable = useMemo(() => {
        const groups: Record<
            EmployeeExportFieldOption['group'],
            EmployeeExportFieldOption[]
        > = {
            employee: [],
            contract: [],
            bank_account: [],
        };

        for (const option of availableOptions) {
            groups[option.group].push(option);
        }

        return groups;
    }, [availableOptions]);

    const selectedOptions = useMemo(
        () =>
            selectedKeys
                .map((key) => optionByKey.get(key))
                .filter(
                    (option): option is EmployeeExportFieldOption =>
                        option !== undefined,
                ),
        [optionByKey, selectedKeys],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        setSearch('');
        setFormat('xlsx');
        setCollapsedGroups(new Set());
        setSelectedKeys(
            DEFAULT_SELECTED_KEYS.filter((key) => optionByKey.has(key)),
        );
    }, [open, optionByKey]);

    const addField = (key: string) => {
        const option = optionByKey.get(key);

        if (option?.excel_only && format === 'csv') {
            toast.error('This field is only available in XLSX format.');

            return;
        }

        setSelectedKeys((current) =>
            current.includes(key) ? current : [...current, key],
        );
    };

    const toggleGroup = (group: EmployeeExportFieldOption['group']) => {
        setCollapsedGroups((prev) => {
            const next = new Set(prev);

            if (next.has(group)) {
                next.delete(group);
            } else {
                next.add(group);
            }

            return next;
        });
    };

    const addAllVisible = () => {
        setSelectedKeys((current) => {
            const existing = new Set(current);
            const toAdd = availableOptions
                .map((opt) => opt.key)
                .filter((k) => !existing.has(k));

            return [...current, ...toAdd];
        });
    };

    const removeField = (key: string) => {
        setSelectedKeys((current) => current.filter((item) => item !== key));
    };

    const clearAll = () => setSelectedKeys([]);

    const moveField = (index: number, direction: -1 | 1) => {
        setSelectedKeys((current) => {
            const nextIndex = index + direction;

            if (nextIndex < 0 || nextIndex >= current.length) {
                return current;
            }

            const next = [...current];
            const [moved] = next.splice(index, 1);
            next.splice(nextIndex, 0, moved);

            return next;
        });
    };

    const handleExport = async () => {
        if (selectedKeys.length === 0 || isExporting) {
            return;
        }

        setIsExporting(true);

        try {
            const accept =
                format === 'xlsx'
                    ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                    : 'text/csv';

            await downloadBinaryExport(
                exportUrl,
                {
                    format,
                    fields: selectedKeys,
                    ...filters,
                },
                accept,
                `employees.${format}`,
                'Employee export failed. Please try again.',
            );

            onOpenChange(false);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Employee export failed. Please try again.',
            );
        } finally {
            setIsExporting(false);
        }
    };

    const totalAvailable = availableOptions.length;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[min(88vh,640px)] w-[min(96vw,58rem)] max-w-none flex-col gap-0 overflow-hidden rounded-2xl border-border bg-background p-0 shadow-2xl sm:max-w-none">
                {/* Header */}
                <DialogHeader className="shrink-0 flex-row items-center justify-between border-b border-border pl-6 pr-14 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                            <Download className="h-4 w-4 text-primary" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-semibold leading-none">
                                Export Employees
                            </DialogTitle>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Choose columns and download format
                            </p>
                        </div>
                    </div>

                    {/* Format toggle — inline in header */}
                    <div className="flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
                        {(['xlsx', 'csv'] as const).map((opt) => (
                            <button
                                key={opt}
                                type="button"
                                onClick={() => setFormat(opt)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-xs font-semibold transition-all',
                                    format === opt
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {opt.toUpperCase()}
                            </button>
                        ))}
                    </div>
                </DialogHeader>

                {/* Body — two columns, fills remaining height */}
                <div className="grid min-h-0 flex-1 grid-cols-2 divide-x divide-border overflow-hidden">
                    {/* ── Left: Available ── */}
                    <div className="flex h-full flex-col overflow-hidden">
                        <div className="shrink-0 flex items-center justify-between border-b border-border px-4 py-2.5">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    Available fields
                                </span>
                                {totalAvailable > 0 && (
                                    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[11px] font-bold text-muted-foreground">
                                        {totalAvailable}
                                    </span>
                                )}
                            </div>
                            {totalAvailable > 0 && (
                                <button
                                    type="button"
                                    onClick={addAllVisible}
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    Add all
                                </button>
                            )}
                        </div>

                        <div className="shrink-0 border-b border-border px-3 py-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search fields…"
                                    className="h-8 pl-8 text-sm"
                                />
                                {search && (
                                    <button
                                        type="button"
                                        onClick={() => setSearch('')}
                                        className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto px-2 py-2">
                            {(
                                Object.keys(
                                    groupedAvailable,
                                ) as EmployeeExportFieldOption['group'][]
                            ).map((group) => {
                                const options = groupedAvailable[group];

                                if (options.length === 0) {
                                    return null;
                                }

                                const isCollapsed = collapsedGroups.has(group);

                                return (
                                    <div key={group} className="mb-1">
                                        <button
                                            type="button"
                                            onClick={() => toggleGroup(group)}
                                            className="flex w-full items-center gap-1.5 rounded-md px-1.5 py-1.5 hover:bg-muted/60"
                                        >
                                            <ChevronDown
                                                className={cn(
                                                    'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform duration-150',
                                                    isCollapsed && '-rotate-90',
                                                )}
                                            />
                                            <span
                                                className={cn(
                                                    'h-1.5 w-1.5 rounded-full',
                                                    GROUP_COLORS[group].dot,
                                                )}
                                            />
                                            <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                {GROUP_LABELS[group]}
                                            </span>
                                            <span className="ml-auto text-[11px] text-muted-foreground/60">
                                                {options.length}
                                            </span>
                                        </button>

                                        {!isCollapsed && (
                                            <div className="ml-2">
                                                {options.map((option) => (
                                                    <button
                                                        key={option.key}
                                                        type="button"
                                                        onClick={() =>
                                                            addField(option.key)
                                                        }
                                                        className={cn(
                                                            'group flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left hover:bg-muted/70 active:bg-muted',
                                                            option.excel_only &&
                                                                format ===
                                                                    'csv' &&
                                                                'opacity-50',
                                                        )}
                                                    >
                                                        <span className="flex items-center gap-2 text-sm text-foreground">
                                                            {option.label}
                                                            {option.excel_only && (
                                                                <span className="rounded border border-amber-500/30 bg-amber-500/10 px-1 py-px text-[9px] font-bold tracking-wide text-amber-600 dark:text-amber-400">
                                                                    XLSX
                                                                </span>
                                                            )}
                                                        </span>
                                                        <Plus className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}

                            {availableOptions.length === 0 && (
                                <div className="flex flex-col items-center justify-center py-10 text-center">
                                    <Search className="mb-2 h-7 w-7 text-muted-foreground/40" />
                                    <p className="text-sm text-muted-foreground">
                                        {search
                                            ? 'No fields match your search.'
                                            : 'All fields have been selected.'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* ── Right: Selected ── */}
                    <div className="flex h-full flex-col overflow-hidden">
                        <div className="shrink-0 flex items-center justify-between border-b border-border px-4 py-2.5">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    Fields to export
                                </span>
                                {selectedOptions.length > 0 && (
                                    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/15 px-1.5 text-[11px] font-bold text-primary">
                                        {selectedOptions.length}
                                    </span>
                                )}
                            </div>
                            {selectedOptions.length > 0 && (
                                <button
                                    type="button"
                                    onClick={clearAll}
                                    className="text-xs font-medium text-muted-foreground hover:text-destructive"
                                >
                                    Clear all
                                </button>
                            )}
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {selectedOptions.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-10 text-center">
                                    <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-xl border-2 border-dashed border-border">
                                        <Plus className="h-5 w-5 text-muted-foreground/50" />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        No fields selected yet.
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground/60">
                                        Add fields from the left panel.
                                    </p>
                                </div>
                            ) : (
                                <div className="px-2 py-2">
                                    {selectedOptions.map((option, index) => (
                                        <div
                                            key={option.key}
                                            className="group flex items-center gap-1.5 rounded-lg px-1.5 py-1.5 hover:bg-muted/60"
                                        >
                                            {/* drag handle visual */}
                                            <GripVertical className="h-4 w-4 shrink-0 text-muted-foreground/30 group-hover:text-muted-foreground/60" />

                                            {/* reorder */}
                                            <div className="flex flex-col gap-0.5">
                                                <button
                                                    type="button"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        moveField(index, -1)
                                                    }
                                                    className="flex h-4 w-4 items-center justify-center rounded text-muted-foreground/50 hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                                                >
                                                    <ArrowUp className="h-3 w-3" />
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={
                                                        index ===
                                                        selectedOptions.length -
                                                            1
                                                    }
                                                    onClick={() =>
                                                        moveField(index, 1)
                                                    }
                                                    className="flex h-4 w-4 items-center justify-center rounded text-muted-foreground/50 hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-25"
                                                >
                                                    <ArrowDown className="h-3 w-3" />
                                                </button>
                                            </div>

                                            {/* label + group */}
                                            <div className="min-w-0 flex-1">
                                                <span className="block truncate text-sm leading-none">
                                                    {option.label}
                                                </span>
                                                <div className="mt-0.5 flex items-center gap-1">
                                                    <span
                                                        className={cn(
                                                            'inline-flex items-center gap-1 rounded border px-1.5 py-px text-[10px] font-medium leading-none',
                                                            GROUP_COLORS[
                                                                option.group
                                                            ].badge,
                                                        )}
                                                    >
                                                        {
                                                            GROUP_LABELS[
                                                                option.group
                                                            ]
                                                        }
                                                    </span>
                                                    {option.excel_only &&
                                                        format === 'csv' && (
                                                            <span
                                                                className="inline-flex items-center gap-0.5 rounded border border-amber-500/30 bg-amber-500/10 px-1 py-px text-[9px] font-bold text-amber-600 dark:text-amber-400"
                                                                title="Switch to XLSX to include this field"
                                                            >
                                                                <TriangleAlert className="h-2.5 w-2.5" />
                                                                XLSX only
                                                            </span>
                                                        )}
                                                </div>
                                            </div>

                                            {/* remove */}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeField(option.key)
                                                }
                                                className="ml-auto flex h-6 w-6 shrink-0 items-center justify-center rounded text-muted-foreground/40 opacity-0 transition-all hover:bg-destructive/10 hover:text-destructive group-hover:opacity-100"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <DialogFooter className="flex-row items-center justify-between border-t border-border bg-muted/20 px-6 py-3 sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                        {selectedOptions.length === 0
                            ? 'Select at least one field to export.'
                            : `${selectedOptions.length} column${selectedOptions.length !== 1 ? 's' : ''} · ${format.toUpperCase()}`}
                    </p>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={handleExport}
                            disabled={
                                selectedKeys.length === 0 || isExporting
                            }
                            className="gap-1.5"
                        >
                            <Download className="h-3.5 w-3.5" />
                            {isExporting ? 'Exporting…' : 'Export'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
