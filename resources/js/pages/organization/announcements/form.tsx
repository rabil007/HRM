import { Head, router, useForm, useHttp } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    FileText,
    Folder,
    FolderOpen,
    GitBranch,
    Globe,
    Mail,
    Megaphone,
    MessageCircle,
    Send,
    Smartphone,
    Trash2,
    Upload,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type {
    AnnouncementCan,
    AnnouncementFormData,
    AnnouncementFormOptions,
    AnnouncementFormPayload,
    RecipientPreview,
} from '@/features/organization/announcements/types';
import { cn } from '@/lib/utils';

const CHANNELS = [
    {
        value: 'in_app',
        label: 'In-app',
        Icon: Smartphone,
        description: 'Notification inside the app',
    },
    {
        value: 'email',
        label: 'Email',
        Icon: Mail,
        description: 'Sent to employee email',
    },
    {
        value: 'whatsapp',
        label: 'WhatsApp',
        Icon: MessageCircle,
        description: 'Message to registered phone',
    },
] as const;

function SectionCard({
    icon,
    title,
    description,
    children,
    className,
}: {
    icon: React.ReactNode;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('rounded-xl border glass-card', className)}>
            <div className="border-b border-border/60 bg-muted/20 px-6 py-4">
                <h2 className="flex items-center gap-2 text-base font-semibold">
                    {icon}
                    {title}
                </h2>
                {description ? (
                    <p className="mt-1 text-sm text-muted-foreground">{description}</p>
                ) : null}
            </div>
            <div className="p-6">{children}</div>
        </section>
    );
}

const AUDIENCE_TYPES = [
    {
        value: 'all_employees',
        label: 'All employees',
        description: 'Every active employee in the system',
        Icon: Globe,
        color: 'text-success',
        bgColor: 'bg-success/10',
        borderColor: 'border-success/40',
    },
    {
        value: 'department',
        label: 'By department',
        description: 'Pick one or more departments',
        Icon: Building2,
        color: 'text-blue-500',
        bgColor: 'bg-blue-500/10',
        borderColor: 'border-blue-500/40',
    },
    {
        value: 'branch',
        label: 'By branch',
        description: 'Pick one or more branches',
        Icon: GitBranch,
        color: 'text-violet-500',
        bgColor: 'bg-violet-500/10',
        borderColor: 'border-violet-500/40',
    },
    {
        value: 'position',
        label: 'By position',
        description: 'Pick one or more job positions',
        Icon: Briefcase,
        color: 'text-orange-500',
        bgColor: 'bg-orange-500/10',
        borderColor: 'border-orange-500/40',
    },
    {
        value: 'employee',
        label: 'Specific employees',
        description: 'Hand-pick individual employees',
        Icon: UserCheck,
        color: 'text-primary',
        bgColor: 'bg-primary/10',
        borderColor: 'border-primary/40',
    },
] as const;

type DepartmentItem = {
    id: number;
    name: string;
    parent_id?: number | null;
};

