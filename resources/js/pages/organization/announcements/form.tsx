import { Head, router, useForm, useHttp } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    FileText,
    FlaskConical,
    Folder,
    FolderOpen,
    GitBranch,
    Globe,
    Mail,
    MessageCircle,
    Send,
    Smartphone,
    Sparkles,
    Trash2,
    Upload,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
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
import { AnnouncementAiAssistDialog } from '@/features/organization/announcements/announcement-ai-assist-dialog';
import { AnnouncementMessageEditorSkeleton } from '@/features/organization/announcements/announcement-message-editor-skeleton';
import { EmailPreview } from '@/features/organization/announcements/email-preview';
import { SendAnnouncementTestDialog } from '@/features/organization/announcements/send-announcement-test-dialog';
import type {
    AnnouncementAiAssistAction,
    AnnouncementAiAssistResponse,
    AnnouncementAiAssistResult,
    AnnouncementCan,
    AnnouncementChannelPreviewResponse,
    AnnouncementChannelPreviews,
    AnnouncementFormData,
    AnnouncementFormOptions,
    AnnouncementFormPayload,
    AnnouncementTestSendResponse,
    RecipientPreview,
} from '@/features/organization/announcements/types';
import { WhatsAppDocumentTemplatePreview } from '@/features/settings/whatsapp-document-template-preview';
import { cn } from '@/lib/utils';
import {
    aiAssist as announcementAiAssist,
    previewChannels as previewAnnouncementChannels,
    sendTest as sendAnnouncementTest,
} from '@/routes/organization/announcements';

/**
 * Tiptap and ProseMirror are the heaviest dependency on this page, so the
 * editor is split out of the initial page chunk.
 */
const LazyAnnouncementMessageEditor = lazy(() =>
    import('@/features/organization/announcements/announcement-message-editor').then(
        (module) => ({
            default: module.AnnouncementMessageEditor,
        }),
    ),
);

const CHANNELS = [
    {
        value: 'in_app',
        label: 'In-app',
        Icon: Smartphone,
        description: 'Notification inside the app',
        activeClass:
            'border-primary/45 bg-primary/5 shadow-[0_0_0_1px] shadow-primary/15',
        iconActiveClass: 'bg-primary/15 text-primary',
    },
    {
        value: 'email',
        label: 'Email',
        Icon: Mail,
        description: 'Sent to employee email',
        activeClass:
            'border-sky-500/45 bg-sky-500/5 shadow-[0_0_0_1px] shadow-sky-500/15',
        iconActiveClass: 'bg-sky-500/15 text-sky-500',
    },
    {
        value: 'whatsapp',
        label: 'WhatsApp',
        Icon: MessageCircle,
        description: 'Message to registered phone',
        activeClass:
            'border-emerald-500/45 bg-emerald-500/5 shadow-[0_0_0_1px] shadow-emerald-500/15',
        iconActiveClass: 'bg-emerald-500/15 text-emerald-500',
    },
] as const;

function SectionCard({
    step,
    icon,
    title,
    description,
    children,
    className,
    headerRight,
}: {
    step?: number;
    icon: React.ReactNode;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string;
    headerRight?: React.ReactNode;
}) {
    return (
        <section className={cn('rounded-xl border glass-card', className)}>
            <div className="flex items-start justify-between gap-4 border-b border-border/60 bg-muted/20 px-5 py-4 sm:px-6">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2.5 text-base font-semibold">
                        {step ? (
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[11px] font-bold text-primary">
                                {step}
                            </span>
                        ) : null}
                        <span className="flex items-center gap-2">
                            {icon}
                            {title}
                        </span>
                    </h2>
                    {description ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {headerRight ? (
                    <div className="shrink-0">{headerRight}</div>
                ) : null}
            </div>
            <div className="p-5 sm:p-6">{children}</div>
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
    const isPartiallySelected = selectedFamilyCount > 0 && !isFullySelected;
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
                    isFullySelected &&
                        'bg-primary/5 font-medium text-foreground',
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
                    className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 select-none"
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
                    <span className="shrink-0 rounded-full bg-muted/60 px-2 py-0.5 font-mono text-[11px] font-medium text-muted-foreground">
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

    const itemIdsSet = useMemo(() => new Set(items.map((i) => i.id)), [items]);

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
        items.length > 0 &&
        items.every((item) => selectedIds.includes(item.id));
    const selectedItems = items.filter((item) => selectedIds.includes(item.id));
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
                                    onChange={(e) => setSearch(e.target.value)}
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

                        <div className="max-h-64 space-y-1 overflow-y-auto pr-1">
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
                                            onToggleBatch(
                                                type,
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
                                    onChange={(e) => setSearch(e.target.value)}
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
                                const isChecked = selectedIds.includes(item.id);

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
    can,
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
    const testHttp = useHttp<{
        title: string;
        body_html: string;
        category: string;
        priority: string;
        whatsapp_link: string | null;
        whatsapp_message: string | null;
        whatsapp_template_id: number | null;
        channels: string[];
        announcement_id: number | null;
    }>({
        title: '',
        body_html: '',
        category: 'general',
        priority: 'normal',
        whatsapp_link: null,
        whatsapp_message: null,
        whatsapp_template_id: null,
        channels: [],
        announcement_id: null,
    });
    const channelPreviewHttp = useHttp<{
        title: string;
        body_html: string;
        category: string;
        priority: string;
        channels: string[];
        whatsapp_link: string | null;
        whatsapp_message: string | null;
        whatsapp_template_id: number | null;
    }>({
        title: '',
        body_html: '',
        category: 'general',
        priority: 'normal',
        channels: [],
        whatsapp_link: null,
        whatsapp_message: null,
        whatsapp_template_id: null,
    });
    const aiAssistHttp = useHttp<{
        action: AnnouncementAiAssistAction;
        instructions: string | null;
        title: string | null;
        body_html: string | null;
        whatsapp_message: string | null;
    }>({
        action: 'improve',
        instructions: null,
        title: null,
        body_html: null,
        whatsapp_message: null,
    });
    const [preview, setPreview] = useState<RecipientPreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const previewDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(
        null,
    );
    const channelPreviewDebounceRef = useRef<ReturnType<
        typeof setTimeout
    > | null>(null);
    const [channelPreviews, setChannelPreviews] =
        useState<AnnouncementChannelPreviews | null>(null);
    const [channelPreviewLoading, setChannelPreviewLoading] = useState(false);
    const [testDialogOpen, setTestDialogOpen] = useState(false);
    const [testSending, setTestSending] = useState(false);
    const [testError, setTestError] = useState<string | null>(null);
    const [testResult, setTestResult] =
        useState<AnnouncementTestSendResponse | null>(null);
    const [aiDialogOpen, setAiDialogOpen] = useState(false);
    const [aiProcessing, setAiProcessing] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiResult, setAiResult] = useState<AnnouncementAiAssistResult | null>(
        null,
    );
    const [pendingSuggestedTemplateId, setPendingSuggestedTemplateId] =
        useState<number | null>(null);
    const [activeAudienceType, setActiveAudienceType] = useState<string>(
        announcement?.audiences.some((a) => a.type === 'all_employees')
            ? 'all_employees'
            : (announcement?.audiences[0]?.type ?? 'all_employees'),
    );

    const defaultWhatsAppTemplateId =
        announcement?.whatsapp_template_id ??
        options.whatsapp_templates.find((template) => template.is_legacy)?.id ??
        options.whatsapp_templates[0]?.id ??
        null;

    const form = useForm<AnnouncementFormData>({
        title: announcement?.title ?? '',
        body_html: announcement?.body_html ?? '',
        category: announcement?.category ?? 'general',
        priority: announcement?.priority ?? 'normal',
        channels: announcement?.channels ?? ['in_app'],
        whatsapp_link: announcement?.whatsapp_link ?? '',
        whatsapp_message: announcement?.whatsapp_message ?? '',
        whatsapp_template_id: defaultWhatsAppTemplateId,
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
        form.setData((data) => ({
            ...data,
            channels: next,
            whatsapp_link: next.includes('whatsapp') ? data.whatsapp_link : '',
            whatsapp_message: next.includes('whatsapp')
                ? data.whatsapp_message
                : '',
            whatsapp_template_id: next.includes('whatsapp')
                ? (data.whatsapp_template_id ?? defaultWhatsAppTemplateId)
                : null,
        }));
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
        const otherAudiences = form.data.audiences.filter(
            (a) => a.type !== type,
        );
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
            options.employees.every((employee) =>
                currentTypeIds.has(employee.id),
            )
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
            options.employees.every((employee) =>
                employeeIds.includes(employee.id),
            )
        ) {
            return [{ type: 'all_employees', id: null }];
        }

        return audiences;
    };

    const previewRequestIdRef = useRef(0);

    const loadPreview = (
        channels: string[],
        audiences: { type: string; id: number | null }[],
    ) => {
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

    const isAllEmployees = form.data.audiences.some(
        (audience) => audience.type === 'all_employees',
    );

    const audienceSummary = isAllEmployees
        ? 'All employees'
        : totalAudienceSelected > 0
          ? `${totalAudienceSelected} selected`
          : 'No audience selected';

    const whatsappSelected = form.data.channels.includes('whatsapp');
    const emailSelected = form.data.channels.includes('email');
    const canSendTest =
        can.publish &&
        (emailSelected || whatsappSelected) &&
        form.data.title.trim() !== '' &&
        form.data.body_html.trim() !== '';

    const openTestDialog = () => {
        setTestError(null);
        setTestResult(null);
        setTestDialogOpen(true);
    };

    const priorityLabel =
        options.priorities.find((option) => option.value === form.data.priority)
            ?.label ?? form.data.priority;

    const handleSendTest = (channels: Array<'email' | 'whatsapp'>) => {
        setTestSending(true);
        setTestError(null);
        setTestResult(null);

        testHttp.transform(() => ({
            title: form.data.title,
            body_html: form.data.body_html,
            category: form.data.category,
            priority: form.data.priority,
            whatsapp_link: whatsappSelected
                ? form.data.whatsapp_link || null
                : null,
            whatsapp_message: whatsappSelected
                ? form.data.whatsapp_message || null
                : null,
            whatsapp_template_id: whatsappSelected
                ? form.data.whatsapp_template_id
                : null,
            channels,
            announcement_id: announcement?.id ?? null,
        }));

        testHttp
            .post(sendAnnouncementTest.url())
            .then((data) => {
                setTestResult(data as AnnouncementTestSendResponse);
            })
            .catch(() => {
                setTestError('Test could not be sent.');
            })
            .finally(() => {
                testHttp.transform((data) => data);
                setTestSending(false);
            });
    };

    const selectedWhatsAppTemplate =
        options.whatsapp_templates.find(
            (template) => template.id === form.data.whatsapp_template_id,
        ) ?? null;

    useEffect(() => {
        if (!emailSelected && !whatsappSelected) {
            setChannelPreviews(null);

            return;
        }

        if (
            form.data.title.trim() === '' ||
            form.data.body_html.trim() === ''
        ) {
            return;
        }

        if (channelPreviewDebounceRef.current) {
            clearTimeout(channelPreviewDebounceRef.current);
        }

        channelPreviewDebounceRef.current = setTimeout(() => {
            setChannelPreviewLoading(true);
            channelPreviewHttp.transform(() => ({
                title: form.data.title,
                body_html: form.data.body_html,
                category: form.data.category,
                priority: form.data.priority,
                channels: form.data.channels,
                whatsapp_link: whatsappSelected
                    ? form.data.whatsapp_link || null
                    : null,
                whatsapp_message: whatsappSelected
                    ? form.data.whatsapp_message || null
                    : null,
                whatsapp_template_id: whatsappSelected
                    ? form.data.whatsapp_template_id
                    : null,
            }));

            channelPreviewHttp
                .post(previewAnnouncementChannels.url())
                .then((data) => {
                    const response = data as AnnouncementChannelPreviewResponse;
                    setChannelPreviews(response.channel_previews);
                })
                .catch(() => {
                    // Keep last successful preview; exact errors surface on Test Send.
                })
                .finally(() => {
                    channelPreviewHttp.transform((data) => data);
                    setChannelPreviewLoading(false);
                });
        }, 450);

        return () => {
            if (channelPreviewDebounceRef.current) {
                clearTimeout(channelPreviewDebounceRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        emailSelected,
        form.data.body_html,
        form.data.category,
        form.data.channels,
        form.data.priority,
        form.data.title,
        form.data.whatsapp_link,
        form.data.whatsapp_message,
        form.data.whatsapp_template_id,
        whatsappSelected,
    ]);

    const runAiAssist = (
        action: AnnouncementAiAssistAction,
        instructions: string,
    ) => {
        setAiProcessing(true);
        setAiError(null);
        setAiResult(null);

        aiAssistHttp.transform(() => ({
            action,
            instructions: instructions.trim() !== '' ? instructions : null,
            title: form.data.title || null,
            body_html: form.data.body_html || null,
            whatsapp_message: form.data.whatsapp_message || null,
        }));

        aiAssistHttp
            .post(announcementAiAssist.url())
            .then((data) => {
                const response = data as AnnouncementAiAssistResponse;
                setAiResult(response.result);

                if (response.result.suggested_template) {
                    setPendingSuggestedTemplateId(
                        response.result.suggested_template.id,
                    );
                }
            })
            .catch(() => {
                setAiError('AI assistance is temporarily unavailable.');
            })
            .finally(() => {
                aiAssistHttp.transform((data) => data);
                setAiProcessing(false);
            });
    };

    const applyAiContent = (result: AnnouncementAiAssistResult) => {
        form.setData((data) => ({
            ...data,
            title: result.title !== '' ? result.title : data.title,
            body_html:
                result.main_body !== '' ? result.main_body : data.body_html,
            whatsapp_message:
                result.whatsapp_message !== ''
                    ? result.whatsapp_message
                    : data.whatsapp_message,
        }));
    };

    const whatsappPreviewPayload = channelPreviews?.whatsapp ?? null;
    const emailPreviewPayload = channelPreviews?.email ?? null;

    const whatsappPreview = whatsappSelected ? (
        whatsappPreviewPayload?.available === false ? (
            <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
                {whatsappPreviewPayload.message ??
                    'WhatsApp template is not configured.'}
            </div>
        ) : (
            <WhatsAppDocumentTemplatePreview
                templateName={
                    whatsappPreviewPayload?.template_name ??
                    selectedWhatsAppTemplate?.meta_name ??
                    'announcement'
                }
                templateLanguage={
                    whatsappPreviewPayload?.template_language ??
                    selectedWhatsAppTemplate?.meta_language ??
                    'en'
                }
                bodyText={whatsappPreviewPayload?.body_text ?? ''}
                headerType={
                    (whatsappPreviewPayload?.header_type as
                        | 'document'
                        | 'text'
                        | 'none'
                        | undefined) ??
                    (selectedWhatsAppTemplate?.header_type as
                        | 'document'
                        | 'text'
                        | 'none'
                        | undefined) ??
                    'none'
                }
                headerText={whatsappPreviewPayload?.header_text ?? ''}
                accountName={options.company_name || 'Company'}
                hint={
                    channelPreviewLoading
                        ? 'Refreshing exact production preview…'
                        : 'Exact preview from the production WhatsApp builder.'
                }
            />
        )
    ) : null;

    const emailPreview = emailSelected ? (
        emailPreviewPayload ? (
            <EmailPreview
                subject={emailPreviewPayload.subject}
                html={emailPreviewPayload.html}
                accountName={options.company_name || 'Company'}
                hint={
                    channelPreviewLoading
                        ? 'Refreshing exact production preview…'
                        : 'Exact preview from the production email renderer.'
                }
            />
        ) : (
            <div className="rounded-xl border border-border/70 bg-muted/20 p-4 text-sm text-muted-foreground">
                {channelPreviewLoading
                    ? 'Loading email preview…'
                    : 'Enter a title and body to preview the email.'}
            </div>
        )
    ) : null;

    return (
        <>
            <Head
                title={isEdit ? 'Edit announcement' : 'Create announcement'}
            />
            <Main>
                <PageHeader
                    title={isEdit ? 'Edit announcement' : 'Create announcement'}
                    description="Write the message, pick channels and audience, then draft, schedule, or send."
                    kicker="Communications"
                />

                <div className="mx-auto max-w-6xl pb-28">
                    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20.5rem]">
                        <div className="space-y-5">
                            <SectionCard
                                step={1}
                                icon={<Send className="size-4 text-primary" />}
                                title="Delivery channels"
                                description="Choose at least one way to deliver this announcement."
                            >
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {CHANNELS.map((channel) => {
                                        const isChecked =
                                            form.data.channels.includes(
                                                channel.value,
                                            );

                                        return (
                                            <label
                                                key={channel.value}
                                                className={cn(
                                                    'group relative flex cursor-pointer flex-col gap-3 rounded-xl border p-4 text-sm transition-all',
                                                    isChecked
                                                        ? channel.activeClass
                                                        : 'border-border/70 hover:border-border hover:bg-muted/30',
                                                )}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div
                                                        className={cn(
                                                            'flex size-9 items-center justify-center rounded-lg transition-colors',
                                                            isChecked
                                                                ? channel.iconActiveClass
                                                                : 'bg-muted text-muted-foreground',
                                                        )}
                                                    >
                                                        <channel.Icon className="size-4" />
                                                    </div>
                                                    <Checkbox
                                                        checked={isChecked}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleChannel(
                                                                channel.value,
                                                                Boolean(
                                                                    checked,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <p className="font-medium">
                                                        {channel.label}
                                                    </p>
                                                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                                        {channel.description}
                                                    </p>
                                                </div>
                                            </label>
                                        );
                                    })}
                                </div>
                                <InputError message={form.errors.channels} />
                            </SectionCard>

                            <SectionCard
                                step={2}
                                icon={
                                    <FileText className="size-4 text-primary" />
                                }
                                title="Message content"
                                description="Title and body are shared across channels. Customize WhatsApp text and template when WhatsApp is selected."
                                headerRight={
                                    can.create || can.update ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setAiError(null);
                                                setAiResult(null);
                                                setAiDialogOpen(true);
                                            }}
                                        >
                                            <Sparkles className="size-4" />
                                            AI Assist
                                        </Button>
                                    ) : null
                                }
                            >
                                <div className="space-y-5">
                                    <div className="space-y-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input
                                            id="title"
                                            placeholder="e.g. Office closed on Friday, 25 July"
                                            value={form.data.title}
                                            onChange={(e) =>
                                                form.setData(
                                                    'title',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.title}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="body_html">
                                            Message body
                                        </Label>
                                        <Suspense
                                            fallback={
                                                <AnnouncementMessageEditorSkeleton />
                                            }
                                        >
                                            <LazyAnnouncementMessageEditor
                                                id="body_html"
                                                value={form.data.body_html}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'body_html',
                                                        value,
                                                    )
                                                }
                                                invalid={Boolean(
                                                    form.errors.body_html,
                                                )}
                                            />
                                        </Suspense>
                                        <p className="text-xs text-muted-foreground">
                                            Use the link button for clickable
                                            email and in-app links.
                                        </p>
                                        <InputError
                                            message={form.errors.body_html}
                                        />
                                    </div>

                                    <div className="grid gap-4 rounded-xl border border-border/60 bg-muted/15 p-4 sm:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Category</Label>
                                            <Select
                                                value={form.data.category}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'category',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {options.categories.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Priority</Label>
                                            <Select
                                                value={form.data.priority}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'priority',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {options.priorities.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="expires_at">
                                                Expiry{' '}
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    optional
                                                </span>
                                            </Label>
                                            <Input
                                                id="expires_at"
                                                type="datetime-local"
                                                value={form.data.expires_at}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'expires_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>

                                    {whatsappSelected ? (
                                        <div className="space-y-4 rounded-xl border border-emerald-500/25 bg-emerald-500/[0.04] p-4">
                                            <div className="flex items-center gap-2 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                                <MessageCircle className="size-4" />
                                                WhatsApp
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="whatsapp_template_id">
                                                    Template
                                                </Label>
                                                <Select
                                                    value={
                                                        form.data
                                                            .whatsapp_template_id
                                                            ? String(
                                                                  form.data
                                                                      .whatsapp_template_id,
                                                              )
                                                            : undefined
                                                    }
                                                    onValueChange={(value) =>
                                                        form.setData(
                                                            'whatsapp_template_id',
                                                            Number(value),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger id="whatsapp_template_id">
                                                        <SelectValue placeholder="Select a WhatsApp template" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {options.whatsapp_templates.map(
                                                            (template) => (
                                                                <SelectItem
                                                                    key={
                                                                        template.id
                                                                    }
                                                                    value={String(
                                                                        template.id,
                                                                    )}
                                                                >
                                                                    <div className="flex flex-col items-start">
                                                                        <span>
                                                                            {
                                                                                template.label
                                                                            }
                                                                        </span>
                                                                        <span className="text-xs text-muted-foreground">
                                                                            {
                                                                                template.meta_name
                                                                            }{' '}
                                                                            ·{' '}
                                                                            {
                                                                                template.meta_language
                                                                            }
                                                                        </span>
                                                                    </div>
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .whatsapp_template_id
                                                    }
                                                />
                                                {pendingSuggestedTemplateId &&
                                                pendingSuggestedTemplateId !==
                                                    form.data
                                                        .whatsapp_template_id ? (
                                                    <div className="rounded-lg border border-primary/25 bg-primary/5 p-3 text-sm">
                                                        <p className="font-medium">
                                                            Suggested template
                                                            ready to apply
                                                        </p>
                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={() => {
                                                                    form.setData(
                                                                        'whatsapp_template_id',
                                                                        pendingSuggestedTemplateId,
                                                                    );
                                                                    setPendingSuggestedTemplateId(
                                                                        null,
                                                                    );
                                                                }}
                                                            >
                                                                Use suggestion
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setPendingSuggestedTemplateId(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Keep current
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ) : null}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="whatsapp_message">
                                                    WhatsApp message{' '}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        optional
                                                    </span>
                                                </Label>
                                                <Textarea
                                                    id="whatsapp_message"
                                                    rows={3}
                                                    maxLength={500}
                                                    placeholder="Leave blank to derive from the announcement body"
                                                    value={
                                                        form.data
                                                            .whatsapp_message
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'whatsapp_message',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .whatsapp_message
                                                    }
                                                />
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="whatsapp_link">
                                                    Link{' '}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        optional
                                                    </span>
                                                </Label>
                                                <Input
                                                    id="whatsapp_link"
                                                    type="url"
                                                    inputMode="url"
                                                    placeholder="https://example.com/your-document"
                                                    value={
                                                        form.data.whatsapp_link
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'whatsapp_link',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Appended to the WhatsApp
                                                    message for Title + Message
                                                    templates. Legacy templates
                                                    still use a dedicated link
                                                    parameter.
                                                </p>
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .whatsapp_link
                                                    }
                                                />
                                            </div>
                                            <div className="xl:hidden">
                                                {whatsappPreview}
                                            </div>
                                        </div>
                                    ) : null}

                                    {emailSelected ? (
                                        <div className="space-y-4 rounded-xl border border-sky-500/25 bg-sky-500/[0.04] p-4 xl:hidden">
                                            {emailPreview}
                                        </div>
                                    ) : null}
                                </div>
                            </SectionCard>

                            <SectionCard
                                step={3}
                                icon={<Users className="size-4 text-primary" />}
                                title="Audience"
                                description="Choose who should receive this announcement."
                                headerRight={
                                    <span className="rounded-full border border-border/70 bg-background px-2.5 py-1 text-xs text-muted-foreground">
                                        {audienceSummary}
                                    </span>
                                }
                            >
                                <div className="space-y-4">
                                    {/* Audience type cards */}
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                        {AUDIENCE_TYPES.map((type) => {
                                            const isActive =
                                                activeAudienceType ===
                                                type.value;
                                            const count =
                                                type.value === 'all_employees'
                                                    ? null
                                                    : (audienceOptions[
                                                          type.value
                                                      ]?.length ?? 0);
                                            const selectedCount =
                                                type.value !== 'all_employees'
                                                    ? selectedIds(type.value)
                                                          .length
                                                    : null;

                                            return (
                                                <button
                                                    key={type.value}
                                                    type="button"
                                                    onClick={() =>
                                                        selectAudienceType(
                                                            type.value,
                                                        )
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
                                                                    'leading-tight font-medium',
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
                                                        <p className="mt-0.5 text-xs leading-tight text-muted-foreground">
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
                                                This announcement will be sent
                                                to{' '}
                                                <strong>
                                                    all active employees
                                                </strong>{' '}
                                                in the system.
                                            </span>
                                        </div>
                                    ) : null}

                                    {/* Sub-picker for non-all modes */}
                                    {activeAudienceType === 'department' ? (
                                        <DepartmentTreePicker
                                            items={options.departments}
                                            selectedIds={selectedIds(
                                                'department',
                                            )}
                                            onToggleBatch={toggleAudienceBatch}
                                            onClear={() =>
                                                clearAudienceType('department')
                                            }
                                        />
                                    ) : activeAudienceType !==
                                          'all_employees' &&
                                      audienceOptions[activeAudienceType] ? (
                                        <AudiencePicker
                                            type={activeAudienceType}
                                            items={
                                                audienceOptions[
                                                    activeAudienceType
                                                ]
                                            }
                                            selectedIds={selectedIds(
                                                activeAudienceType,
                                            )}
                                            onToggleBatch={toggleAudienceBatch}
                                            onClear={() =>
                                                clearAudienceType(
                                                    activeAudienceType,
                                                )
                                            }
                                            onSelectAll={
                                                activeAudienceType ===
                                                'employee'
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
                                            Select at least one option to
                                            continue.
                                        </p>
                                    ) : null}

                                    <InputError
                                        message={form.errors.audiences}
                                    />
                                </div>
                            </SectionCard>

                            {/* Attachments (edit mode only) */}
                            {isEdit && announcement ? (
                                <SectionCard
                                    icon={
                                        <Upload className="size-4 text-primary" />
                                    }
                                    title="Attachments"
                                >
                                    <div className="space-y-3">
                                        {announcement.attachments.length > 0 ? (
                                            <ul className="space-y-2">
                                                {announcement.attachments.map(
                                                    (attachment) => (
                                                        <li
                                                            key={attachment.id}
                                                            className="flex items-center justify-between rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5 text-sm"
                                                        >
                                                            <div className="flex items-center gap-2">
                                                                <FileText className="size-4 text-muted-foreground" />
                                                                <span>
                                                                    {
                                                                        attachment.original_name
                                                                    }
                                                                </span>
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
                                                    ),
                                                )}
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
                                                <span>
                                                    Click to upload a file
                                                </span>
                                            </Label>
                                            <Input
                                                id="attachment-upload"
                                                type="file"
                                                className="sr-only"
                                                onChange={(e) => {
                                                    const file =
                                                        e.target.files?.[0];

                                                    if (!file) {
                                                        return;
                                                    }

                                                    const data = new FormData();
                                                    data.append(
                                                        'attachment',
                                                        file,
                                                    );
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
                                step={4}
                                icon={
                                    <CalendarClock className="size-4 text-primary" />
                                }
                                title="Schedule"
                                description="Optional. Leave empty to send immediately or save as draft."
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="scheduled_at">
                                        Schedule for later
                                    </Label>
                                    <Input
                                        id="scheduled_at"
                                        type="datetime-local"
                                        value={form.data.scheduled_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'scheduled_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.scheduled_at}
                                    />
                                </div>
                            </SectionCard>
                        </div>

                        <aside className="hidden xl:block">
                            <div className="sticky top-24 space-y-4">
                                <div className="rounded-xl border glass-card p-5">
                                    <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Summary
                                    </p>
                                    <div className="mt-4 space-y-3">
                                        <div className="rounded-lg border border-border/60 bg-muted/20 px-3 py-2.5">
                                            <p className="text-[11px] text-muted-foreground">
                                                Recipients
                                            </p>
                                            <p className="mt-0.5 text-2xl font-semibold tracking-tight">
                                                {previewLoading && !preview
                                                    ? '…'
                                                    : (preview?.selected_employees ??
                                                      0)}
                                            </p>
                                        </div>
                                        <div className="space-y-1.5">
                                            <p className="text-[11px] text-muted-foreground">
                                                Channels
                                            </p>
                                            <div className="flex flex-wrap gap-1.5">
                                                {form.data.channels.length ===
                                                0 ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        None selected
                                                    </span>
                                                ) : (
                                                    form.data.channels.map(
                                                        (channel) => {
                                                            const meta =
                                                                CHANNELS.find(
                                                                    (item) =>
                                                                        item.value ===
                                                                        channel,
                                                                );

                                                            return (
                                                                <span
                                                                    key={
                                                                        channel
                                                                    }
                                                                    className="inline-flex items-center gap-1 rounded-full border border-border/70 bg-background px-2 py-0.5 text-xs"
                                                                >
                                                                    {meta ? (
                                                                        <meta.Icon className="size-3" />
                                                                    ) : null}
                                                                    {meta?.label ??
                                                                        channel}
                                                                </span>
                                                            );
                                                        },
                                                    )
                                                )}
                                            </div>
                                        </div>
                                        <div className="space-y-1">
                                            <p className="text-[11px] text-muted-foreground">
                                                Audience
                                            </p>
                                            <p className="text-sm font-medium">
                                                {audienceSummary}
                                            </p>
                                        </div>
                                        <div className="space-y-1">
                                            <p className="text-[11px] text-muted-foreground">
                                                Priority
                                            </p>
                                            <p className="text-sm font-medium">
                                                {priorityLabel}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {emailSelected ? (
                                    <div className="rounded-xl border border-sky-500/20 bg-sky-500/[0.03] p-4">
                                        {emailPreview}
                                    </div>
                                ) : null}

                                {whatsappSelected ? (
                                    <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/[0.03] p-4">
                                        {whatsappPreview}
                                    </div>
                                ) : null}
                            </div>
                        </aside>
                    </div>
                </div>

                <div className="fixed inset-x-0 bottom-0 z-20 border-t border-border/70 bg-background/90 backdrop-blur-md">
                    <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div className="min-w-0 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                {preview?.selected_employees ?? 0}
                            </span>{' '}
                            recipients · {audienceSummary}
                            {form.data.channels.length > 0
                                ? ` · ${form.data.channels.length} channel${form.data.channels.length === 1 ? '' : 's'}`
                                : ''}
                        </div>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            {can.publish &&
                            (emailSelected || whatsappSelected) ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!canSendTest || form.processing}
                                    onClick={openTestDialog}
                                >
                                    <FlaskConical className="size-4" /> Send
                                    test to me
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => submit('draft')}
                            >
                                <FileText className="size-4" /> Save draft
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    form.processing || !form.data.scheduled_at
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
                </div>
                <SendAnnouncementTestDialog
                    open={testDialogOpen}
                    onOpenChange={(open) => {
                        setTestDialogOpen(open);

                        if (!open) {
                            setTestError(null);
                            setTestResult(null);
                        }
                    }}
                    destinations={options.test_destinations}
                    selectedChannels={form.data.channels}
                    processing={testSending}
                    result={testResult}
                    error={testError}
                    onSubmit={handleSendTest}
                />
                <AnnouncementAiAssistDialog
                    open={aiDialogOpen}
                    onOpenChange={setAiDialogOpen}
                    available={options.ai_assist_available}
                    processing={aiProcessing}
                    error={aiError}
                    result={aiResult}
                    onRun={runAiAssist}
                    onApplyContent={applyAiContent}
                    onUseSuggestedTemplate={(templateId) => {
                        form.setData('whatsapp_template_id', templateId);
                        setPendingSuggestedTemplateId(null);
                    }}
                    onKeepCurrentTemplate={() =>
                        setPendingSuggestedTemplateId(null)
                    }
                />
            </Main>
        </>
    );
}