function DepartmentTreeNode({
    item,
    childrenMap,
    getFamilyIds,
    selectedIds,
    onToggleBatch,
    search,
    depth = 0,
}: {
    item: DepartmentItem;
    childrenMap: Map<number, DepartmentItem[]>;
    getFamilyIds: (id: number) => number[];
    selectedIds: number[];
    onToggleBatch: (type: string, ids: number[], checked: boolean) => void;
    search: string;
    depth?: number;
}) {
    const children = childrenMap.get(item.id) || [];
    const hasChildren = children.length > 0;
    const [expanded, setExpanded] = useState(true);

    const familyIds = useMemo(
        () => getFamilyIds(item.id),
        [getFamilyIds, item.id],
    );
    const selectedFamilyCount = useMemo(
        () => familyIds.filter((id) => selectedIds.includes(id)).length,
        [familyIds, selectedIds],
    );

    const isFullySelected =
        familyIds.length > 0 && selectedFamilyCount === familyIds.length;
    const isPartiallySelected =
        selectedFamilyCount > 0 && !isFullySelected;
    const isSelfSelected = selectedIds.includes(item.id);

    // Search filter logic
    const matchesSearch = item.name
        .toLowerCase()
        .includes(search.toLowerCase());
    const hasMatchingDescendant = useMemo(() => {
        if (!search) {
            return false;
        }

        const checkMatch = (id: number): boolean => {
            const childs = childrenMap.get(id) || [];

            return childs.some(
                (c) =>
                    c.name.toLowerCase().includes(search.toLowerCase()) ||
                    checkMatch(c.id),
            );
        };

        return checkMatch(item.id);
    }, [childrenMap, item.id, search]);

    if (search && !matchesSearch && !hasMatchingDescendant) {
        return null;
    }

    const handleToggle = () => {
        if (hasChildren) {
            // Clicking parent toggles all children in family
            onToggleBatch('department', familyIds, !isFullySelected);
        } else {
            onToggleBatch('department', [item.id], !isSelfSelected);
        }
    };

    return (
        <div className="space-y-1">
            <div
                className={cn(
                    'flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm transition-colors hover:bg-muted/50',
                    isFullySelected && 'bg-primary/5 font-medium text-foreground',
                    isPartiallySelected && 'bg-primary/5 text-foreground',
                )}
                style={{ paddingLeft: `${depth * 1.25 + 0.625}rem` }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => setExpanded(!expanded)}
                        className="flex size-5 shrink-0 items-center justify-center rounded-md p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        {expanded ? (
                            <ChevronDown className="size-3.5" />
                        ) : (
                            <ChevronRight className="size-3.5" />
                        )}
                    </button>
                ) : (
                    <span className="size-5 shrink-0" />
                )}

                <Checkbox
                    checked={
                        isFullySelected
                            ? true
                            : isPartiallySelected
                              ? 'indeterminate'
                              : false
                    }
                    onCheckedChange={handleToggle}
                />

                <div
                    className="flex flex-1 cursor-pointer items-center gap-2 min-w-0 select-none"
                    onClick={handleToggle}
                >
                    {hasChildren ? (
                        expanded ? (
                            <FolderOpen className="size-4 shrink-0 text-blue-500" />
                        ) : (
                            <Folder className="size-4 shrink-0 text-blue-500" />
                        )
                    ) : null}
                    <span className="min-w-0 flex-1 truncate">{item.name}</span>
                </div>

                {hasChildren ? (
                    <span className="shrink-0 font-mono text-[11px] font-medium text-muted-foreground bg-muted/60 px-2 py-0.5 rounded-full">
                        {selectedFamilyCount > 0
                            ? `${selectedFamilyCount}/${familyIds.length}`
                            : `${children.length} sub-dept${children.length !== 1 ? 's' : ''}`}
                    </span>
                ) : isSelfSelected ? (
                    <CheckCircle2 className="size-3.5 shrink-0 text-primary" />
                ) : null}
            </div>

            {hasChildren && (expanded || search) ? (
                <div className="relative ml-4 border-l border-border/40 pl-1">
                    {children.map((child) => (
                        <DepartmentTreeNode
                            key={child.id}
                            item={child}
                            childrenMap={childrenMap}
                            getFamilyIds={getFamilyIds}
                            selectedIds={selectedIds}
                            onToggleBatch={onToggleBatch}
                            search={search}
                            depth={depth + 1}
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}

const MAX_VISIBLE_AUDIENCE_CHIPS = 8;

function DepartmentTreePicker({
    items,
    selectedIds,
    onToggleBatch,
    onClear,
}: {
    items: DepartmentItem[];
    selectedIds: number[];
    onToggleBatch: (type: string, ids: number[], checked: boolean) => void;
    onClear: () => void;
}) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(true);

    const childrenMap = useMemo(() => {
        const map = new Map<number, DepartmentItem[]>();
        items.forEach((item) => {
            if (item.parent_id) {
                const list = map.get(item.parent_id) || [];
                list.push(item);
                map.set(item.parent_id, list);
            }
        });

        return map;
    }, [items]);

    const itemIdsSet = useMemo(
        () => new Set(items.map((i) => i.id)),
        [items],
    );

    const rootItems = useMemo(
        () =>
            items.filter(
                (item) => !item.parent_id || !itemIdsSet.has(item.parent_id),
            ),
        [items, itemIdsSet],
    );

    const getFamilyIds = useCallback(
        (id: number): number[] => {
            const collect = (currentId: number): number[] => {
                const family = [currentId];
                const children = childrenMap.get(currentId) || [];
                children.forEach((child) => {
                    family.push(...collect(child.id));
                });

                return family;
            };

            return collect(id);
        },
        [childrenMap],
    );

    const allSelected =
        items.length > 0 && items.every((item) => selectedIds.includes(item.id));
    const selectedItems = items.filter((item) =>
        selectedIds.includes(item.id),
    );
    const visibleSelectedItems = allSelected
        ? []
        : selectedItems.slice(0, MAX_VISIBLE_AUDIENCE_CHIPS);
    const hiddenSelectedCount = allSelected
        ? 0
        : Math.max(0, selectedItems.length - visibleSelectedItems.length);

    const toggleSelectAll = () => {
        const allIds = items.map((item) => item.id);
        onToggleBatch('department', allIds, !allSelected);
    };

    return (
        <div className="space-y-3">
            {selectedIds.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                    {allSelected ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-blue-500/30 bg-blue-500/8 px-3 py-1 text-xs font-medium text-blue-600 dark:text-blue-400">
                            <Building2 className="size-3 opacity-70" />
                            All {items.length} departments selected
                        </span>
                    ) : (
                        <>
                            {visibleSelectedItems.map((item) => (
                                <span
                                    key={item.id}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-blue-500/30 bg-blue-500/8 px-3 py-1 text-xs font-medium text-blue-600 dark:text-blue-400"
                                >
                                    <Building2 className="size-3 opacity-70" />
                                    {item.name}
                                    <button
                                        type="button"
                                        className="ml-0.5 rounded-full opacity-60 transition-opacity hover:opacity-100"
                                        onClick={() =>
                                            onToggleBatch(
                                                'department',
                                                [item.id],
                                                false,
                                            )
                                        }
                                    >
                                        <X className="size-3" />
                                    </button>
                                </span>
                            ))}
                            {hiddenSelectedCount > 0 ? (
                                <span className="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-3 py-1 text-xs text-muted-foreground">
                                    +{hiddenSelectedCount} more
                                </span>
                            ) : null}
                        </>
                    )}
                    <button
                        type="button"
                        className="text-xs text-muted-foreground hover:text-destructive"
                        onClick={onClear}
                    >
                        Clear all
                    </button>
                </div>
            ) : null}

            {/* Tree Container */}
            <div className="rounded-xl border border-border/70 bg-muted/10">
                <button
                    type="button"
                    className="flex w-full items-center justify-between px-4 py-3 text-sm font-medium"
                    onClick={() => setOpen((o) => !o)}
                >
                    <span className="flex items-center gap-2 text-muted-foreground">
                        <Building2 className="size-4 text-blue-500" />
                        {selectedIds.length === 0
                            ? `Select from ${items.length} departments (tree view)`
                            : `${selectedIds.length} of ${items.length} departments selected`}
                    </span>
                    <ChevronDown
                        className={cn(
                            'size-4 text-muted-foreground transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>

                {open ? (
                    <div className="border-t border-border/60 px-3 pb-3">
                        {/* Search + Select All bar */}
                        <div className="flex items-center gap-2 py-2">
                            {items.length > 5 ? (
                                <Input
                                    placeholder="Search departments…"
                                    value={search}
                                    onChange={(e) =>
                                        setSearch(e.target.value)
                                    }
                                    className="h-8 flex-1 text-xs"
                                />
                            ) : null}
                            <button
                                type="button"
                                className="ml-auto shrink-0 text-xs font-semibold text-primary hover:underline"
                                onClick={toggleSelectAll}
                            >
                                {allSelected ? 'Deselect all' : 'Select all'}
                            </button>
                        </div>

                        <div className="max-h-64 overflow-y-auto space-y-1 pr-1">
                            {rootItems.length === 0 ? (
                                <p className="py-4 text-center text-xs text-muted-foreground">
                                    No departments available
                                </p>
                            ) : null}
                            {rootItems.map((root) => (
                                <DepartmentTreeNode
                                    key={root.id}
                                    item={root}
                                    childrenMap={childrenMap}
                                    getFamilyIds={getFamilyIds}
                                    selectedIds={selectedIds}
                                    onToggleBatch={onToggleBatch}
                                    search={search}
                                />
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function AudiencePicker({
    type,
    items,
    selectedIds,
    onToggleBatch,
    onClear,
    onSelectAll,
}: {
    type: string;
    items: { id: number; name: string; employee_no?: string | null }[];
    selectedIds: number[];
    onToggleBatch: (type: string, ids: number[], checked: boolean) => void;
    onClear: () => void;
    onSelectAll?: () => void;
}) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(true);
    const filtered = items.filter((item) =>
        item.name.toLowerCase().includes(search.toLowerCase()),
    );
    const allSelected = items.length > 0 && selectedIds.length === items.length;
    const selectedItems = items.filter((item) => selectedIds.includes(item.id));
    const visibleSelectedItems = allSelected
        ? []
        : selectedItems.slice(0, MAX_VISIBLE_AUDIENCE_CHIPS);
    const hiddenSelectedCount = allSelected
        ? 0
        : Math.max(0, selectedItems.length - visibleSelectedItems.length);

    const toggleAll = () => {
        if (!allSelected && onSelectAll) {
            onSelectAll();

            return;
        }

        onToggleBatch(
            type,
            items.map((item) => item.id),
            !allSelected,
        );
    };

    return (
        <div className="space-y-3">
            {selectedIds.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                    {allSelected ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/8 px-3 py-1 text-xs font-medium text-primary">
                            All {items.length} selected
                        </span>
                    ) : (
                        <>
                            {visibleSelectedItems.map((item) => (
                                <span
                                    key={item.id}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/8 px-3 py-1 text-xs font-medium text-primary"
                                >
                                    {item.name}
                                    {item.employee_no ? (
                                        <span className="opacity-60">
                                            #{item.employee_no}
                                        </span>
                                    ) : null}
                                    <button
                                        type="button"
                                        className="ml-0.5 rounded-full opacity-60 transition-opacity hover:opacity-100"
                                        onClick={() =>
                                            onToggleBatch(type, [item.id], false)
                                        }
                                    >
                                        <X className="size-3" />
                                    </button>
                                </span>
                            ))}
                            {hiddenSelectedCount > 0 ? (
                                <span className="inline-flex items-center rounded-full border border-border/70 bg-muted/40 px-3 py-1 text-xs text-muted-foreground">
                                    +{hiddenSelectedCount} more
                                </span>
                            ) : null}
                        </>
                    )}
                    <button
                        type="button"
                        className="text-xs text-muted-foreground hover:text-destructive"
                        onClick={onClear}
                    >
                        Clear all
                    </button>
                </div>
            ) : null}

            {/* Collapsible list */}
            <div className="rounded-xl border border-border/70 bg-muted/10">
                <button
                    type="button"
                    className="flex w-full items-center justify-between px-4 py-3 text-sm font-medium"
                    onClick={() => setOpen((o) => !o)}
                >
                    <span className="text-muted-foreground">
                        {selectedIds.length === 0
                            ? `Choose from ${items.length} option${items.length !== 1 ? 's' : ''}`
                            : `${selectedIds.length} of ${items.length} selected`}
                    </span>
                    <ChevronDown
                        className={cn(
                            'size-4 text-muted-foreground transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>

                {open ? (
                    <div className="border-t border-border/60 px-3 pb-3">
                        {/* Search + select-all row */}
                        <div className="flex items-center gap-2 py-2">
                            {items.length > 5 ? (
                                <Input
                                    placeholder="Search…"
                                    value={search}
                                    onChange={(e) =>
                                        setSearch(e.target.value)
                                    }
                                    className="h-7 flex-1 text-xs"
                                />
                            ) : null}
                            <button
                                type="button"
                                className="ml-auto shrink-0 text-xs font-semibold text-primary hover:underline"
                                onClick={toggleAll}
                            >
                                {allSelected ? 'Deselect all' : 'Select all'}
                            </button>
                        </div>

                        <div className="grid max-h-52 gap-0.5 overflow-y-auto">
                            {filtered.length === 0 ? (
                                <p className="py-4 text-center text-xs text-muted-foreground">
                                    No results
                                </p>
                            ) : null}
                            {filtered.map((item) => {
                                const isChecked = selectedIds.includes(
                                    item.id,
                                );

                                return (
                                    <label
                                        key={item.id}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-sm transition-colors hover:bg-muted/50',
                                            isChecked &&
                                                'bg-primary/5 font-medium',
                                        )}
                                    >
                                        <Checkbox
                                            checked={isChecked}
                                            onCheckedChange={(checked) =>
                                                onToggleBatch(
                                                    type,
                                                    [item.id],
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {item.name}
                                        </span>
                                        {item.employee_no ? (
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                #{item.employee_no}
                                            </span>
                                        ) : null}
                                        {isChecked ? (
                                            <CheckCircle2 className="size-3.5 shrink-0 text-primary" />
                                        ) : null}
                                    </label>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export default function AnnouncementFormPage({
    announcement,
    options,
}: {
    announcement: AnnouncementFormPayload | null;
    options: AnnouncementFormOptions;
    can: AnnouncementCan;
}) {
    const isEdit = announcement !== null;
    const http = useHttp<{
        channels: string[];
        audiences: { type: string; id: number | null }[];
    }>({
        channels: ['in_app'],
        audiences: [{ type: 'all_employees', id: null }],
    });
    const [preview, setPreview] = useState<RecipientPreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const previewDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [activeAudienceType, setActiveAudienceType] = useState<string>(
        announcement?.audiences.some((a) => a.type === 'all_employees')
            ? 'all_employees'
            : (announcement?.audiences[0]?.type ?? 'all_employees'),
    );

    const form = useForm<AnnouncementFormData>({
        title: announcement?.title ?? '',
        body_html: announcement?.body_html ?? '',
        category: announcement?.category ?? 'general',
        priority: announcement?.priority ?? 'normal',
        channels: announcement?.channels ?? ['in_app'],
        audiences: announcement?.audiences?.length
            ? announcement.audiences
            : [{ type: 'all_employees', id: null }],
        expires_at: announcement?.expires_at ?? '',
        publish_mode:
            announcement?.status === 'scheduled' ? 'schedule' : 'draft',
        scheduled_at: announcement?.scheduled_at ?? '',
    });

    const toggleChannel = (channel: string, checked: boolean) => {
        const next = checked
            ? [...form.data.channels, channel]
            : form.data.channels.filter((c) => c !== channel);
        form.setData('channels', next);
    };

    const selectAudienceType = (type: string) => {
        setActiveAudienceType(type);

        if (type === 'all_employees') {
            form.setData('audiences', [{ type: 'all_employees', id: null }]);

            return;
        }

        form.setData('audiences', []);
    };

    const clearAudienceType = (type: string) => {
        form.setData(
            'audiences',
            form.data.audiences.filter((a) => a.type !== type),
        );
    };

    const toggleAudienceBatch = (
        type: string,
        idsToToggle: number[],
        checked: boolean,
    ) => {
        const otherAudiences = form.data.audiences.filter((a) => a.type !== type);
        const currentTypeIds = new Set(
            form.data.audiences
                .filter((a) => a.type === type)
                .map((a) => a.id)
                .filter((id): id is number => id !== null),
        );

        if (checked) {
            idsToToggle.forEach((id) => currentTypeIds.add(id));
        } else {
            idsToToggle.forEach((id) => currentTypeIds.delete(id));
        }

        if (
            type === 'employee' &&
            checked &&
            options.employees.length > 0 &&
            currentTypeIds.size === options.employees.length &&
            options.employees.every((employee) => currentTypeIds.has(employee.id))
        ) {
            setActiveAudienceType('all_employees');
            form.setData('audiences', [{ type: 'all_employees', id: null }]);

            return;
        }

        const newTypeAudiences = Array.from(currentTypeIds).map((id) => ({
            type,
            id,
        }));

        form.setData('audiences', [...otherAudiences, ...newTypeAudiences]);
    };

    const audiencesForRequest = (
        audiences: { type: string; id: number | null }[],
    ) => {
        if (audiences.some((audience) => audience.type === 'all_employees')) {
            return [{ type: 'all_employees', id: null }];
        }

        const employeeIds = audiences
            .filter((audience) => audience.type === 'employee')
            .map((audience) => audience.id)
            .filter((id): id is number => id !== null);
        const otherAudiences = audiences.filter(
            (audience) => audience.type !== 'employee',
        );

        if (
            otherAudiences.length === 0 &&
            options.employees.length > 0 &&
            employeeIds.length === options.employees.length &&
            options.employees.every((employee) => employeeIds.includes(employee.id))
        ) {
            return [{ type: 'all_employees', id: null }];
        }

        return audiences;
    };

    const previewRequestIdRef = useRef(0);

    const loadPreview = (channels: string[], audiences: { type: string; id: number | null }[]) => {
        const requestId = ++previewRequestIdRef.current;
        const requestAudiences = audiencesForRequest(audiences);

        if (requestAudiences.length === 0) {
            setPreview(null);
            setPreviewLoading(false);

            return;
        }

        setPreviewLoading(true);
        http.transform(() => ({ channels, audiences: requestAudiences }));
        http.post('/organization/announcements/preview-recipients')
            .then((data) => {
                if (requestId !== previewRequestIdRef.current) {
                    return;
                }

                setPreview(data as RecipientPreview);
            })
            .finally(() => {
                http.transform((data) => data);

                if (requestId === previewRequestIdRef.current) {
                    setPreviewLoading(false);
                }
            });
    };

    // Auto-refresh preview whenever channels or audiences change (debounced 500ms)
    useEffect(() => {
        if (previewDebounceRef.current) {
            clearTimeout(previewDebounceRef.current);
        }

        previewDebounceRef.current = setTimeout(() => {
            loadPreview(form.data.channels, form.data.audiences);
        }, 500);

        return () => {
            if (previewDebounceRef.current) {
                clearTimeout(previewDebounceRef.current);
            }
        };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.channels, form.data.audiences]);

    const submit = (mode: AnnouncementFormData['publish_mode']) => {
        const payload = {
            ...form.data,
            audiences: audiencesForRequest(form.data.audiences),
            publish_mode: mode,
        };

        if (isEdit && announcement) {
            router.put(
                `/organization/announcements/${announcement.id}`,
                payload,
            );

            return;
        }

        router.post('/organization/announcements', payload);
    };

    const selectedIds = (type: string) =>
        form.data.audiences
            .filter((a) => a.type === type)
            .map((a) => a.id)
            .filter((id): id is number => id !== null);

    const audienceOptions: Record<
        string,
        { id: number; name: string; employee_no?: string | null }[]
    > = {
        department: options.departments,
        branch: options.branches,
        position: options.positions,
        employee: options.employees,
    };

    const totalAudienceSelected = form.data.audiences.filter(
        (a) => a.type !== 'all_employees',
    ).length;

    return (
        <>
            <Head
                title={isEdit ? 'Edit announcement' : 'Create announcement'}
            />
            <Main>
                <PageHeader
                    title={isEdit ? 'Edit announcement' : 'Create announcement'}
                    description="Compose the message, choose audience and channels, then draft, schedule, or send."
                    kicker="Communications"
                />

                <div className="mx-auto max-w-5xl space-y-6">
                    {/* Tip banner */}
                    <div className="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4 text-sm text-muted-foreground">
                        <Megaphone className="mt-0.5 size-4 shrink-0 text-primary" />
                        <p>
                            Keep the title concise and make the first sentence
                            useful on its own. You can save a draft and finish
                            it later.
                        </p>
                    </div>

                    {/* Message content */}
                    <SectionCard
                        icon={<FileText className="size-4 text-primary" />}
                        title="Message content"
                    >
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    placeholder="e.g. Office closed on Friday, 25 July"
                                    value={form.data.title}
                                    onChange={(e) =>
                                        form.setData('title', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.title} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="body_html">Message body</Label>
                                <Textarea
                                    id="body_html"
                                    rows={8}
                                    placeholder="Write the full announcement here..."
                                    value={form.data.body_html}
                                    onChange={(e) =>
                                        form.setData('body_html', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.body_html} />
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Category</Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(value) =>
                                            form.setData('category', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.categories.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Priority</Label>
                                    <Select
                                        value={form.data.priority}
                                        onValueChange={(value) =>
                                            form.setData('priority', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.priorities.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="expires_at">
                                        Expiry date{' '}
                                        <span className="text-xs text-muted-foreground">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="expires_at"
                                        type="datetime-local"
                                        value={form.data.expires_at}
                                        onChange={(e) =>
                                            form.setData('expires_at', e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                    </SectionCard>

                    {/* Audience */}
                    <SectionCard
                        icon={<Users className="size-4 text-primary" />}
                        title="Audience"
                        description="Choose who should receive this announcement."
                    >
                        <div className="space-y-4">
                            {/* Audience type cards */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                {AUDIENCE_TYPES.map((type) => {
                                    const isActive =
                                        activeAudienceType === type.value;
                                    const count =
                                        type.value === 'all_employees'
                                            ? null
                                            : audienceOptions[type.value]
                                                  ?.length ?? 0;
                                    const selectedCount =
                                        type.value !== 'all_employees'
                                            ? selectedIds(type.value).length
                                            : null;

                                    return (
                                        <button
                                            key={type.value}
                                            type="button"
                                            onClick={() =>
                                                selectAudienceType(type.value)
                                            }
                                            className={cn(
                                                'flex flex-col items-start gap-2 rounded-xl border p-3.5 text-left text-sm transition-all',
                                                isActive
                                                    ? [
                                                          type.borderColor,
                                                          type.bgColor,
                                                          'shadow-sm',
                                                      ]
                                                    : 'border-border/60 hover:border-border hover:bg-muted/30',
                                            )}
                                        >
                                            <div
                                                className={cn(
                                                    'flex size-8 items-center justify-center rounded-lg',
                                                    isActive
                                                        ? [
                                                              type.bgColor,
                                                              type.color,
                                                          ]
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                <type.Icon className="size-4" />
                                            </div>
                                            <div className="w-full">
                                                <div className="flex items-center justify-between gap-1">
                                                    <span
                                                        className={cn(
                                                            'font-medium leading-tight',
                                                            isActive
                                                                ? type.color
                                                                : 'text-foreground',
                                                        )}
                                                    >
                                                        {type.label}
                                                    </span>
                                                    {count !== null ? (
                                                        <span
                                                            className={cn(
                                                                'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                                                isActive
                                                                    ? [
                                                                          type.bgColor,
                                                                          type.color,
                                                                      ]
                                                                    : 'bg-muted text-muted-foreground',
                                                            )}
                                                        >
                                                            {selectedCount
                                                                ? `${selectedCount}/${count}`
                                                                : count}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="mt-0.5 text-xs text-muted-foreground leading-tight">
                                                    {type.description}
                                                </p>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>

                            {/* All employees confirmation */}
                            {activeAudienceType === 'all_employees' ? (
                                <div className="flex items-center gap-3 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-success">
                                    <Globe className="size-4 shrink-0" />
                                    <span>
                                        This announcement will be sent to{' '}
                                        <strong>all active employees</strong>{' '}
                                        in the system.
                                    </span>
                                </div>
                            ) : null}

                            {/* Sub-picker for non-all modes */}
                            {activeAudienceType === 'department' ? (
                                <DepartmentTreePicker
                                    items={options.departments}
                                    selectedIds={selectedIds('department')}
                                    onToggleBatch={toggleAudienceBatch}
                                    onClear={() =>
                                        clearAudienceType('department')
                                    }
                                />
                            ) : activeAudienceType !== 'all_employees' &&
                              audienceOptions[activeAudienceType] ? (
                                <AudiencePicker
                                    type={activeAudienceType}
                                    items={
                                        audienceOptions[activeAudienceType]
                                    }
                                    selectedIds={selectedIds(
                                        activeAudienceType,
                                    )}
                                    onToggleBatch={toggleAudienceBatch}
                                    onClear={() =>
                                        clearAudienceType(activeAudienceType)
                                    }
                                    onSelectAll={
                                        activeAudienceType === 'employee'
                                            ? () =>
                                                  selectAudienceType(
                                                      'all_employees',
                                                  )
                                            : undefined
                                    }
                                />
                            ) : null}

                            {/* Validation warning when nothing chosen in non-all mode */}
                            {activeAudienceType !== 'all_employees' &&
                            totalAudienceSelected === 0 ? (
                                <p className="text-xs text-warning">
                                    Select at least one option to continue.
                                </p>
                            ) : null}

                            <InputError message={form.errors.audiences} />
                        </div>
                    </SectionCard>

                    {/* Delivery channels */}
                    <SectionCard
                        icon={<Send className="size-4 text-primary" />}
                        title="Delivery channels"
                        description="Select at least one channel for delivery."
                    >
                        <div className="grid gap-3 sm:grid-cols-3">
                            {CHANNELS.map((channel) => {
                                const isChecked = form.data.channels.includes(
                                    channel.value,
                                );

                                return (
                                    <label
                                        key={channel.value}
                                        className={cn(
                                            'flex cursor-pointer flex-col gap-3 rounded-xl border p-4 text-sm transition-all',
                                            isChecked
                                                ? 'border-primary/50 bg-primary/5 text-foreground shadow-sm'
                                                : 'border-border/70 hover:bg-muted/40',
                                        )}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div
                                                className={cn(
                                                    'flex size-9 items-center justify-center rounded-lg',
                                                    isChecked
                                                        ? 'bg-primary/15 text-primary'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                <channel.Icon className="size-4" />
                                            </div>
                                            <Checkbox
                                                checked={isChecked}
                                                onCheckedChange={(checked) =>
                                                    toggleChannel(
                                                        channel.value,
                                                        Boolean(checked),
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <p className="font-medium">{channel.label}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {channel.description}
                                            </p>
                                        </div>
                                    </label>
                                );
                            })}
                        </div>
                        <InputError message={form.errors.channels} />
                    </SectionCard>

                    {/* Attachments (edit mode only) */}
                    {isEdit && announcement ? (
                        <SectionCard
                            icon={<Upload className="size-4 text-primary" />}
                            title="Attachments"
                        >
                            <div className="space-y-3">
                                {announcement.attachments.length > 0 ? (
                                    <ul className="space-y-2">
                                        {announcement.attachments.map((attachment) => (
                                            <li
                                                key={attachment.id}
                                                className="flex items-center justify-between rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5 text-sm"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <FileText className="size-4 text-muted-foreground" />
                                                    <span>{attachment.original_name}</span>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/organization/announcements/${announcement.id}/attachments/${attachment.id}`,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-3.5" />
                                                    Remove
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No attachments yet.
                                    </p>
                                )}
                                <div className="rounded-xl border border-dashed border-border/70 p-4">
                                    <Label
                                        htmlFor="attachment-upload"
                                        className="flex cursor-pointer flex-col items-center gap-2 text-center text-sm text-muted-foreground"
                                    >
                                        <Upload className="size-5" />
                                        <span>Click to upload a file</span>
                                    </Label>
                                    <Input
                                        id="attachment-upload"
                                        type="file"
                                        className="sr-only"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];

                                            if (!file) {
                                                return;
                                            }

                                            const data = new FormData();
                                            data.append('attachment', file);
                                            router.post(
                                                `/organization/announcements/${announcement.id}/attachments`,
                                                data,
                                                { forceFormData: true },
                                            );
                                        }}
                                    />
                                </div>
                            </div>
                        </SectionCard>
                    ) : null}

                    {/* Publishing */}
                    <SectionCard
                        icon={<CalendarClock className="size-4 text-primary" />}
                        title="Publishing"
                        description="Choose how and when this announcement should go out."
                    >
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label htmlFor="scheduled_at">
                                    Schedule for later{' '}
                                    <span className="text-xs text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="scheduled_at"
                                    type="datetime-local"
                                    value={form.data.scheduled_at}
                                    onChange={(e) =>
                                        form.setData('scheduled_at', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.scheduled_at} />
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-medium">Recipient preview</p>
                                    {previewLoading ? (
                                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <span className="size-1.5 animate-pulse rounded-full bg-primary" />
                                            Updating…
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">Live</span>
                                    )}
                                </div>
                                <div
                                    className={cn(
                                        'rounded-xl border border-border/70 bg-muted/20 px-4 py-3 text-sm transition-opacity',
                                        previewLoading ? 'opacity-50' : 'opacity-100',
                                    )}
                                >
                                    {previewLoading && !preview ? (
                                        <div className="space-y-1.5">
                                            <div className="h-3 w-28 animate-pulse rounded bg-muted" />
                                            <div className="h-6 w-12 animate-pulse rounded bg-muted" />
                                        </div>
                                    ) : (
                                        <>
                                            <div className="text-xs text-muted-foreground">
                                                Selected employees
                                            </div>
                                            <div className="mt-0.5 text-lg font-bold">
                                                {preview?.selected_employees ?? 0}
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>

                            {/* Submit actions */}
                            <div className="flex flex-col-reverse gap-2 border-t border-border/60 pt-5 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => submit('draft')}
                                >
                                    <FileText className="size-4" /> Save as draft
                                </Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={
                                        form.processing ||
                                        !form.data.scheduled_at
                                    }
                                    onClick={() => submit('schedule')}
                                >
                                    <CalendarClock className="size-4" /> Schedule
                                </Button>
                                <Button
                                    type="button"
                                    disabled={form.processing}
                                    onClick={() => submit('send_now')}
                                >
                                    <CheckCircle2 className="size-4" /> Send now
                                </Button>
                            </div>
                        </div>
                    </SectionCard>
                </div>
            </Main>
        </>
    );
}
