import { router } from '@inertiajs/react';
import { Canvas, FabricImage, FabricText, Line, Rect, Textbox } from 'fabric';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    AlignVerticalJustifyCenter,
    AlignVerticalJustifyEnd,
    AlignVerticalJustifyStart,
    Bold,
    ChevronLeft,
    ChevronRight,
    Copy,
    Eye,
    EyeOff,
    Loader2,
    Minus,
    Plus,
    Redo2,
    Save,
    Search,
    Send,
    Trash2,
    Undo2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getPdfJs } from '@/lib/pdfjs';
import { cn } from '@/lib/utils';
import { draft as draftTemplate } from '@/routes/organization/documents/templates';
import {
    publish as publishVersion,
    show as showVersionRoute,
    sourcePdf,
} from '@/routes/organization/documents/templates/versions';
import { save as saveDesignRoute } from '@/routes/organization/documents/templates/versions/design';
import {
    clickToAlignedPlacement,
    clickToCenteredPlacement,
    cloneDesignState,
    DesignHistory,
    isEditableTypingTarget,
    isRedoKey,
    isUndoKey,
    nudgeDeltaFromKeyboard,
    nudgeNormalizedPlacement,
    offsetDuplicatedNormalizedRect,
    overlayFieldLabelLayout,
    overlayTextTopForAlign,
} from '../lib/canvas-edit';
import {
    clamp,
    DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
    DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
    OVERLAY_FONT_SIZE_MAX_PT,
    OVERLAY_FONT_SIZE_MIN_PT,
    clampOverlayFontSizePt,
    fabricObjectToPixelRect,
    normalizedToPixel,
    overlayFontSizePx,
    overlayFontSizeSelectOptionsPt,
    overlayPlacementBoxChrome,
    overlayPlacementChrome,
    overlayPlacementTextFill,
    pixelToNormalized,
    placementRectInVisibleCanvas,
    stepOverlayFontSizePt,
} from '../lib/coordinates';
import type { FabricRectLike } from '../lib/coordinates';
import {
    estimatePlacementOverflow,
    overflowPageBanner,
    overflowPreviewText,
    placementOverflowLabel,
} from '../lib/placement-overflow';
import type { OverflowLevel } from '../lib/placement-overflow';
import { snapRectToGuides } from '../lib/snap-guides';
import type { SnapBox, SnapGuide } from '../lib/snap-guides';
import {
    normalizeFontColor,
    normalizePlacementConfig,
    normalizeVerticalAlign,
} from '../types';
import type {
    CustomTemplate,
    MergeField,
    PlacementFontFamily,
    PdfFieldPlacement,
    PdfPlacementItem,
    PdfTextPlacement,
    SignaturePlacementConfig,
    SignaturePlacementItem,
    TemplateVersionListItem,
    TemplateVersionSummary,
    VersionChangeSummary,
    VersionDetailResponse,
} from '../types';
import { TemplateDesignEmployeePreviewPicker } from './template-design-employee-preview';
import type { DesignEmployeePreview } from './template-design-employee-preview';

// ─── Signature helper constants ───────────────────────────────────────────────
const SUBJECT_SLOT = 'subject';
const MAX_ROLE_OCCURRENCE = 7;
const DEFAULT_WIDTH = 0.25;
const DEFAULT_HEIGHT = 0.08;
const DEFAULT_X = 0.1;

function isSnapGuideObject(obj: { get: (key: string) => unknown }): boolean {
    return (
        (obj.get('data') as { elementType?: string } | undefined)
            ?.elementType === 'guide'
    );
}

function clearSnapGuides(canvas: Canvas): void {
    canvas
        .getObjects()
        .filter((obj) => isSnapGuideObject(obj))
        .forEach((obj) => canvas.remove(obj));
}

function renderSnapGuides(
    canvas: Canvas,
    guides: SnapGuide[],
    canvasWidth: number,
): void {
    clearSnapGuides(canvas);

    for (const guide of guides) {
        const line = new Line(
            [0, guide.position, canvasWidth, guide.position],
            {
                stroke: '#c026d3',
                strokeWidth: 1,
                selectable: false,
                evented: false,
                hoverCursor: 'default',
            },
        );
        line.set('data', { elementType: 'guide' });
        canvas.add(line);
    }
}

function pageSnapBoxes(
    movingId: string,
    page: number,
    canvasWidth: number,
    canvasHeight: number,
    placements: PdfPlacementItem[],
    signatures: Record<string, SignaturePlacementItem>,
): SnapBox[] {
    const boxes: SnapBox[] = [];

    for (const item of placements) {
        if (item.page !== page || item.id === movingId) {
            continue;
        }

        boxes.push(normalizedToPixel(item, canvasWidth, canvasHeight));
    }

    for (const item of Object.values(signatures)) {
        if (!item || item.page !== page || item.id === movingId) {
            continue;
        }

        boxes.push(normalizedToPixel(item, canvasWidth, canvasHeight));
    }

    return boxes;
}

type SignatureRole = SignaturePlacementItem['role'];

type PendingPlacement =
    | { kind: 'field'; fieldKey: string; label: string }
    | { kind: 'text' }
    | { kind: 'signature'; role: SignatureRole };

function pendingPlacementLabel(pending: PendingPlacement): string {
    if (pending.kind === 'field') {
        return pending.label;
    }

    if (pending.kind === 'text') {
        return 'text';
    }

    if (pending.role === 'subject') {
        return 'employee signature';
    }

    if (pending.role === 'manager') {
        return 'manager signature';
    }

    return 'company signatory signature';
}

function placementIdForSlot(slotKey: string): string {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject_signature';
    }

    const managerMatch = /^manager_(\d+)$/.exec(slotKey);

    if (managerMatch) {
        const occ = Number(managerMatch[1]);

        return occ === 1 ? 'manager_signature' : `manager_signature_${occ}`;
    }

    const companyMatch = /^company_signatory_(\d+)$/.exec(slotKey);

    if (companyMatch) {
        const occ = Number(companyMatch[1]);

        return occ === 1
            ? 'company_signatory_signature'
            : `company_signatory_signature_${occ}`;
    }

    return `${slotKey}_signature`;
}

function roleForSlot(slotKey: string): SignatureRole {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject';
    }

    if (slotKey.startsWith('manager_')) {
        return 'manager';
    }

    return 'company_signatory';
}

function occurrenceForSlot(slotKey: string): number {
    if (slotKey === SUBJECT_SLOT) {
        return 1;
    }

    const match = /_(\d+)$/.exec(slotKey);

    return match ? Number(match[1]) : 1;
}

function slotKeyForRole(role: SignatureRole, occurrence: number): string {
    if (role === 'subject') {
        return SUBJECT_SLOT;
    }

    return role === 'manager'
        ? `manager_${occurrence}`
        : `company_signatory_${occurrence}`;
}

function defaultYForRole(role: SignatureRole, occurrence: number): number {
    if (role === 'subject') {
        return 0.75;
    }

    if (role === 'manager') {
        return Math.max(0.2, 0.62 - (occurrence - 1) * 0.1);
    }

    return Math.max(0.15, 0.5 - (occurrence - 1) * 0.1);
}

function slotLabel(slotKey: string): string {
    const role = roleForSlot(slotKey);
    const occ = occurrenceForSlot(slotKey);

    if (role === 'subject') {
        return 'Employee Signature';
    }

    if (role === 'manager') {
        return occ === 1 ? 'Manager Signature' : `Manager Signature ${occ}`;
    }

    return occ === 1
        ? 'Company Signatory Signature'
        : `Company Signatory Signature ${occ}`;
}

function roleColors(role: SignatureRole): {
    fill: string;
    stroke: string;
    text: string;
} {
    if (role === 'subject') {
        return {
            fill: 'rgba(37,99,235,0.08)',
            stroke: '#93c5fd',
            text: '#1e3a8a',
        };
    }

    if (role === 'manager') {
        return {
            fill: 'rgba(5,150,105,0.08)',
            stroke: '#6ee7b7',
            text: '#065f46',
        };
    }

    return { fill: 'rgba(180,83,9,0.08)', stroke: '#fcd34d', text: '#78350f' };
}

function defaultPlacement(
    slotKey: string,
    page: number,
): SignaturePlacementItem {
    const role = roleForSlot(slotKey);
    const occ = occurrenceForSlot(slotKey);

    return {
        id: placementIdForSlot(slotKey),
        type: 'signature',
        role,
        slot_key: slotKey,
        page,
        x: DEFAULT_X,
        y: defaultYForRole(role, occ),
        width: DEFAULT_WIDTH,
        height: DEFAULT_HEIGHT,
        required: true,
    };
}

function normalizeLoadedPlacement(
    item: SignaturePlacementItem,
    slotKey: string,
): SignaturePlacementItem {
    return {
        id: item.id || placementIdForSlot(slotKey),
        type: 'signature',
        role: roleForSlot(slotKey),
        slot_key: slotKey,
        page: item.page || 1,
        x: item.x,
        y: item.y,
        width: item.width,
        height: item.height,
        required: item.required ?? true,
    };
}

function loadPlacementsFromConfig(
    initialConfig: SignaturePlacementConfig | null,
): Record<string, SignaturePlacementItem> {
    const placements: Record<string, SignaturePlacementItem> = {};
    const source = initialConfig?.placements ?? [];

    if (source.length === 0) {
        // Seed a default employee box only when the version has never stored
        // signature placements. An explicit empty config stays empty.
        if (initialConfig == null) {
            placements[SUBJECT_SLOT] = defaultPlacement(SUBJECT_SLOT, 1);
        }

        return placements;
    }

    const isV2 =
        initialConfig?.schema_version === 2 ||
        source.some((item) => typeof item.slot_key === 'string');

    if (isV2) {
        for (const item of source) {
            const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);
            placements[slotKey] = normalizeLoadedPlacement(item, slotKey);
        }
    } else {
        for (const item of source) {
            const slotKey = slotKeyForRole(item.role, 1);
            placements[slotKey] = normalizeLoadedPlacement(item, slotKey);
        }
    }

    return placements;
}

function sortedSlotKeys(
    placements: Record<string, SignaturePlacementItem>,
): string[] {
    return Object.keys(placements).sort((a, b) => {
        const roleOrder = (slot: string): number => {
            const r = roleForSlot(slot);

            return r === 'subject' ? 0 : r === 'manager' ? 1 : 2;
        };
        const diff = roleOrder(a) - roleOrder(b);

        return diff !== 0 ? diff : occurrenceForSlot(a) - occurrenceForSlot(b);
    });
}

function nextOccurrence(
    placements: Record<string, SignaturePlacementItem>,
    role: Exclude<SignatureRole, 'subject'>,
): number | null {
    const existing = Object.keys(placements)
        .filter((slot) => roleForSlot(slot) === role)
        .map(occurrenceForSlot)
        .sort((a, b) => a - b);
    const next = existing.length + 1;

    return next > MAX_ROLE_OCCURRENCE ? null : next;
}

function renumberRoleSlots(
    placements: Record<string, SignaturePlacementItem>,
    role: Exclude<SignatureRole, 'subject'>,
): Record<string, SignaturePlacementItem> {
    const roleSlots = sortedSlotKeys(placements).filter(
        (slot) => roleForSlot(slot) === role,
    );
    const next: Record<string, SignaturePlacementItem> = {};

    for (const [slotKey, item] of Object.entries(placements)) {
        if (roleForSlot(slotKey) !== role) {
            next[slotKey] = item;
        }
    }

    roleSlots.forEach((oldSlot, index) => {
        const occ = index + 1;
        const newSlot = slotKeyForRole(role, occ);
        const item = placements[oldSlot];

        if (!item) {
            return;
        }

        next[newSlot] = {
            ...item,
            id: placementIdForSlot(newSlot),
            role,
            slot_key: newSlot,
        };
    });

    return next;
}

// ─── VersionInfoPanel ─────────────────────────────────────────────────────────
function signatureSlotLabel(slotKey: string): string {
    if (slotKey === 'subject') {
        return 'Employee';
    }

    const managerMatch = /^manager_(\d+)$/.exec(slotKey);

    if (managerMatch?.[1]) {
        return `Manager ${managerMatch[1]}`;
    }

    const companyMatch = /^company_signatory_(\d+)$/.exec(slotKey);

    if (companyMatch?.[1]) {
        return `Company Signatory ${companyMatch[1]}`;
    }

    return slotKey;
}

function ChangeGroup({ title, rows }: { title: string; rows: string[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="space-y-0.5">
            <p className="font-medium text-foreground">{title}</p>
            <ul className="space-y-0.5 text-muted-foreground">
                {rows.map((row, index) => (
                    <li key={`${title}-${index}`}>• {row}</li>
                ))}
            </ul>
        </div>
    );
}

function VersionChangeSummarySections({
    summary,
}: {
    summary: VersionChangeSummary;
}) {
    const fieldRows = [
        summary.fields_added > 0 ? `${summary.fields_added} added` : null,
        summary.fields_removed > 0 ? `${summary.fields_removed} removed` : null,
        summary.fields_moved > 0 ? `${summary.fields_moved} moved` : null,
        summary.fields_changed > 0 ? `${summary.fields_changed} changed` : null,
    ].filter((row): row is string => row !== null);

    const textRows = [
        summary.static_text_added > 0
            ? `${summary.static_text_added} added`
            : null,
        summary.static_text_removed > 0
            ? `${summary.static_text_removed} removed`
            : null,
        summary.static_text_moved > 0
            ? `${summary.static_text_moved} moved`
            : null,
        summary.static_text_updated > 0
            ? `${summary.static_text_updated} updated`
            : null,
    ].filter((row): row is string => row !== null);

    const signatureRows = [
        ...summary.signatures_added.map(
            (key) => `${signatureSlotLabel(key)} added`,
        ),
        ...summary.signatures_removed.map(
            (key) => `${signatureSlotLabel(key)} removed`,
        ),
        ...summary.signatures_moved.map(
            (key) => `${signatureSlotLabel(key)} moved`,
        ),
    ];

    const workflowRows = [
        summary.workflow_preset_changed ? 'Workflow preset changed' : null,
        summary.signing_preset_changed ? 'Signing preset changed' : null,
    ].filter((row): row is string => row !== null);

    const heading = `Changes from v${summary.compared_to_version}`;

    return (
        <div className="space-y-2 border-t border-border/60 pt-2">
            <p className="font-semibold text-foreground">{heading}</p>
            {summary.pdf_metadata_changed && (
                <ChangeGroup
                    title="PDF"
                    rows={['Source PDF metadata changed']}
                />
            )}
            <ChangeGroup title="Fields" rows={fieldRows} />
            <ChangeGroup title="Text" rows={textRows} />
            <ChangeGroup title="Signatures" rows={signatureRows} />
            <ChangeGroup title="Workflow" rows={workflowRows} />
        </div>
    );
}

function VersionInfoPanel({
    version,
    changeSummary,
}: {
    version: TemplateVersionSummary | null;
    changeSummary: VersionChangeSummary | null;
}) {
    if (!version) {
        return null;
    }

    return (
        <div className="space-y-3 text-xs">
            <p className="font-semibold text-foreground">Version Information</p>
            <div className="space-y-1 text-muted-foreground">
                <div className="flex justify-between">
                    <span>Version</span>
                    <span className="font-medium text-foreground">
                        v{version.version}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span>Status</span>
                    <span className="font-medium text-foreground capitalize">
                        {version.status}
                    </span>
                </div>
                {version.published_at && (
                    <div className="flex justify-between">
                        <span>Published</span>
                        <span>
                            {new Date(version.published_at).toLocaleDateString(
                                'en-GB',
                                {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric',
                                },
                            )}
                        </span>
                    </div>
                )}
                {version.source_pdf_original_name && (
                    <div className="flex justify-between">
                        <span>PDF</span>
                        <span className="max-w-[130px] truncate text-right">
                            {version.source_pdf_original_name}
                        </span>
                    </div>
                )}
                {version.source_pdf_page_count && (
                    <div className="flex justify-between">
                        <span>Pages</span>
                        <span>{version.source_pdf_page_count}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span>Fields</span>
                    <span>{version.placement_count}</span>
                </div>
            </div>
            {changeSummary ? (
                <VersionChangeSummarySections summary={changeSummary} />
            ) : (
                <p className="text-muted-foreground">
                    Initial version — no previous to compare.
                </p>
            )}
        </div>
    );
}

// ─── Component Props ──────────────────────────────────────────────────────────
type Props = {
    open?: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    initialVersion: TemplateVersionSummary | null;
    initialChangeSummary?: VersionChangeSummary | null;
    allVersions: TemplateVersionListItem[];
    mergeFields: MergeField[];
    can: { create_draft: boolean; update: boolean; preview_employee?: boolean };
    onSaved?: () => void;
    mode?: 'dialog' | 'page';
};

const FONT_FAMILIES: {
    value: PlacementFontFamily;
    label: string;
    fabric: string;
}[] = [
    {
        value: 'serif',
        label: 'Times (Serif)',
        fabric: 'Times New Roman',
    },
    {
        value: 'sans',
        label: 'Arial (Sans)',
        fabric: 'Arial',
    },
];

const FONT_COLORS: { value: string; label: string }[] = [
    { value: '#000000', label: 'Black' },
    { value: '#333333', label: 'Dark gray' },
    { value: '#1e3a8a', label: 'Navy' },
    { value: '#b91c1c', label: 'Red' },
];

function fabricFontFamily(family: PlacementFontFamily | undefined): string {
    return (
        FONT_FAMILIES.find((item) => item.value === (family ?? 'sans'))
            ?.fabric ?? 'Arial'
    );
}

function bakeFabricPixelRect(
    target: FabricRectLike & {
        set: (options: object) => unknown;
        setCoords: () => unknown;
    },
): ReturnType<typeof fabricObjectToPixelRect> {
    const pixel = fabricObjectToPixelRect(target);
    target.set({
        left: pixel.left,
        top: pixel.top,
        width: pixel.width,
        height: pixel.height,
        scaleX: 1,
        scaleY: 1,
        originX: 'left',
        originY: 'top',
    });
    target.setCoords();

    return pixel;
}

function overflowMessage(level: OverflowLevel): string | null {
    if (level === 'fail') {
        return 'This box is too small for the text. Drag a corner to make it bigger.';
    }

    return null;
}

function placementOverflowLevel(
    item: PdfPlacementItem,
    pixel: { width: number; height: number },
    pdfScale: number,
    mergeFieldsMap: Map<string, MergeField>,
    previewEmployee: DesignEmployeePreview | null,
): OverflowLevel {
    const requested = item.font_size || 12;
    const text =
        item.type === 'text'
            ? item.text_content
            : overflowPreviewText(
                  item.field,
                  mergeFieldsMap.get(item.field)?.sample ?? '',
                  previewEmployee?.values[item.field],
              );

    return estimatePlacementOverflow({
        text,
        boxWidthPx: pixel.width,
        boxHeightPx: pixel.height,
        requestedPt: requested,
        fontSizePx: overlayFontSizePx(requested, pdfScale),
        fontFamily: fabricFontFamily(item.font_family),
        fontWeight: item.font_weight || 'normal',
        wrap: true,
    });
}

const OVERLAY_WRAP_LINE_HEIGHT = 1.2;

function createOverlayWrapTextbox({
    text,
    left,
    top,
    width,
    height,
    fontSize,
    fontFamily,
    fontWeight,
    textAlign,
    fill,
    verticalAlign,
    parentId,
    elementType,
}: {
    text: string;
    left: number;
    top: number;
    width: number;
    height: number;
    fontSize: number;
    fontFamily: string;
    fontWeight: string;
    textAlign: string;
    fill: string;
    verticalAlign: 'top' | 'middle' | 'baseline';
    parentId: string;
    elementType: 'field' | 'text';
}): Textbox {
    const textbox = new Textbox(text, {
        left,
        top,
        width,
        fontSize,
        fontFamily,
        fontWeight,
        textAlign,
        lineHeight: OVERLAY_WRAP_LINE_HEIGHT,
        objectCaching: false,
        fill,
        backgroundColor: '',
        shadow: null,
        padding: 0,
        lockRotation: true,
        editable: false,
        selectable: false,
        evented: false,
        splitByGrapheme: false,
    });
    textbox.set('data', {
        parentId,
        elementType,
    });
    const textHeight =
        typeof textbox.calcTextHeight === 'function'
            ? textbox.calcTextHeight()
            : fontSize;
    textbox.set({
        top: overlayTextTopForAlign(top, height, textHeight, verticalAlign),
    });

    return textbox;
}

function overflowLabelForPlacement(
    item: PdfPlacementItem,
    mergeFieldsMap: Map<string, MergeField>,
): string {
    if (item.type === 'text') {
        return placementOverflowLabel('text', item.text_content);
    }

    return placementOverflowLabel(
        'field',
        item.field,
        mergeFieldsMap.get(item.field)?.label,
    );
}

function PlacementFontControls({
    placement,
    disabled,
    onChange,
}: {
    placement: PdfPlacementItem;
    disabled: boolean;
    onChange: (
        patch: Partial<
            Pick<
                PdfPlacementItem,
                | 'font_size'
                | 'font_weight'
                | 'text_align'
                | 'vertical_align'
                | 'font_family'
                | 'font_color'
            >
        >,
    ) => void;
}) {
    const family = placement.font_family === 'serif' ? 'serif' : 'sans';
    const color = normalizeFontColor(placement.font_color);
    const fontSize = clampOverlayFontSizePt(placement.font_size);

    return (
        <>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">Font</p>
                <Select
                    value={family}
                    onValueChange={(value) =>
                        onChange({ font_family: value as PlacementFontFamily })
                    }
                    disabled={disabled}
                >
                    <SelectTrigger className="h-7 w-full text-xs">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {FONT_FAMILIES.map((item) => (
                            <SelectItem key={item.value} value={item.value}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">Color</p>
                <div className="flex items-center gap-1.5">
                    {FONT_COLORS.map((item) => (
                        <button
                            key={item.value}
                            type="button"
                            title={item.label}
                            disabled={disabled}
                            className={`size-6 rounded-full border ${
                                color === item.value
                                    ? 'border-foreground ring-1 ring-foreground/40'
                                    : 'border-border'
                            }`}
                            style={{ backgroundColor: item.value }}
                            onClick={() => onChange({ font_color: item.value })}
                        />
                    ))}
                    <input
                        type="color"
                        value={color}
                        disabled={disabled}
                        aria-label="Custom font color"
                        className="h-6 w-8 cursor-pointer rounded border border-border bg-background p-0 disabled:cursor-not-allowed"
                        onChange={(event) =>
                            onChange({
                                font_color: normalizeFontColor(
                                    event.target.value,
                                ),
                            })
                        }
                    />
                </div>
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Font Size
                </p>
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className="size-7 shrink-0"
                        disabled={
                            disabled || fontSize <= OVERLAY_FONT_SIZE_MIN_PT
                        }
                        title="Smaller"
                        onClick={() =>
                            onChange({
                                font_size: stepOverlayFontSizePt(fontSize, -1),
                            })
                        }
                    >
                        <Minus className="size-3.5" />
                    </Button>
                    <Select
                        value={String(fontSize)}
                        onValueChange={(value) =>
                            onChange({
                                font_size: clampOverlayFontSizePt(
                                    Number(value),
                                ),
                            })
                        }
                        disabled={disabled}
                    >
                        <SelectTrigger className="h-7 min-w-0 flex-1 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {overlayFontSizeSelectOptionsPt(fontSize).map(
                                (size) => (
                                    <SelectItem key={size} value={String(size)}>
                                        {size} pt
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className="size-7 shrink-0"
                        disabled={
                            disabled || fontSize >= OVERLAY_FONT_SIZE_MAX_PT
                        }
                        title="Larger"
                        onClick={() =>
                            onChange({
                                font_size: stepOverlayFontSizePt(fontSize, 1),
                            })
                        }
                    >
                        <Plus className="size-3.5" />
                    </Button>
                </div>
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">Style</p>
                <Button
                    type="button"
                    size="icon"
                    variant={
                        placement.font_weight === 'bold' ? 'default' : 'ghost'
                    }
                    className="size-7"
                    disabled={disabled}
                    onClick={() =>
                        onChange({
                            font_weight:
                                placement.font_weight === 'bold'
                                    ? 'normal'
                                    : 'bold',
                        })
                    }
                >
                    <Bold className="size-3.5" />
                </Button>
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Alignment
                </p>
                <div className="flex items-center gap-0.5">
                    {(
                        [
                            ['left', AlignLeft, 'Left'],
                            ['center', AlignCenter, 'Center'],
                            ['right', AlignRight, 'Right'],
                        ] as const
                    ).map(([align, Icon, label]) => (
                        <Button
                            key={align}
                            type="button"
                            size="sm"
                            variant={
                                (placement.text_align || 'left') === align
                                    ? 'default'
                                    : 'ghost'
                            }
                            className="h-7 flex-1 gap-1 px-1.5 text-[11px]"
                            disabled={disabled}
                            title={label}
                            aria-label={label}
                            aria-pressed={
                                (placement.text_align || 'left') === align
                            }
                            onClick={() => onChange({ text_align: align })}
                        >
                            <Icon className="size-3.5" />
                            <span>{label}</span>
                        </Button>
                    ))}
                </div>
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Vertical
                </p>
                <div className="flex items-center gap-0.5">
                    {(
                        [
                            ['top', AlignVerticalJustifyStart, 'Top'],
                            ['middle', AlignVerticalJustifyCenter, 'Middle'],
                            [
                                'baseline',
                                AlignVerticalJustifyEnd,
                                'Baseline (box bottom)',
                            ],
                        ] as const
                    ).map(([align, Icon, label]) => (
                        <Button
                            key={align}
                            type="button"
                            size="icon"
                            variant={
                                (placement.vertical_align ||
                                    (placement.type === 'text'
                                        ? 'top'
                                        : 'middle')) === align
                                    ? 'default'
                                    : 'ghost'
                            }
                            className="size-7"
                            disabled={disabled}
                            title={label}
                            onClick={() => onChange({ vertical_align: align })}
                        >
                            <Icon className="size-3.5" />
                        </Button>
                    ))}
                </div>
            </div>
        </>
    );
}

function PlacementBoxControls({
    placement,
    disabled,
    onChange,
}: {
    placement: PdfPlacementItem;
    disabled: boolean;
    onChange: (
        patch: Partial<Pick<PdfPlacementItem, 'x' | 'y' | 'width' | 'height'>>,
    ) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2">
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Left (%)
                </p>
                <Input
                    type="number"
                    min={0}
                    max={100}
                    step={0.5}
                    disabled={disabled}
                    className="h-7 text-xs"
                    value={Number((placement.x * 100).toFixed(1))}
                    onChange={(event) =>
                        onChange({
                            x: Number(event.target.value) / 100,
                        })
                    }
                />
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Width (%)
                </p>
                <Input
                    type="number"
                    min={1}
                    max={100}
                    step={0.5}
                    disabled={disabled}
                    className="h-7 text-xs"
                    value={Number((placement.width * 100).toFixed(1))}
                    onChange={(event) =>
                        onChange({
                            width: Number(event.target.value) / 100,
                        })
                    }
                />
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Top (%)
                </p>
                <Input
                    type="number"
                    min={0}
                    max={100}
                    step={0.5}
                    disabled={disabled}
                    className="h-7 text-xs"
                    value={Number((placement.y * 100).toFixed(1))}
                    onChange={(event) =>
                        onChange({
                            y: Number(event.target.value) / 100,
                        })
                    }
                />
            </div>
            <div>
                <p className="mb-1 text-[11px] text-muted-foreground">
                    Height (%)
                </p>
                <Input
                    type="number"
                    min={0.5}
                    max={100}
                    step={0.5}
                    disabled={disabled}
                    className="h-7 text-xs"
                    value={Number((placement.height * 100).toFixed(1))}
                    onChange={(event) =>
                        onChange({
                            height: Number(event.target.value) / 100,
                        })
                    }
                />
            </div>
        </div>
    );
}
// ─── Main Component ───────────────────────────────────────────────────────────
export function TemplatePdfDesignerDialog({
    open: openProp,
    onOpenChange,
    template,
    initialVersion,
    initialChangeSummary = null,
    allVersions,
    mergeFields,
    can,
    onSaved,
    mode = 'dialog',
}: Props) {
    const open = mode === 'page' ? true : Boolean(openProp);

    // ── State ──────────────────────────────────────────────────────────────────
    const [selectedVersion, setSelectedVersion] =
        useState<TemplateVersionSummary | null>(initialVersion);
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [placements, setPlacements] = useState<PdfPlacementItem[]>([]);
    const [signaturePlacements, setSignaturePlacements] = useState<
        Record<string, SignaturePlacementItem>
    >({});
    const [selectedElementId, setSelectedElementId] = useState<string | null>(
        null,
    );
    const [selectedElementType, setSelectedElementType] = useState<
        'field' | 'text' | 'signature' | null
    >(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
    const [isLoadingVersion, setIsLoadingVersion] = useState(false);
    const [isLoadingPdf, setIsLoadingPdf] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isPublishing, setIsPublishing] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [placementError, setPlacementError] = useState<string | null>(null);
    const [signatureError, setSignatureError] = useState<string | null>(null);
    const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });
    const [isSamplePreview, setIsSamplePreview] = useState(false);
    const [pendingVersionSwitch, setPendingVersionSwitch] =
        useState<TemplateVersionListItem | null>(null);
    const [isDiscardConfirmOpen, setIsDiscardConfirmOpen] = useState(false);
    const [isCloseDiscardOpen, setIsCloseDiscardOpen] = useState(false);
    const [isCreateDraftConfirmOpen, setIsCreateDraftConfirmOpen] =
        useState(false);
    const [isPublishConfirmOpen, setIsPublishConfirmOpen] = useState(false);
    const [changeSummary, setChangeSummary] =
        useState<VersionChangeSummary | null>(initialChangeSummary ?? null);
    const [pendingPlacement, setPendingPlacement] =
        useState<PendingPlacement | null>(null);
    const [previewEmployee, setPreviewEmployee] =
        useState<DesignEmployeePreview | null>(null);
    const [historyTick, setHistoryTick] = useState(0);

    // ── Derived ────────────────────────────────────────────────────────────────
    const isEditable = selectedVersion?.status === 'draft';

    // ── Refs ───────────────────────────────────────────────────────────────────
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasHostRef = useRef<HTMLDivElement>(null);
    const fabricCanvasRef = useRef<Canvas | null>(null);
    const labelRefs = useRef<Map<string, FabricText>>(new Map());
    const textBoxRefs = useRef<Map<string, Textbox>>(new Map());
    const placementsRef = useRef<PdfPlacementItem[]>([]);
    const signaturePlacementsRef = useRef<
        Record<string, SignaturePlacementItem>
    >({});
    const canvasSizeRef = useRef({ width: 0, height: 0 });
    const pdfScaleRef = useRef(1);
    const isSamplePreviewRef = useRef(false);
    const isEditableRef = useRef(isEditable);
    const pdfDocRef = useRef<any>(null);
    const currentPageRef = useRef(currentPage);
    const pendingPlacementRef = useRef<PendingPlacement | null>(null);
    const previewEmployeeRef = useRef<DesignEmployeePreview | null>(null);
    const historyRef = useRef(
        new DesignHistory<
            PdfPlacementItem[],
            Record<string, SignaturePlacementItem>
        >(),
    );
    const dragStartRef = useRef<{
        placements: PdfPlacementItem[];
        signaturePlacements: Record<string, SignaturePlacementItem>;
    } | null>(null);
    const placePendingAtRef = useRef<(x: number, y: number) => void>(
        () => undefined,
    );
    const applyNudgeRef = useRef<(dx: number, dy: number) => void>(
        () => undefined,
    );
    const selectedElementIdRef = useRef<string | null>(null);
    const selectedElementTypeRef = useRef<
        'field' | 'text' | 'signature' | null
    >(null);
    const nudgeSessionRef = useRef(false);

    // ── Ref sync effects ────────────────────────────────────────────────────────
    useEffect(() => {
        placementsRef.current = placements;
    }, [placements]);
    useEffect(() => {
        signaturePlacementsRef.current = signaturePlacements;
    }, [signaturePlacements]);
    useEffect(() => {
        canvasSizeRef.current = canvasSize;
    }, [canvasSize]);
    useEffect(() => {
        isSamplePreviewRef.current = isSamplePreview;
    }, [isSamplePreview]);
    useEffect(() => {
        isEditableRef.current = Boolean(isEditable);
    }, [isEditable]);
    useEffect(() => {
        pendingPlacementRef.current = pendingPlacement;
    }, [pendingPlacement]);
    useEffect(() => {
        previewEmployeeRef.current = previewEmployee;
    }, [previewEmployee]);
    useEffect(() => {
        currentPageRef.current = currentPage;
    }, [currentPage]);
    useEffect(() => {
        selectedElementIdRef.current = selectedElementId;
    }, [selectedElementId]);
    useEffect(() => {
        selectedElementTypeRef.current = selectedElementType;
    }, [selectedElementType]);
    useEffect(() => {
        nudgeSessionRef.current = false;
    }, [selectedElementId]);

    const disposeFabricCanvas = useCallback(() => {
        if (fabricCanvasRef.current) {
            fabricCanvasRef.current.dispose();
            fabricCanvasRef.current = null;
        }

        labelRefs.current.clear();
        textBoxRefs.current.clear();
        canvasHostRef.current?.replaceChildren();
    }, []);

    // ── Merge field helpers ─────────────────────────────────────────────────────
    const mergeFieldsMap = useMemo(() => {
        const map = new Map<string, MergeField>();
        mergeFields.forEach((f) => map.set(f.key, f));

        return map;
    }, [mergeFields]);

    const categories = useMemo(() => {
        const groups: Record<string, MergeField[]> = {};
        const query = searchQuery.trim().toLowerCase();
        mergeFields.forEach((field) => {
            if (
                query &&
                !field.label.toLowerCase().includes(query) &&
                !field.key.toLowerCase().includes(query)
            ) {
                return;
            }

            if (!groups[field.category]) {
                groups[field.category] = [];
            }

            groups[field.category].push(field);
        });

        return groups;
    }, [mergeFields, searchQuery]);

    // ── Selected element helpers ───────────────────────────────────────────────
    const selectedPlacement = useMemo(
        () => placements.find((p) => p.id === selectedElementId) ?? null,
        [placements, selectedElementId],
    );

    const selectedSignature = useMemo(() => {
        if (!selectedElementId) {
            return null;
        }

        const key = Object.keys(signaturePlacements).find(
            (k) => signaturePlacements[k]?.id === selectedElementId,
        );

        return key ? { slotKey: key, item: signaturePlacements[key]! } : null;
    }, [signaturePlacements, selectedElementId]);

    // ── syncLabels ────────────────────────────────────────────────────────────
    const syncLabels = useCallback((canvas: Canvas) => {
        labelRefs.current.forEach((label, id) => {
            const rect = canvas
                .getObjects()
                .find((obj) => (obj.get('data') as { id?: string })?.id === id);

            if (rect) {
                const elementType = (
                    rect.get('data') as { elementType?: string } | undefined
                )?.elementType;

                if (elementType === 'signature') {
                    const pixel = fabricObjectToPixelRect(rect);
                    label.set({
                        left: pixel.left + 6,
                        top: pixel.top + 6,
                        originX: 'left',
                        originY: 'top',
                    });

                    return;
                }

                const pixel = fabricObjectToPixelRect(rect);
                const originX =
                    label.originX === 'center' || label.originX === 'right'
                        ? label.originX
                        : 'left';
                const placement = placementsRef.current.find(
                    (item) => item.id === id,
                );
                const layout = overlayFieldLabelLayout(
                    pixel.left,
                    pixel.top,
                    pixel.width,
                    pixel.height,
                    originX,
                    normalizeVerticalAlign(
                        placement?.vertical_align,
                        placement?.type === 'text' ? 'text' : 'field',
                    ),
                    label.fontSize ?? 12,
                );
                label.set(layout);
            }
        });
        textBoxRefs.current.forEach((textbox, id) => {
            const rect = canvas
                .getObjects()
                .find((obj) => (obj.get('data') as { id?: string })?.id === id);

            if (rect) {
                const pixel = fabricObjectToPixelRect(rect);
                const placement = placementsRef.current.find(
                    (item) => item.id === id,
                );
                const width = Math.max(10, pixel.width);
                textbox.set({ left: pixel.left, width });
                const textHeight =
                    typeof textbox.calcTextHeight === 'function'
                        ? textbox.calcTextHeight()
                        : (textbox.fontSize ?? 12);
                textbox.set({
                    top: overlayTextTopForAlign(
                        pixel.top,
                        pixel.height,
                        textHeight,
                        normalizeVerticalAlign(
                            placement?.vertical_align,
                            placement?.type === 'text' ? 'text' : 'field',
                        ),
                    ),
                });
            }
        });
        canvas.requestRenderAll();
    }, []);

    // ── syncAllObjects ─────────────────────────────────────────────────────────
    const syncAllObjects = useCallback(
        (canvas: Canvas, page: number, isEditableArg: boolean) => {
            // Remove all managed objects
            const existing = canvas.getObjects().filter((obj) => {
                const d = obj.get('data') as
                    | Record<string, unknown>
                    | undefined;

                return (
                    Boolean(d?.id) ||
                    Boolean(d?.parentId) ||
                    d?.elementType === 'guide'
                );
            });
            existing.forEach((obj) => canvas.remove(obj));
            labelRefs.current.clear();
            textBoxRefs.current.clear();

            const preview = isSamplePreviewRef.current;
            const width = canvasSizeRef.current.width;
            const height = canvasSizeRef.current.height;

            if (width <= 0 || height <= 0) {
                return;
            }

            // ── Field + Text placements ──────────────────────────────────────
            const pagePlacements = placementsRef.current.filter(
                (p) => p.page === page,
            );
            const overflowById = new Map<string, OverflowLevel>();

            pagePlacements.forEach((item) => {
                overflowById.set(
                    item.id,
                    placementOverflowLevel(
                        item,
                        normalizedToPixel(
                            {
                                x: item.x,
                                y: item.y,
                                width: item.width,
                                height: item.height,
                            },
                            width,
                            height,
                        ),
                        pdfScaleRef.current,
                        mergeFieldsMap,
                        previewEmployeeRef.current,
                    ),
                );
            });

            pagePlacements.forEach((item) => {
                const pixel = normalizedToPixel(
                    {
                        x: item.x,
                        y: item.y,
                        width: item.width,
                        height: item.height,
                    },
                    width,
                    height,
                );

                if (item.type === 'field') {
                    const fieldMeta = mergeFieldsMap.get(item.field);
                    const displayText = preview
                        ? (previewEmployeeRef.current?.values[item.field] ??
                          fieldMeta?.sample ??
                          item.field)
                        : (fieldMeta?.label ?? item.field);

                    const overflow = overflowById.get(item.id) ?? 'ok';
                    const chrome = overlayPlacementBoxChrome(
                        preview ? 'print' : 'edit',
                        overflow,
                    );
                    const rect = new Rect({
                        left: pixel.left,
                        top: pixel.top,
                        width: pixel.width,
                        height: pixel.height,
                        originX: 'left',
                        originY: 'top',
                        fill: chrome.fill,
                        stroke: chrome.stroke,
                        strokeWidth: chrome.strokeWidth,
                        cornerColor:
                            overflow === 'fail' ? '#dc2626' : '#2563eb',
                        cornerStyle: 'circle',
                        transparentCorners: false,
                        hasRotatingPoint: false,
                        lockRotation: true,
                        selectable: !preview,
                        evented: !preview,
                    });
                    rect.set('data', { id: item.id, elementType: 'field' });

                    if (!isEditableArg) {
                        rect.set({
                            lockMovementX: true,
                            lockMovementY: true,
                            lockScalingX: true,
                            lockScalingY: true,
                            hasControls: false,
                        });
                    }

                    const tb = createOverlayWrapTextbox({
                        text: displayText,
                        left: pixel.left,
                        top: pixel.top,
                        width: pixel.width,
                        height: pixel.height,
                        fontSize: overlayFontSizePx(
                            item.font_size,
                            pdfScaleRef.current,
                        ),
                        fontFamily: fabricFontFamily(item.font_family),
                        fontWeight: item.font_weight || 'normal',
                        textAlign: item.text_align || 'left',
                        fill: overlayPlacementTextFill(
                            preview ? 'print' : 'edit',
                            normalizeFontColor(item.font_color),
                        ),
                        verticalAlign: normalizeVerticalAlign(
                            item.vertical_align,
                            'field',
                        ),
                        parentId: item.id,
                        elementType: 'field',
                    });

                    canvas.add(rect);
                    canvas.add(tb);
                    textBoxRefs.current.set(item.id, tb);
                } else if (item.type === 'text') {
                    const overflow = overflowById.get(item.id) ?? 'ok';
                    const chrome = overlayPlacementBoxChrome(
                        preview ? 'print' : 'edit',
                        overflow,
                    );
                    const rect = new Rect({
                        left: pixel.left,
                        top: pixel.top,
                        width: pixel.width,
                        height: pixel.height,
                        originX: 'left',
                        originY: 'top',
                        fill: chrome.fill,
                        stroke: chrome.stroke,
                        strokeWidth: chrome.strokeWidth,
                        cornerColor:
                            overflow === 'fail' ? '#dc2626' : '#2563eb',
                        cornerStyle: 'circle',
                        transparentCorners: false,
                        hasRotatingPoint: false,
                        lockRotation: true,
                        selectable: !preview,
                        evented: !preview,
                    });
                    rect.set('data', { id: item.id, elementType: 'text' });

                    if (!isEditableArg) {
                        rect.set({
                            lockMovementX: true,
                            lockMovementY: true,
                            lockScalingX: true,
                            lockScalingY: true,
                            hasControls: false,
                        });
                    }

                    const tb = createOverlayWrapTextbox({
                        text: item.text_content || '',
                        left: pixel.left,
                        top: pixel.top,
                        width: pixel.width,
                        height: pixel.height,
                        fontSize: overlayFontSizePx(
                            item.font_size,
                            pdfScaleRef.current,
                        ),
                        fontFamily: fabricFontFamily(item.font_family),
                        fontWeight: item.font_weight || 'normal',
                        textAlign: item.text_align || 'left',
                        fill: overlayPlacementTextFill(
                            preview ? 'print' : 'edit',
                            normalizeFontColor(item.font_color),
                        ),
                        verticalAlign: normalizeVerticalAlign(
                            item.vertical_align,
                            'text',
                        ),
                        parentId: item.id,
                        elementType: 'text',
                    });

                    canvas.add(rect);
                    canvas.add(tb);
                    textBoxRefs.current.set(item.id, tb);
                }
            });

            // ── Signature placements ─────────────────────────────────────────
            sortedSlotKeys(signaturePlacementsRef.current).forEach(
                (slotKey) => {
                    const item = signaturePlacementsRef.current[slotKey];

                    if (!item || item.page !== page) {
                        return;
                    }

                    const pixel = normalizedToPixel(
                        {
                            x: item.x,
                            y: item.y,
                            width: item.width,
                            height: item.height,
                        },
                        width,
                        height,
                    );
                    const colors = roleColors(item.role);

                    const rect = new Rect({
                        left: pixel.left,
                        top: pixel.top,
                        width: pixel.width,
                        height: pixel.height,
                        originX: 'left',
                        originY: 'top',
                        fill: preview ? 'transparent' : colors.fill,
                        stroke: preview ? 'transparent' : colors.stroke,
                        strokeWidth: 1,
                        strokeUniform: true,
                        objectCaching: false,
                        cornerColor: colors.stroke,
                        cornerStyle: 'circle',
                        transparentCorners: false,
                        hasRotatingPoint: false,
                        lockRotation: true,
                        selectable: !preview,
                        evented: !preview,
                    });
                    rect.set('data', {
                        id: item.id,
                        elementType: 'signature',
                        slotKey,
                    });

                    if (!isEditableArg) {
                        rect.set({
                            lockMovementX: true,
                            lockMovementY: true,
                            lockScalingX: true,
                            lockScalingY: true,
                            hasControls: false,
                        });
                    }

                    canvas.add(rect);

                    if (!preview) {
                        const label = new FabricText(slotLabel(slotKey), {
                            left: pixel.left + 6,
                            top: pixel.top + 6,
                            fontSize: 12,
                            fill: colors.text,
                            selectable: false,
                            evented: false,
                        });
                        label.set('data', {
                            parentId: item.id,
                            elementType: 'signature',
                        });

                        canvas.add(label);
                        labelRefs.current.set(item.id, label);
                    }
                },
            );

            canvas.requestRenderAll();
        },
        [mergeFieldsMap],
    );

    // ── attachCanvasEvents ─────────────────────────────────────────────────────
    const attachCanvasEvents = useCallback(
        (canvas: Canvas) => {
            canvas.on('mouse:down', (opt) => {
                if (
                    pendingPlacementRef.current &&
                    !opt.target &&
                    isEditableRef.current &&
                    !isSamplePreviewRef.current
                ) {
                    const event = opt.e as MouseEvent | undefined;
                    const pointer =
                        event && typeof canvas.getScenePoint === 'function'
                            ? canvas.getScenePoint(event)
                            : canvas.getPointer(opt.e);
                    placePendingAtRef.current(pointer.x, pointer.y);

                    return;
                }

                if (pendingPlacementRef.current && opt.target) {
                    setPendingPlacement(null);
                }

                if (
                    opt.target &&
                    isEditableRef.current &&
                    !isSamplePreviewRef.current
                ) {
                    nudgeSessionRef.current = false;
                    dragStartRef.current = cloneDesignState({
                        placements: placementsRef.current,
                        signaturePlacements: signaturePlacementsRef.current,
                    });
                }
            });

            canvas.on('object:moving', (e) => {
                if (dragStartRef.current) {
                    historyRef.current.accept(dragStartRef.current);
                    dragStartRef.current = null;
                    setHistoryTick((tick) => tick + 1);
                }

                const target = e.target;
                const data = target?.get('data') as
                    | { id?: string; elementType?: string; slotKey?: string }
                    | undefined;

                if (!target || !data?.id || !isEditableRef.current) {
                    return;
                }

                const dims = canvasSizeRef.current;

                if (dims.width <= 0 || dims.height <= 0) {
                    return;
                }

                const moving = fabricObjectToPixelRect(target);
                const pointerEvent = e.e as MouseEvent | undefined;
                const disableSnap = Boolean(pointerEvent?.altKey);
                let pixel = moving;

                if (!disableSnap && !isSamplePreviewRef.current) {
                    const snapped = snapRectToGuides(
                        moving,
                        pageSnapBoxes(
                            data.id,
                            currentPageRef.current,
                            dims.width,
                            dims.height,
                            placementsRef.current,
                            signaturePlacementsRef.current,
                        ),
                        dims.width,
                        dims.height,
                    );
                    pixel = {
                        ...moving,
                        left: snapped.left,
                        top: snapped.top,
                    };
                    target.set({ left: pixel.left, top: pixel.top });
                    target.setCoords();
                    renderSnapGuides(canvas, snapped.guides, dims.width);
                } else {
                    clearSnapGuides(canvas);
                }

                syncLabels(canvas);
                const norm = pixelToNormalized(pixel, dims.width, dims.height);

                if (
                    data.elementType === 'field' ||
                    data.elementType === 'text'
                ) {
                    setPlacements((prev) => {
                        const updated = prev.map((p) =>
                            p.id === data.id
                                ? { ...p, x: norm.x, y: norm.y }
                                : p,
                        );
                        placementsRef.current = updated;

                        return updated;
                    });
                } else if (data.elementType === 'signature' && data.slotKey) {
                    const slotKey = data.slotKey;
                    setSignaturePlacements((prev) => {
                        const updated = {
                            ...prev,
                            [slotKey]: {
                                ...prev[slotKey]!,
                                x: norm.x,
                                y: norm.y,
                            },
                        };
                        signaturePlacementsRef.current = updated;

                        return updated;
                    });
                }

                setHasUnsavedChanges(true);
            });

            canvas.on('object:scaling', (e) => {
                if (dragStartRef.current) {
                    historyRef.current.accept(dragStartRef.current);
                    dragStartRef.current = null;
                    setHistoryTick((tick) => tick + 1);
                }

                const target = e.target;
                const data = target?.get('data') as
                    | { id?: string; elementType?: string; slotKey?: string }
                    | undefined;

                if (!target || !data?.id || !isEditableRef.current) {
                    return;
                }

                syncLabels(canvas);
                const dims = canvasSizeRef.current;

                if (dims.width <= 0 || dims.height <= 0) {
                    return;
                }

                const pixel = fabricObjectToPixelRect(target);
                const norm = pixelToNormalized(pixel, dims.width, dims.height);

                if (
                    data.elementType === 'field' ||
                    data.elementType === 'text'
                ) {
                    setPlacements((prev) => {
                        const updated = prev.map((p) =>
                            p.id === data.id
                                ? {
                                      ...p,
                                      x: norm.x,
                                      y: norm.y,
                                      width: norm.width,
                                      height: norm.height,
                                  }
                                : p,
                        );
                        placementsRef.current = updated;

                        return updated;
                    });
                } else if (data.elementType === 'signature' && data.slotKey) {
                    const slotKey = data.slotKey;
                    setSignaturePlacements((prev) => {
                        const updated = {
                            ...prev,
                            [slotKey]: {
                                ...prev[slotKey]!,
                                x: norm.x,
                                y: norm.y,
                                width: norm.width,
                                height: norm.height,
                            },
                        };
                        signaturePlacementsRef.current = updated;

                        return updated;
                    });
                }

                setHasUnsavedChanges(true);
            });

            canvas.on('object:modified', (e) => {
                const target = e.target;
                const data = target?.get('data') as
                    | { id?: string; elementType?: string; slotKey?: string }
                    | undefined;

                if (
                    !target ||
                    !data?.id ||
                    !isEditableRef.current ||
                    (data.elementType !== 'field' &&
                        data.elementType !== 'text' &&
                        data.elementType !== 'signature')
                ) {
                    return;
                }

                const pixel = bakeFabricPixelRect(target);
                const dims = canvasSizeRef.current;

                if (dims.width <= 0 || dims.height <= 0) {
                    return;
                }

                const norm = pixelToNormalized(pixel, dims.width, dims.height);

                if (
                    data.elementType === 'field' ||
                    data.elementType === 'text'
                ) {
                    setPlacements((prev) => {
                        const updated = prev.map((p) =>
                            p.id === data.id
                                ? {
                                      ...p,
                                      x: norm.x,
                                      y: norm.y,
                                      width: norm.width,
                                      height: norm.height,
                                  }
                                : p,
                        );
                        placementsRef.current = updated;

                        return updated;
                    });
                } else if (data.elementType === 'signature' && data.slotKey) {
                    const slotKey = data.slotKey;
                    setSignaturePlacements((prev) => {
                        const updated = {
                            ...prev,
                            [slotKey]: {
                                ...prev[slotKey]!,
                                x: norm.x,
                                y: norm.y,
                                width: norm.width,
                                height: norm.height,
                            },
                        };
                        signaturePlacementsRef.current = updated;

                        return updated;
                    });
                }

                setHasUnsavedChanges(true);
                syncLabels(canvas);
                canvas.requestRenderAll();
            });

            canvas.on('mouse:up', () => {
                clearSnapGuides(canvas);
                canvas.requestRenderAll();
            });

            canvas.on('text:changed', (e) => {
                if (!isEditableRef.current) {
                    return;
                }

                const target = e.target;
                const data = target?.get('data') as
                    | { id?: string; parentId?: string; elementType?: string }
                    | undefined;
                const textId = data?.id ?? data?.parentId;

                if (data?.elementType !== 'text' || !textId) {
                    return;
                }

                const newText = (target as Textbox).text || '';
                setPlacements((prev) => {
                    const updated = prev.map((p) =>
                        p.id === textId && p.type === 'text'
                            ? { ...p, text_content: newText }
                            : p,
                    );
                    placementsRef.current = updated;

                    return updated;
                });
                setHasUnsavedChanges(true);
            });

            canvas.on('selection:created', (e) => {
                const target = e.selected?.[0];
                const data = target?.get('data') as
                    | { id?: string; elementType?: string }
                    | undefined;
                setSelectedElementId(data?.id ?? null);
                setSelectedElementType(
                    (data?.elementType as 'field' | 'text' | 'signature') ??
                        null,
                );
            });

            canvas.on('selection:updated', (e) => {
                const target = e.selected?.[0];
                const data = target?.get('data') as
                    | { id?: string; elementType?: string }
                    | undefined;
                setSelectedElementId(data?.id ?? null);
                setSelectedElementType(
                    (data?.elementType as 'field' | 'text' | 'signature') ??
                        null,
                );
            });

            canvas.on('selection:cleared', () => {
                setSelectedElementId(null);
                setSelectedElementType(null);
                clearSnapGuides(canvas);
            });

            canvas.on('mouse:wheel', (opt) => {
                const event = opt.e as WheelEvent;
                const scrollParent = containerRef.current;

                if (!scrollParent) {
                    return;
                }

                scrollParent.scrollTop += event.deltaY;
                scrollParent.scrollLeft += event.deltaX;
                event.preventDefault();
                event.stopPropagation();
            });
        },
        [syncLabels],
    );

    // ── PDF load effect ─────────────────────────────────────────────────────────
    useEffect(() => {
        if (!open || !template || !selectedVersion) {
            return;
        }

        let cancelled = false;

        const loadAndRenderPdfPage = async () => {
            setIsLoadingPdf(true);
            setErrorMessage(null);

            try {
                let pdf = pdfDocRef.current;

                if (!pdf) {
                    const pdfUrl = sourcePdf.url({
                        template: template.id,
                        version: selectedVersion.id,
                    });
                    const response = await fetch(pdfUrl, {
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        throw new Error(
                            'Failed to stream private template PDF.',
                        );
                    }

                    const data = await response.arrayBuffer();
                    const pdfjs = await getPdfJs();
                    pdf = await pdfjs.getDocument({ data }).promise;

                    if (cancelled) {
                        return;
                    }

                    pdfDocRef.current = pdf;
                    setTotalPages(pdf.numPages);
                }

                const pageNumber = Math.min(
                    Math.max(1, currentPage),
                    pdf.numPages,
                );
                const pdfPage = await pdf.getPage(pageNumber);

                if (cancelled) {
                    return;
                }

                const unscaledViewport = pdfPage.getViewport({ scale: 1 });
                const targetWidth = Math.min(
                    900,
                    Math.max(600, window.innerWidth * 0.55),
                );
                const scale = targetWidth / unscaledViewport.width;
                const viewport = pdfPage.getViewport({ scale });
                pdfScaleRef.current = scale;

                const offscreen = document.createElement('canvas');
                const context = offscreen.getContext('2d');

                if (!context) {
                    throw new Error(
                        'Could not get 2d context for PDF rendering.',
                    );
                }

                offscreen.width = viewport.width;
                offscreen.height = viewport.height;

                await pdfPage.render({
                    canvasContext: context,
                    viewport,
                    canvas: offscreen,
                }).promise;

                if (document.fonts?.ready) {
                    await document.fonts.ready;
                }

                if (cancelled) {
                    return;
                }

                const backgroundUrl = offscreen.toDataURL('image/png');
                const newSize = {
                    width: viewport.width,
                    height: viewport.height,
                };
                setCanvasSize(newSize);
                canvasSizeRef.current = newSize;

                let canvas = fabricCanvasRef.current;
                const canvasHost = canvasHostRef.current;

                if (!canvas && canvasHost) {
                    const canvasElement = document.createElement('canvas');
                    canvasHost.appendChild(canvasElement);
                    canvas = new Canvas(canvasElement, {
                        width: viewport.width,
                        height: viewport.height,
                        selection: false,
                    });
                    fabricCanvasRef.current = canvas;
                    attachCanvasEvents(canvas);
                } else if (canvas) {
                    canvas.setDimensions({
                        width: viewport.width,
                        height: viewport.height,
                    });
                }

                if (!canvas) {
                    return;
                }

                const bgImage = await FabricImage.fromURL(backgroundUrl);
                canvas.backgroundImage = bgImage;

                syncAllObjects(canvas, pageNumber, Boolean(isEditable));
                setIsLoadingPdf(false);
            } catch (err: unknown) {
                if (!cancelled) {
                    const message =
                        err instanceof Error
                            ? err.message
                            : 'Failed to render PDF page.';
                    setErrorMessage(message);
                    setIsLoadingPdf(false);
                }
            }
        };

        loadAndRenderPdfPage();

        return () => {
            cancelled = true;
        };
    }, [
        open,
        template,
        selectedVersion,
        currentPage,
        attachCanvasEvents,
        syncAllObjects,
        isEditable,
    ]);

    // ── Fast sync for preview toggle / editable state ─────────────────────────
    useEffect(() => {
        const canvas = fabricCanvasRef.current;

        if (
            canvas &&
            canvasSize.width > 0 &&
            canvasSize.height > 0 &&
            !isLoadingPdf
        ) {
            syncAllObjects(canvas, currentPage, Boolean(isEditable));
        }
    }, [
        isSamplePreview,
        previewEmployee,
        currentPage,
        canvasSize,
        isLoadingPdf,
        syncAllObjects,
        isEditable,
    ]);

    // ── Initialization effect ─────────────────────────────────────────────────
    useEffect(() => {
        if (open && initialVersion) {
            const normalizedConfig = normalizePlacementConfig(
                initialVersion.placement_config,
            );
            const normalizedSigs = loadPlacementsFromConfig(
                initialVersion.signature_placement_config ?? null,
            );

            placementsRef.current = normalizedConfig.placements;
            signaturePlacementsRef.current = normalizedSigs;
            setPlacements(normalizedConfig.placements);
            setSignaturePlacements(normalizedSigs);
            setSelectedVersion(initialVersion);
            setCurrentPage(1);
            setTotalPages(initialVersion.source_pdf_page_count || 1);
            setSelectedElementId(null);
            setSelectedElementType(null);
            setHasUnsavedChanges(false);
            setChangeSummary(null);
            isSamplePreviewRef.current = false;
            setIsSamplePreview(false);
            setPreviewEmployee(null);
            setPendingPlacement(null);
            historyRef.current = new DesignHistory();
            setHistoryTick(0);
            nudgeSessionRef.current = false;
            pdfDocRef.current = null;
        }
    }, [open, initialVersion]);

    // ── Cleanup on unmount ────────────────────────────────────────────────────
    useEffect(() => disposeFabricCanvas, [disposeFabricCanvas]);

    // ── Signature slot helpers ─────────────────────────────────────────────────
    const canAddSubject = signaturePlacements[SUBJECT_SLOT] == null;
    const canAddManager =
        nextOccurrence(signaturePlacements, 'manager') !== null;
    const canAddCompany =
        nextOccurrence(signaturePlacements, 'company_signatory') !== null;

    const refreshCanvasObjects = () => {
        const canvas = fabricCanvasRef.current;

        if (
            canvas &&
            canvasSize.width > 0 &&
            canvasSize.height > 0 &&
            !isLoadingPdf
        ) {
            syncAllObjects(canvas, currentPage, Boolean(isEditable));
        }
    };

    const recordHistory = useCallback(() => {
        historyRef.current.push({
            placements: placementsRef.current,
            signaturePlacements: signaturePlacementsRef.current,
        });
        setHistoryTick((tick) => tick + 1);
    }, []);

    const applyHistoryState = useCallback(
        (
            next: {
                placements: PdfPlacementItem[];
                signaturePlacements: Record<string, SignaturePlacementItem>;
            } | null,
        ) => {
            if (!next) {
                return;
            }

            placementsRef.current = next.placements;
            signaturePlacementsRef.current = next.signaturePlacements;
            setPlacements(next.placements);
            setSignaturePlacements(next.signaturePlacements);
            setSelectedElementId(null);
            setSelectedElementType(null);
            setHasUnsavedChanges(true);
            setHistoryTick((tick) => tick + 1);
            nudgeSessionRef.current = false;

            const canvas = fabricCanvasRef.current;

            if (canvas && canvasSizeRef.current.width > 0) {
                syncAllObjects(
                    canvas,
                    currentPageRef.current,
                    isEditableRef.current,
                );
            }
        },
        [syncAllObjects],
    );

    const undoDesign = useCallback(() => {
        applyHistoryState(
            historyRef.current.undo({
                placements: placementsRef.current,
                signaturePlacements: signaturePlacementsRef.current,
            }),
        );
    }, [applyHistoryState]);

    const redoDesign = useCallback(() => {
        applyHistoryState(
            historyRef.current.redo({
                placements: placementsRef.current,
                signaturePlacements: signaturePlacementsRef.current,
            }),
        );
    }, [applyHistoryState]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (isEditableTypingTarget(event.target)) {
                return;
            }

            if (event.key === 'Escape' && pendingPlacementRef.current) {
                event.preventDefault();
                setPendingPlacement(null);

                return;
            }

            if (isUndoKey(event)) {
                if (!isEditableRef.current) {
                    return;
                }

                event.preventDefault();
                undoDesign();

                return;
            }

            if (isRedoKey(event)) {
                if (!isEditableRef.current) {
                    return;
                }

                event.preventDefault();
                redoDesign();

                return;
            }

            if (!isEditableRef.current || isSamplePreviewRef.current) {
                return;
            }

            const delta = nudgeDeltaFromKeyboard(event);

            if (!delta) {
                return;
            }

            if (!selectedElementIdRef.current) {
                return;
            }

            event.preventDefault();
            applyNudgeRef.current(delta.dx, delta.dy);
        };

        window.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [open, undoDesign, redoDesign]);

    useEffect(() => {
        const canvas = fabricCanvasRef.current;
        const crosshair =
            pendingPlacement !== null && isEditable && !isSamplePreview;

        if (canvas) {
            canvas.defaultCursor = crosshair ? 'crosshair' : 'default';
            canvas.hoverCursor = crosshair ? 'crosshair' : 'move';
            canvas.requestRenderAll();
        }
    }, [pendingPlacement, isEditable, isSamplePreview, canvasSize.width]);

    const addRoleSlot = (
        role: SignatureRole,
        initialPixel?: {
            left: number;
            top: number;
            width: number;
            height: number;
        },
    ) => {
        const page = currentPageRef.current;
        const size = canvasSizeRef.current;
        const withClickOrigin = (
            item: SignaturePlacementItem,
        ): SignaturePlacementItem => {
            if (!initialPixel || size.width <= 0 || size.height <= 0) {
                return item;
            }

            const norm = pixelToNormalized(
                initialPixel,
                size.width,
                size.height,
            );

            return {
                ...item,
                x: norm.x,
                y: norm.y,
                width: norm.width,
                height: norm.height,
            };
        };

        if (role === 'subject') {
            if (signaturePlacementsRef.current[SUBJECT_SLOT]) {
                return;
            }

            recordHistory();
            nudgeSessionRef.current = false;
            const newItem = withClickOrigin(
                defaultPlacement(SUBJECT_SLOT, page),
            );
            const updated = {
                ...signaturePlacementsRef.current,
                [SUBJECT_SLOT]: newItem,
            };
            signaturePlacementsRef.current = updated;
            setSignaturePlacements(updated);
            setSelectedElementId(newItem.id);
            setSelectedElementType('signature');
            setHasUnsavedChanges(true);
            refreshCanvasObjects();

            return;
        }

        const occurrence = nextOccurrence(signaturePlacementsRef.current, role);

        if (occurrence === null) {
            return;
        }

        recordHistory();
        nudgeSessionRef.current = false;
        const slotKey = slotKeyForRole(role, occurrence);
        const newItem = withClickOrigin(defaultPlacement(slotKey, page));
        const updated = {
            ...signaturePlacementsRef.current,
            [slotKey]: newItem,
        };
        signaturePlacementsRef.current = updated;
        setSignaturePlacements(updated);
        setSelectedElementId(newItem.id);
        setSelectedElementType('signature');
        setHasUnsavedChanges(true);
        refreshCanvasObjects();
    };

    const removeSlot = (slotKey: string, skipHistory = false) => {
        if (!skipHistory) {
            recordHistory();
            nudgeSessionRef.current = false;
        }

        const role = roleForSlot(slotKey);
        const removedId = signaturePlacementsRef.current[slotKey]?.id;
        const rest = { ...signaturePlacementsRef.current };
        delete rest[slotKey];
        const next = role === 'subject' ? rest : renumberRoleSlots(rest, role);
        signaturePlacementsRef.current = next;
        setSignaturePlacements(next);

        if (selectedElementId === removedId) {
            setSelectedElementId(null);
            setSelectedElementType(null);
        }

        setHasUnsavedChanges(true);
        refreshCanvasObjects();
    };

    // ── Handlers ───────────────────────────────────────────────────────────────
    const viewportPlacementRect = (boxWidth: number, boxHeight: number) => {
        const canvasHost = canvasHostRef.current;
        const container = containerRef.current;

        if (
            !canvasHost ||
            !container ||
            !canvasSize.width ||
            !canvasSize.height
        ) {
            return {
                left: Math.round((canvasSize.width - boxWidth) / 2),
                top: Math.round((canvasSize.height - boxHeight) / 2),
                width: boxWidth,
                height: boxHeight,
            };
        }

        return placementRectInVisibleCanvas({
            canvasWidth: canvasSize.width,
            canvasHeight: canvasSize.height,
            boxWidth,
            boxHeight,
            canvasRect: canvasHost.getBoundingClientRect(),
            viewRect: container.getBoundingClientRect(),
        });
    };

    const handleAddFieldPlacement = (
        fieldKey: string,
        initialPixel = viewportPlacementRect(
            DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
            DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
        ),
    ) => {
        if (!canvasSize.width || !canvasSize.height) {
            return;
        }

        recordHistory();
        nudgeSessionRef.current = false;
        const newId = crypto.randomUUID();
        const norm = pixelToNormalized(
            initialPixel,
            canvasSize.width,
            canvasSize.height,
        );
        const newPlacement: PdfFieldPlacement = {
            id: newId,
            type: 'field',
            field: fieldKey,
            page: currentPageRef.current,
            x: norm.x,
            y: norm.y,
            width: norm.width,
            height: norm.height,
            font_size: 12,
            font_weight: 'normal',
            font_family: 'serif',
            font_color: '#000000',
            text_align: 'left',
            vertical_align: 'baseline',
        };

        setHasUnsavedChanges(true);
        setPlacements((prev) => {
            const updated = [...prev, newPlacement];
            placementsRef.current = updated;

            return updated;
        });

        const canvas = fabricCanvasRef.current;

        if (canvas) {
            const fieldMeta = mergeFieldsMap.get(fieldKey);
            const chrome = overlayPlacementChrome('edit');
            const rect = new Rect({
                left: initialPixel.left,
                top: initialPixel.top,
                width: initialPixel.width,
                height: initialPixel.height,
                originX: 'left',
                originY: 'top',
                fill: chrome.fill,
                stroke: chrome.stroke,
                strokeWidth: 1,
                cornerColor: '#2563eb',
                cornerStyle: 'circle',
                transparentCorners: false,
                hasRotatingPoint: false,
                lockRotation: true,
            });
            rect.set('data', { id: newId, elementType: 'field' });

            const tb = createOverlayWrapTextbox({
                text: fieldMeta?.label ?? fieldKey,
                left: initialPixel.left,
                top: initialPixel.top,
                width: initialPixel.width,
                height: initialPixel.height,
                fontSize: overlayFontSizePx(12, pdfScaleRef.current),
                fontFamily: fabricFontFamily('serif'),
                fontWeight: 'normal',
                textAlign: 'left',
                fill: '#000000',
                verticalAlign: 'baseline',
                parentId: newId,
                elementType: 'field',
            });

            canvas.add(rect);
            canvas.add(tb);
            textBoxRefs.current.set(newId, tb);
            canvas.setActiveObject(rect);
            setSelectedElementId(newId);
            setSelectedElementType('field');
            canvas.requestRenderAll();
        }
    };

    const handleAddTextPlacement = (
        initialPixel = viewportPlacementRect(
            DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
            DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
        ),
    ) => {
        if (!canvasSize.width || !canvasSize.height) {
            return;
        }

        recordHistory();
        nudgeSessionRef.current = false;
        const newId = crypto.randomUUID();
        const norm = pixelToNormalized(
            initialPixel,
            canvasSize.width,
            canvasSize.height,
        );
        const newPlacement: PdfTextPlacement = {
            id: newId,
            type: 'text',
            text_content: 'Text',
            page: currentPageRef.current,
            x: norm.x,
            y: norm.y,
            width: norm.width,
            height: norm.height,
            font_size: 12,
            font_weight: 'normal',
            font_family: 'serif',
            font_color: '#000000',
            text_align: 'left',
            vertical_align: 'baseline',
        };

        setHasUnsavedChanges(true);
        setPlacements((prev) => {
            const updated = [...prev, newPlacement];
            placementsRef.current = updated;

            return updated;
        });

        const canvas = fabricCanvasRef.current;

        if (canvas) {
            const chrome = overlayPlacementChrome('edit');
            const rect = new Rect({
                left: initialPixel.left,
                top: initialPixel.top,
                width: initialPixel.width,
                height: initialPixel.height,
                originX: 'left',
                originY: 'top',
                fill: chrome.fill,
                stroke: chrome.stroke,
                strokeWidth: 1,
                cornerColor: '#2563eb',
                cornerStyle: 'circle',
                transparentCorners: false,
                hasRotatingPoint: false,
                lockRotation: true,
            });
            rect.set('data', { id: newId, elementType: 'text' });

            const tb = createOverlayWrapTextbox({
                text: 'Text',
                left: initialPixel.left,
                top: initialPixel.top,
                width: initialPixel.width,
                height: initialPixel.height,
                fontSize: overlayFontSizePx(12, pdfScaleRef.current),
                fontFamily: fabricFontFamily('serif'),
                fontWeight: 'normal',
                textAlign: 'left',
                fill: '#000000',
                verticalAlign: 'baseline',
                parentId: newId,
                elementType: 'text',
            });
            canvas.add(rect);
            canvas.add(tb);
            textBoxRefs.current.set(newId, tb);
            canvas.setActiveObject(rect);
            setSelectedElementId(newId);
            setSelectedElementType('text');
            canvas.requestRenderAll();
        }
    };

    const placePendingAt = (x: number, y: number) => {
        const pending = pendingPlacementRef.current;
        const size = canvasSizeRef.current;

        if (!pending || size.width <= 0 || size.height <= 0) {
            return;
        }

        if (pending.kind === 'field') {
            handleAddFieldPlacement(
                pending.fieldKey,
                clickToAlignedPlacement(
                    x,
                    y,
                    DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
                    DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
                    size.width,
                    size.height,
                    'baseline',
                ),
            );
        } else if (pending.kind === 'text') {
            handleAddTextPlacement(
                clickToAlignedPlacement(
                    x,
                    y,
                    DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
                    DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
                    size.width,
                    size.height,
                    'baseline',
                ),
            );
        } else {
            addRoleSlot(
                pending.role,
                clickToCenteredPlacement(
                    x,
                    y,
                    size.width * DEFAULT_WIDTH,
                    size.height * DEFAULT_HEIGHT,
                    size.width,
                    size.height,
                ),
            );
        }

        setPendingPlacement(null);
    };

    placePendingAtRef.current = placePendingAt;

    const applyNudge = (dx: number, dy: number) => {
        const size = canvasSizeRef.current;
        const id = selectedElementIdRef.current;
        const type = selectedElementTypeRef.current;

        if (!id || !type || size.width <= 0 || size.height <= 0) {
            return;
        }

        if (!nudgeSessionRef.current) {
            recordHistory();
            nudgeSessionRef.current = true;
        }

        let nextRect: {
            x: number;
            y: number;
            width: number;
            height: number;
        } | null = null;

        if (type === 'field' || type === 'text') {
            const current = placementsRef.current.find(
                (placement) => placement.id === id,
            );

            if (!current) {
                return;
            }

            nextRect = nudgeNormalizedPlacement(
                current,
                dx,
                dy,
                size.width,
                size.height,
            );
            setPlacements((prev) => {
                const updated = prev.map((placement) =>
                    placement.id === id
                        ? { ...placement, ...nextRect }
                        : placement,
                );
                placementsRef.current = updated;

                return updated;
            });
        } else {
            const slotKey = Object.keys(signaturePlacementsRef.current).find(
                (key) => signaturePlacementsRef.current[key]?.id === id,
            );
            const current = slotKey
                ? signaturePlacementsRef.current[slotKey]
                : undefined;

            if (!slotKey || !current) {
                return;
            }

            nextRect = nudgeNormalizedPlacement(
                current,
                dx,
                dy,
                size.width,
                size.height,
            );
            setSignaturePlacements((prev) => {
                const updated = {
                    ...prev,
                    [slotKey]: { ...current, ...nextRect },
                };
                signaturePlacementsRef.current = updated;

                return updated;
            });
        }

        const canvas = fabricCanvasRef.current;
        const pixel = normalizedToPixel(nextRect, size.width, size.height);
        const object = canvas
            ?.getObjects()
            .find((item) => (item.get('data') as { id?: string })?.id === id);

        if (canvas && object) {
            object.set({ left: pixel.left, top: pixel.top });
            object.setCoords();
            syncLabels(canvas);
            canvas.requestRenderAll();
        }

        setHasUnsavedChanges(true);
    };

    applyNudgeRef.current = applyNudge;

    const handleDuplicatePlacement = (source: PdfPlacementItem) => {
        if (!isEditable || !canvasSize.width || !canvasSize.height) {
            return;
        }

        recordHistory();
        nudgeSessionRef.current = false;
        const { x, y } = offsetDuplicatedNormalizedRect(
            source,
            canvasSize.width,
            canvasSize.height,
        );
        const newId = crypto.randomUUID();
        const duplicate: PdfPlacementItem = {
            ...source,
            id: newId,
            x,
            y,
        };

        setPlacements((prev) => {
            const updated = [...prev, duplicate];
            placementsRef.current = updated;

            return updated;
        });
        setHasUnsavedChanges(true);
        setSelectedElementId(newId);
        setSelectedElementType(source.type);
        refreshCanvasObjects();

        const canvas = fabricCanvasRef.current;
        const cloned = canvas
            ?.getObjects()
            .find(
                (object) =>
                    (object.get('data') as { id?: string })?.id === newId,
            );

        if (canvas && cloned) {
            canvas.setActiveObject(cloned);
            canvas.requestRenderAll();
        }
    };

    const handleDeleteSelected = (
        id: string,
        elementType: 'field' | 'text' | 'signature',
    ) => {
        if (!isEditable) {
            return;
        }

        recordHistory();
        nudgeSessionRef.current = false;

        if (elementType === 'field' || elementType === 'text') {
            setPlacements((prev) => {
                const updated = prev.filter((p) => p.id !== id);
                placementsRef.current = updated;

                return updated;
            });
        } else if (elementType === 'signature') {
            const slotKey = Object.keys(signaturePlacementsRef.current).find(
                (k) => signaturePlacementsRef.current[k]?.id === id,
            );

            if (slotKey) {
                removeSlot(slotKey, true);

                return;
            }
        }

        setSelectedElementId(null);
        setSelectedElementType(null);

        const canvas = fabricCanvasRef.current;

        if (canvas) {
            const obj = canvas
                .getObjects()
                .find((o) => (o.get('data') as { id?: string })?.id === id);
            const lbl = labelRefs.current.get(id);

            if (obj) {
                canvas.remove(obj);
            }

            if (lbl) {
                canvas.remove(lbl);
            }

            const textbox = textBoxRefs.current.get(id);

            if (textbox) {
                canvas.remove(textbox);
            }

            labelRefs.current.delete(id);
            textBoxRefs.current.delete(id);
            canvas.requestRenderAll();
        }

        setHasUnsavedChanges(true);
    };

    const doVersionSwitch = async (target: TemplateVersionListItem) => {
        if (!template) {
            return;
        }

        setIsLoadingVersion(true);
        setSelectedElementId(null);
        setSelectedElementType(null);
        setPlacementError(null);
        setSignatureError(null);
        setErrorMessage(null);

        try {
            const url = showVersionRoute.url({
                template: template.id,
                version: target.id,
            });
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to load version');
            }

            const data: VersionDetailResponse = await response.json();

            const normalizedConfig = normalizePlacementConfig(
                data.version.placement_config,
            );
            const normalizedSigs = loadPlacementsFromConfig(
                data.version.signature_placement_config ?? null,
            );

            placementsRef.current = normalizedConfig.placements;
            signaturePlacementsRef.current = normalizedSigs;
            setPlacements(normalizedConfig.placements);
            setSignaturePlacements(normalizedSigs);
            setSelectedVersion(data.version);
            setChangeSummary(data.change_summary);
            setCurrentPage(1);
            setHasUnsavedChanges(false);
            setPendingPlacement(null);
            historyRef.current = new DesignHistory();
            setHistoryTick(0);
            nudgeSessionRef.current = false;

            disposeFabricCanvas();
            labelRefs.current.clear();
            textBoxRefs.current.clear();
            pdfDocRef.current = null;
        } catch (err: unknown) {
            const message =
                err instanceof Error
                    ? err.message
                    : 'Failed to switch version.';
            setErrorMessage(message);
        } finally {
            setIsLoadingVersion(false);
        }
    };

    const handleVersionSelect = (versionIdStr: string) => {
        const versionId = Number(versionIdStr);

        if (versionId === selectedVersion?.id) {
            return;
        }

        const target = allVersions.find((v) => v.id === versionId);

        if (!target) {
            return;
        }

        if (hasUnsavedChanges) {
            setPendingVersionSwitch(target);
            setIsDiscardConfirmOpen(true);

            return;
        }

        void doVersionSwitch(target);
    };

    const handleCreateDraft = () => {
        if (!template) {
            return;
        }

        router.post(
            draftTemplate.url({ template: template.id }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({
                        only: ['all_versions', 'initial_version'] as never[],
                    });
                },
                onError: (err) => {
                    setErrorMessage(
                        (Object.values(err)[0] as string) ||
                            'Failed to create draft.',
                    );
                },
            },
        );
    };

    const handleSaveDesign = async (): Promise<boolean> => {
        if (!template || !selectedVersion) {
            return false;
        }

        setIsSaving(true);
        setPlacementError(null);
        setSignatureError(null);

        const sigPlacements: SignaturePlacementItem[] = sortedSlotKeys(
            signaturePlacementsRef.current,
        ).map((slotKey) => {
            const item =
                signaturePlacementsRef.current[slotKey] ??
                defaultPlacement(slotKey, 1);

            return {
                ...item,
                id: placementIdForSlot(slotKey),
                type: 'signature' as const,
                role: roleForSlot(slotKey),
                slot_key: slotKey,
                required: true,
            };
        });

        const payload = {
            placement_config: {
                schema_version: 2,
                placements: placementsRef.current.map((p) => {
                    const base = {
                        id: p.id,
                        type: p.type,
                        page: p.page,
                        x: p.x,
                        y: p.y,
                        width: p.width,
                        height: p.height,
                        font_size: p.font_size || 12,
                        font_weight: p.font_weight || 'normal',
                        font_family:
                            p.font_family === 'serif' ? 'serif' : 'sans',
                        font_color: normalizeFontColor(p.font_color),
                        text_align: p.text_align || 'left',
                        vertical_align: normalizeVerticalAlign(
                            p.vertical_align,
                            p.type,
                        ),
                    };

                    if (p.type === 'text') {
                        return { ...base, text_content: p.text_content };
                    }

                    return { ...base, field: p.field };
                }),
            },
            signature_placement_config: {
                schema_version: 2,
                placements: sigPlacements,
            },
        };

        try {
            const csrf =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const response = await fetch(
                saveDesignRoute.url({
                    template: template.id,
                    version: selectedVersion.id,
                }),
                {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(payload),
                },
            );

            if (response.status === 422) {
                const body = (await response.json()) as {
                    message?: string;
                    errors?: Record<string, string[] | string>;
                };
                const errors = body.errors ?? {};
                const keys = Object.keys(errors);
                const firstValue =
                    keys.length > 0 ? errors[keys[0]!] : body.message;
                const firstMsg = Array.isArray(firstValue)
                    ? (firstValue[0] ?? 'Failed to save design.')
                    : firstValue || 'Failed to save design.';
                const hasSigError = keys.some((k) => k.includes('signature'));
                const hasPlacementError = keys.some(
                    (k) => k.includes('placement') || k.includes('placements'),
                );

                if (hasSigError) {
                    setSignatureError(firstMsg);
                } else if (hasPlacementError) {
                    setPlacementError(firstMsg);
                } else {
                    setErrorMessage(firstMsg);
                }

                return false;
            }

            if (!response.ok) {
                throw new Error('Failed to save design.');
            }

            const data = (await response.json()) as {
                version?: TemplateVersionSummary;
            };

            if (data.version) {
                setSelectedVersion(data.version);
            }

            setHasUnsavedChanges(false);

            if (onSaved) {
                onSaved();
            }

            return true;
        } catch (err: unknown) {
            const message =
                err instanceof Error ? err.message : 'Failed to save design.';
            setErrorMessage(message);

            return false;
        } finally {
            setIsSaving(false);
        }
    };

    const handlePublish = async () => {
        if (!template || !selectedVersion) {
            return;
        }

        setErrorMessage(null);

        if (hasUnsavedChanges) {
            setIsPublishing(true);
            const saved = await handleSaveDesign();

            if (!saved) {
                setIsPublishing(false);

                return;
            }
        }

        setIsPublishing(true);
        router.post(
            publishVersion.url({
                template: template.id,
                version: selectedVersion.id,
            }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsPublishing(false);
                    setHasUnsavedChanges(false);
                    onOpenChange(false);
                },
                onError: (err) => {
                    setIsPublishing(false);
                    setErrorMessage(
                        (Object.values(err)[0] as string) ||
                            'Failed to publish.',
                    );
                },
            },
        );
    };

    const handleSafeOpenChange = (newOpen: boolean) => {
        if (!newOpen && hasUnsavedChanges) {
            setIsCloseDiscardOpen(true);

            return;
        }

        onOpenChange(newOpen);
    };

    // ── Update field property on canvas ───────────────────────────────────────
    const updateFieldProperty = (
        id: string,
        patch: Partial<
            Pick<
                PdfPlacementItem,
                | 'font_size'
                | 'font_weight'
                | 'text_align'
                | 'vertical_align'
                | 'font_family'
                | 'font_color'
                | 'x'
                | 'y'
                | 'width'
                | 'height'
            >
        >,
    ) => {
        recordHistory();
        nudgeSessionRef.current = false;
        setPlacements((prev) => {
            const updated = prev.map((p) => {
                if (p.id !== id) {
                    return p;
                }

                const next = { ...p, ...patch };

                if (
                    patch.width !== undefined ||
                    patch.x !== undefined ||
                    patch.height !== undefined ||
                    patch.y !== undefined
                ) {
                    const width = clamp(
                        Number.isFinite(next.width) ? next.width : p.width,
                        0.02,
                        1,
                    );
                    const height = clamp(
                        Number.isFinite(next.height) ? next.height : p.height,
                        0.005,
                        1,
                    );
                    const x = clamp(
                        Number.isFinite(next.x) ? next.x : p.x,
                        0,
                        Math.max(0, 1 - width),
                    );
                    const y = clamp(
                        Number.isFinite(next.y) ? next.y : p.y,
                        0,
                        Math.max(0, 1 - height),
                    );

                    return { ...next, x, y, width, height };
                }

                return next;
            });
            placementsRef.current = updated;

            return updated;
        });
        setHasUnsavedChanges(true);

        if (
            'x' in patch ||
            'y' in patch ||
            'width' in patch ||
            'height' in patch ||
            'vertical_align' in patch
        ) {
            refreshCanvasObjects();
            const canvas = fabricCanvasRef.current;
            const object = canvas
                ?.getObjects()
                .find(
                    (item) => (item.get('data') as { id?: string })?.id === id,
                );

            if (canvas && object) {
                canvas.setActiveObject(object);
                canvas.requestRenderAll();
            }

            return;
        }

        const canvas = fabricCanvasRef.current;

        if (!canvas) {
            return;
        }

        const label = labelRefs.current.get(id);

        if (label) {
            if ('font_size' in patch && patch.font_size !== undefined) {
                label.set(
                    'fontSize',
                    overlayFontSizePx(patch.font_size, pdfScaleRef.current),
                );
            }

            if ('font_family' in patch && patch.font_family !== undefined) {
                label.set('fontFamily', fabricFontFamily(patch.font_family));
            }

            if ('font_color' in patch && patch.font_color !== undefined) {
                label.set('fill', normalizeFontColor(patch.font_color));
            }

            if ('font_weight' in patch && patch.font_weight !== undefined) {
                label.set('fontWeight', patch.font_weight);
            }

            if ('text_align' in patch && patch.text_align !== undefined) {
                const rect = canvas
                    .getObjects()
                    .find((o) => (o.get('data') as { id?: string })?.id === id);

                if (rect) {
                    const pixel = fabricObjectToPixelRect(rect);
                    label.set(
                        overlayFieldLabelLayout(
                            pixel.left,
                            pixel.top,
                            pixel.width,
                            pixel.height,
                            patch.text_align,
                            normalizeVerticalAlign(
                                placementsRef.current.find(
                                    (item) => item.id === id,
                                )?.vertical_align,
                                'field',
                            ),
                            label.fontSize ?? 12,
                        ),
                    );
                }
            }

            canvas.requestRenderAll();
        }

        const tb = textBoxRefs.current.get(id);

        if (tb) {
            if ('font_size' in patch && patch.font_size !== undefined) {
                tb.set(
                    'fontSize',
                    overlayFontSizePx(patch.font_size, pdfScaleRef.current),
                );
            }

            if ('font_family' in patch && patch.font_family !== undefined) {
                tb.set('fontFamily', fabricFontFamily(patch.font_family));
            }

            if ('font_color' in patch && patch.font_color !== undefined) {
                tb.set('fill', normalizeFontColor(patch.font_color));
            }

            if ('font_weight' in patch && patch.font_weight !== undefined) {
                tb.set('fontWeight', patch.font_weight);
            }

            if ('text_align' in patch && patch.text_align !== undefined) {
                tb.set('textAlign', patch.text_align);
            }

            if (
                'font_size' in patch ||
                'font_family' in patch ||
                'font_weight' in patch ||
                'text_align' in patch
            ) {
                const rect = canvas
                    .getObjects()
                    .find((o) => (o.get('data') as { id?: string })?.id === id);

                if (rect) {
                    const pixel = fabricObjectToPixelRect(rect);
                    const placement = placementsRef.current.find(
                        (item) => item.id === id,
                    );
                    const textHeight =
                        typeof tb.calcTextHeight === 'function'
                            ? tb.calcTextHeight()
                            : (tb.fontSize ?? 12);
                    tb.set({
                        top: overlayTextTopForAlign(
                            pixel.top,
                            pixel.height,
                            textHeight,
                            normalizeVerticalAlign(
                                placement?.vertical_align,
                                placement?.type === 'text' ? 'text' : 'field',
                            ),
                        ),
                    });
                }
            }

            canvas.requestRenderAll();
        }
    };

    const selectedOverflow: OverflowLevel =
        selectedPlacement && canvasSize.width > 0
            ? placementOverflowLevel(
                  selectedPlacement,
                  normalizedToPixel(
                      selectedPlacement,
                      canvasSize.width,
                      canvasSize.height,
                  ),
                  pdfScaleRef.current,
                  mergeFieldsMap,
                  previewEmployee,
              )
            : 'ok';
    const selectedOverflowMessage = overflowMessage(selectedOverflow);

    const pageOverflowBanner = (() => {
        if (canvasSize.width <= 0) {
            return null;
        }

        const failLabels: string[] = [];

        placements
            .filter((item) => item.page === currentPage)
            .forEach((item) => {
                const level = placementOverflowLevel(
                    item,
                    normalizedToPixel(
                        item,
                        canvasSize.width,
                        canvasSize.height,
                    ),
                    pdfScaleRef.current,
                    mergeFieldsMap,
                    previewEmployee,
                );

                if (level === 'fail') {
                    failLabels.push(
                        overflowLabelForPlacement(item, mergeFieldsMap),
                    );
                }
            });

        return overflowPageBanner(failLabels);
    })();

    // ─── Right Panel Inline Panels ────────────────────────────────────────────
    const fieldPanelEl =
        selectedElementId &&
        selectedElementType === 'field' &&
        selectedPlacement?.type === 'field' ? (
            <div className="space-y-3">
                <p className="text-xs font-semibold text-foreground">
                    {mergeFieldsMap.get(
                        (selectedPlacement as PdfFieldPlacement).field,
                    )?.label || (selectedPlacement as PdfFieldPlacement).field}
                </p>
                <PlacementFontControls
                    placement={selectedPlacement}
                    disabled={!isEditable}
                    onChange={(patch) =>
                        updateFieldProperty(selectedPlacement.id, patch)
                    }
                />
                <PlacementBoxControls
                    placement={selectedPlacement}
                    disabled={!isEditable}
                    onChange={(patch) =>
                        updateFieldProperty(selectedPlacement.id, patch)
                    }
                />
                {selectedOverflowMessage && (
                    <p className="text-[11px] text-destructive">
                        {selectedOverflowMessage}
                    </p>
                )}
                {isEditable && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="w-full"
                            onClick={() =>
                                handleDuplicatePlacement(selectedPlacement)
                            }
                        >
                            <Copy className="mr-1.5 size-3.5" /> Duplicate
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() =>
                                handleDeleteSelected(
                                    selectedPlacement.id,
                                    'field',
                                )
                            }
                        >
                            <Trash2 className="mr-1.5 size-3.5" /> Delete
                        </Button>
                    </>
                )}
            </div>
        ) : null;

    const textPanelEl =
        selectedElementId &&
        selectedElementType === 'text' &&
        selectedPlacement?.type === 'text' ? (
            <div className="space-y-3">
                <p className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                    Text Box
                </p>
                <div>
                    <p className="mb-1 text-[11px] text-muted-foreground">
                        Content
                    </p>
                    <textarea
                        value={
                            (selectedPlacement as PdfTextPlacement).text_content
                        }
                        readOnly={!isEditable}
                        rows={3}
                        className="w-full resize-none rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:ring-1 focus:ring-primary/30 focus:outline-none"
                        onChange={(e) => {
                            const val = e.target.value;
                            setPlacements((prev) => {
                                const updated = prev.map((p) =>
                                    p.id === selectedPlacement.id &&
                                    p.type === 'text'
                                        ? { ...p, text_content: val }
                                        : p,
                                );
                                placementsRef.current = updated;

                                return updated;
                            });
                            const tb = textBoxRefs.current.get(
                                selectedPlacement.id,
                            );

                            if (tb) {
                                tb.set('text', val);
                                fabricCanvasRef.current?.requestRenderAll();
                            }

                            setHasUnsavedChanges(true);
                        }}
                    />
                </div>
                <PlacementFontControls
                    placement={selectedPlacement}
                    disabled={!isEditable}
                    onChange={(patch) =>
                        updateFieldProperty(selectedPlacement.id, patch)
                    }
                />
                <PlacementBoxControls
                    placement={selectedPlacement}
                    disabled={!isEditable}
                    onChange={(patch) =>
                        updateFieldProperty(selectedPlacement.id, patch)
                    }
                />
                {selectedOverflowMessage && (
                    <p className="text-[11px] text-destructive">
                        {selectedOverflowMessage}
                    </p>
                )}
                {isEditable && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="w-full"
                            onClick={() =>
                                handleDuplicatePlacement(selectedPlacement)
                            }
                        >
                            <Copy className="mr-1.5 size-3.5" /> Duplicate
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() =>
                                handleDeleteSelected(
                                    selectedPlacement.id,
                                    'text',
                                )
                            }
                        >
                            <Trash2 className="mr-1.5 size-3.5" /> Delete
                        </Button>
                    </>
                )}
            </div>
        ) : null;

    const signaturePanelEl =
        selectedElementId &&
        selectedElementType === 'signature' &&
        selectedSignature ? (
            <div className="space-y-3">
                <p className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                    Signature
                </p>
                <div>
                    <p className="mb-0.5 text-[11px] text-muted-foreground">
                        Slot
                    </p>
                    <p className="font-mono text-xs text-foreground">
                        {selectedSignature.slotKey}
                    </p>
                </div>
                <div>
                    <p className="mb-1 text-[11px] text-muted-foreground">
                        Role
                    </p>
                    <Badge
                        variant="secondary"
                        className="text-xs capitalize"
                        style={{
                            color: roleColors(selectedSignature.item.role).text,
                            borderColor: roleColors(selectedSignature.item.role)
                                .stroke,
                        }}
                    >
                        {selectedSignature.item.role.replace('_', ' ')}
                    </Badge>
                </div>
                <div>
                    <p className="mb-1 text-[11px] text-muted-foreground">
                        Page
                    </p>
                    <Select
                        value={String(selectedSignature.item.page)}
                        disabled={!isEditable}
                        onValueChange={(v) => {
                            const page = Number(v);
                            const slotKey = selectedSignature.slotKey;
                            recordHistory();
                            nudgeSessionRef.current = false;
                            setSignaturePlacements((prev) => {
                                const updated = {
                                    ...prev,
                                    [slotKey]: { ...prev[slotKey]!, page },
                                };
                                signaturePlacementsRef.current = updated;

                                return updated;
                            });
                            setHasUnsavedChanges(true);
                        }}
                    >
                        <SelectTrigger className="h-7 w-full text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Array.from(
                                { length: totalPages },
                                (_, i) => i + 1,
                            ).map((p) => (
                                <SelectItem key={p} value={String(p)}>
                                    Page {p}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {isEditable && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() =>
                            handleDeleteSelected(
                                selectedSignature.item.id,
                                'signature',
                            )
                        }
                    >
                        <Trash2 className="mr-1.5 size-3.5" /> Delete
                    </Button>
                )}
            </div>
        ) : null;

    // ─── Toolbar ──────────────────────────────────────────────────────────────
    const canUndoDesign = historyTick >= 0 && historyRef.current.canUndo;
    const canRedoDesign = historyTick >= 0 && historyRef.current.canRedo;

    const toolbar = (
        <div className="flex shrink-0 flex-row items-center justify-between border-b border-border/80 px-4 py-3 sm:px-6">
            <div className="flex min-w-0 items-center gap-3">
                {mode === 'page' && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="shrink-0 gap-1.5 px-2"
                        onClick={() => handleSafeOpenChange(false)}
                    >
                        <ChevronLeft className="size-4" />
                        Templates
                    </Button>
                )}
                <h2 className="truncate text-base font-semibold">
                    Design: {template?.name}
                </h2>

                {/* Version dropdown */}
                {allVersions.length > 0 && (
                    <Select
                        value={String(selectedVersion?.id)}
                        onValueChange={handleVersionSelect}
                        disabled={isSaving || isPublishing || isLoadingVersion}
                    >
                        <SelectTrigger className="h-7 w-44 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {allVersions.map((v) => (
                                <SelectItem key={v.id} value={String(v.id)}>
                                    {v.status === 'draft'
                                        ? `● Draft v${v.version}`
                                        : v.status === 'published'
                                          ? `✓ Published v${v.version}`
                                          : `  Archived v${v.version}`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                {/* Status badge */}
                {isEditable ? (
                    <Badge variant="secondary" className="text-xs">
                        Draft
                        {hasUnsavedChanges && (
                            <span className="ml-1.5 inline-block size-1.5 rounded-full bg-amber-400" />
                        )}
                    </Badge>
                ) : (
                    <Badge variant="outline" className="text-xs">
                        {selectedVersion?.status === 'published'
                            ? 'Published · Read only'
                            : 'Archived · Read only'}
                    </Badge>
                )}
            </div>

            <div className="flex items-center gap-2">
                {isEditable && !isSamplePreview && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-8"
                            disabled={!canUndoDesign}
                            onClick={undoDesign}
                            title="Undo"
                        >
                            <Undo2 className="size-3.5" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-8"
                            disabled={!canRedoDesign}
                            onClick={redoDesign}
                            title="Redo"
                        >
                            <Redo2 className="size-3.5" />
                        </Button>
                    </>
                )}

                {/* Return to Draft / Create Draft */}
                {!isEditable &&
                    (() => {
                        const draftVersion = allVersions.find(
                            (v) => v.status === 'draft',
                        );

                        if (draftVersion) {
                            return (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        handleVersionSelect(
                                            String(draftVersion.id),
                                        )
                                    }
                                >
                                    Return to Draft v{draftVersion.version}
                                </Button>
                            );
                        }

                        if (can.create_draft) {
                            return (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setIsCreateDraftConfirmOpen(true)
                                    }
                                >
                                    Create Draft
                                </Button>
                            );
                        }

                        return null;
                    })()}

                {/* Sample preview toggle */}
                <Button
                    type="button"
                    variant={isSamplePreview ? 'default' : 'outline'}
                    size="sm"
                    onClick={() => {
                        setIsSamplePreview((preview) => {
                            if (!preview) {
                                setPendingPlacement(null);
                            }

                            return !preview;
                        });
                    }}
                >
                    {isSamplePreview ? (
                        <>
                            <EyeOff className="mr-1.5 size-3.5" />
                            Exit Preview
                        </>
                    ) : (
                        <>
                            <Eye className="mr-1.5 size-3.5" />
                            Preview
                        </>
                    )}
                </Button>

                {isSamplePreview && can.preview_employee && template && (
                    <TemplateDesignEmployeePreviewPicker
                        templateId={template.id}
                        selected={previewEmployee}
                        onSelect={setPreviewEmployee}
                        onClear={() => setPreviewEmployee(null)}
                    />
                )}

                {/* Page navigation */}
                <div className="flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-1.5 py-0.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={currentPage <= 1 || isLoadingPdf}
                        onClick={() =>
                            setCurrentPage((p) => Math.max(1, p - 1))
                        }
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <span className="px-1 text-xs font-medium select-none">
                        Page {currentPage} of {totalPages}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={currentPage >= totalPages || isLoadingPdf}
                        onClick={() =>
                            setCurrentPage((p) => Math.min(totalPages, p + 1))
                        }
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>

                {/* Save / Publish — only when editable */}
                {isEditable && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant={hasUnsavedChanges ? 'default' : 'outline'}
                            onClick={() => void handleSaveDesign()}
                            disabled={isSaving || isLoadingPdf}
                        >
                            {isSaving ? (
                                <>
                                    <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                                    Saving...
                                </>
                            ) : (
                                <>
                                    <Save className="mr-1.5 size-3.5" />
                                    Save Design
                                    {hasUnsavedChanges && (
                                        <span className="ml-1.5 inline-block size-1.5 rounded-full bg-amber-400" />
                                    )}
                                </>
                            )}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => setIsPublishConfirmOpen(true)}
                            disabled={isPublishing || isSaving}
                            className="bg-primary hover:bg-primary/90"
                        >
                            {isPublishing ? (
                                <>
                                    <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                                    {hasUnsavedChanges
                                        ? 'Saving & Publishing...'
                                        : 'Publishing...'}
                                </>
                            ) : (
                                <>
                                    <Send className="mr-1.5 size-3.5" />
                                    Publish
                                </>
                            )}
                        </Button>
                    </>
                )}
            </div>
        </div>
    );

    // ─── Workspace ────────────────────────────────────────────────────────────
    const workspace = (
        <>
            {toolbar}

            {pendingPlacement && isEditable && !isSamplePreview && (
                <div className="flex shrink-0 items-center justify-between bg-primary/10 px-6 py-2 text-xs font-medium text-foreground">
                    <span>
                        Click the printed line to place{' '}
                        {pendingPlacementLabel(pendingPlacement)} (Esc to
                        cancel)
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 px-2 text-xs"
                        onClick={() => setPendingPlacement(null)}
                    >
                        Cancel
                    </Button>
                </div>
            )}

            {pageOverflowBanner && (
                <div className="flex shrink-0 items-center bg-destructive/10 px-6 py-2 text-xs font-medium text-destructive">
                    {pageOverflowBanner.message}
                </div>
            )}

            {errorMessage && (
                <div className="flex shrink-0 items-center justify-between bg-destructive/10 px-6 py-2 text-xs font-medium text-destructive">
                    <span>{errorMessage}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-5"
                        onClick={() => setErrorMessage(null)}
                    >
                        <X className="size-3" />
                    </Button>
                </div>
            )}

            <div className="flex min-h-0 flex-1 overflow-hidden">
                {/* Left panel — 300px */}
                <div className="flex min-h-0 w-[300px] shrink-0 flex-col border-r border-border/80 bg-muted/20">
                    {/* TEXT section */}
                    {isEditable && !isSamplePreview && (
                        <div className="border-b border-border/60 p-3">
                            <p className="mb-2 text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                Text
                            </p>
                            <Button
                                size="sm"
                                variant={
                                    pendingPlacement?.kind === 'text'
                                        ? 'default'
                                        : 'outline'
                                }
                                className="w-full"
                                onClick={() =>
                                    setPendingPlacement((current) =>
                                        current?.kind === 'text'
                                            ? null
                                            : { kind: 'text' },
                                    )
                                }
                                disabled={isLoadingPdf}
                            >
                                <Plus className="mr-1.5 size-3.5" />
                                Add Text Box
                            </Button>
                        </div>
                    )}

                    {/* MERGE FIELDS section */}
                    <div className="border-b border-border/60 p-3">
                        <div className="relative">
                            <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                            <Input
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search merge fields..."
                                className="h-8 bg-background pl-8 text-xs"
                            />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-3">
                        {Object.entries(categories).map(
                            ([category, fields]) => (
                                <div key={category} className="space-y-1.5">
                                    <p className="px-1 text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                        {category}
                                    </p>
                                    <div className="space-y-1">
                                        {fields.map((field) => (
                                            <div
                                                key={field.key}
                                                className="group flex items-center justify-between rounded-lg border border-border/60 bg-background p-2 transition-colors hover:border-primary/50 hover:bg-muted/30"
                                            >
                                                <div className="min-w-0 pr-2">
                                                    <p className="truncate text-xs font-medium text-foreground">
                                                        {field.label}
                                                    </p>
                                                    <p className="truncate font-mono text-[10px] text-muted-foreground">
                                                        {field.key}
                                                    </p>
                                                </div>
                                                {isEditable &&
                                                    !isSamplePreview && (
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant={
                                                                pendingPlacement?.kind ===
                                                                    'field' &&
                                                                pendingPlacement.fieldKey ===
                                                                    field.key
                                                                    ? 'default'
                                                                    : 'ghost'
                                                            }
                                                            className="size-6 shrink-0 text-primary opacity-80 group-hover:opacity-100"
                                                            onClick={() =>
                                                                setPendingPlacement(
                                                                    (
                                                                        current,
                                                                    ) =>
                                                                        current?.kind ===
                                                                            'field' &&
                                                                        current.fieldKey ===
                                                                            field.key
                                                                            ? null
                                                                            : {
                                                                                  kind: 'field',
                                                                                  fieldKey:
                                                                                      field.key,
                                                                                  label: field.label,
                                                                              },
                                                                )
                                                            }
                                                            disabled={
                                                                isLoadingPdf
                                                            }
                                                            title={`Place ${field.label} on the page`}
                                                        >
                                                            <Plus className="size-3.5" />
                                                        </Button>
                                                    )}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ),
                        )}
                    </div>

                    {/* SIGNATURES section */}
                    <div className="space-y-2 border-t border-border/60 p-3">
                        <p className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                            Signatures
                        </p>
                        {sortedSlotKeys(signaturePlacements).map((slotKey) => (
                            <div
                                key={slotKey}
                                className="flex items-center justify-between rounded border border-border/60 bg-background p-2"
                            >
                                <div>
                                    <p className="text-xs font-medium">
                                        {slotLabel(slotKey)}
                                    </p>
                                    <p className="font-mono text-[10px] text-muted-foreground">
                                        {slotKey}
                                    </p>
                                </div>
                                {isEditable && !isSamplePreview && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-6 text-destructive"
                                        onClick={() => removeSlot(slotKey)}
                                    >
                                        <Trash2 className="size-3" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {isEditable && !isSamplePreview && (
                            <>
                                {canAddSubject && (
                                    <Button
                                        size="sm"
                                        variant={
                                            pendingPlacement?.kind ===
                                                'signature' &&
                                            pendingPlacement.role === 'subject'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        className="w-full"
                                        onClick={() =>
                                            setPendingPlacement((current) =>
                                                current?.kind === 'signature' &&
                                                current.role === 'subject'
                                                    ? null
                                                    : {
                                                          kind: 'signature',
                                                          role: 'subject',
                                                      },
                                            )
                                        }
                                    >
                                        <Plus className="mr-1.5 size-3.5" />
                                        Add Employee Signature
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant={
                                        pendingPlacement?.kind ===
                                            'signature' &&
                                        pendingPlacement.role === 'manager'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="w-full"
                                    onClick={() =>
                                        setPendingPlacement((current) =>
                                            current?.kind === 'signature' &&
                                            current.role === 'manager'
                                                ? null
                                                : {
                                                      kind: 'signature',
                                                      role: 'manager',
                                                  },
                                        )
                                    }
                                    disabled={!canAddManager}
                                >
                                    <Plus className="mr-1.5 size-3.5" />
                                    Add Manager Signer
                                </Button>
                                <Button
                                    size="sm"
                                    variant={
                                        pendingPlacement?.kind ===
                                            'signature' &&
                                        pendingPlacement.role ===
                                            'company_signatory'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="w-full"
                                    onClick={() =>
                                        setPendingPlacement((current) =>
                                            current?.kind === 'signature' &&
                                            current.role === 'company_signatory'
                                                ? null
                                                : {
                                                      kind: 'signature',
                                                      role: 'company_signatory',
                                                  },
                                        )
                                    }
                                    disabled={!canAddCompany}
                                >
                                    <Plus className="mr-1.5 size-3.5" />
                                    Add Company Signatory
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Center — canvas */}
                <div
                    ref={containerRef}
                    className={cn(
                        'relative flex min-h-0 flex-1 flex-col items-center overflow-y-auto overscroll-contain bg-muted/40 p-6',
                        pendingPlacement &&
                            isEditable &&
                            !isSamplePreview &&
                            'cursor-crosshair',
                    )}
                >
                    {isLoadingVersion && (
                        <div className="absolute inset-0 z-30 flex flex-col items-center justify-center bg-background/70 backdrop-blur-sm">
                            <Loader2 className="size-8 animate-spin text-primary" />
                            <p className="mt-2 text-xs font-medium text-muted-foreground">
                                Loading version...
                            </p>
                        </div>
                    )}

                    {placementError && (
                        <div className="sticky top-0 z-10 mb-4 flex w-full items-center justify-between rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-2 text-xs text-destructive">
                            <span>{placementError}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-5"
                                onClick={() => setPlacementError(null)}
                            >
                                <X className="size-3" />
                            </Button>
                        </div>
                    )}

                    <div className="relative w-fit shrink-0 overflow-hidden rounded-lg border border-border bg-white shadow-lg">
                        <div
                            className={cn(
                                'absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/70 backdrop-blur-xs transition-opacity',
                                !isLoadingPdf &&
                                    'pointer-events-none opacity-0',
                            )}
                            aria-hidden={!isLoadingPdf}
                        >
                            <Loader2 className="size-8 animate-spin text-primary" />
                            <p className="mt-2 text-xs font-medium text-muted-foreground">
                                Loading page {currentPage}...
                            </p>
                        </div>
                        <div ref={canvasHostRef} />
                    </div>

                    {signatureError && (
                        <div className="mt-4 flex w-full items-center justify-between rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-2 text-xs text-destructive">
                            <span>{signatureError}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-5"
                                onClick={() => setSignatureError(null)}
                            >
                                <X className="size-3" />
                            </Button>
                        </div>
                    )}
                </div>

                {/* Right panel — 260px */}
                <div className="flex w-[260px] shrink-0 flex-col border-l border-border/80 bg-background">
                    <div className="border-b border-border/60 px-3 py-2">
                        <p className="text-xs font-semibold text-foreground">
                            Properties
                        </p>
                    </div>
                    <div className="min-h-0 flex-1 overflow-y-auto p-3">
                        {!selectedElementId && isEditable && (
                            <p className="text-xs text-muted-foreground">
                                Select an element to edit its properties.
                            </p>
                        )}
                        {!selectedElementId && !isEditable && (
                            <VersionInfoPanel
                                version={selectedVersion}
                                changeSummary={changeSummary}
                            />
                        )}
                        {fieldPanelEl}
                        {textPanelEl}
                        {signaturePanelEl}
                    </div>
                </div>
            </div>
        </>
    );

    // ─── Dialogs ──────────────────────────────────────────────────────────────
    const versionDiscardDialog = (
        <AlertDialog
            open={isDiscardConfirmOpen}
            onOpenChange={setIsDiscardConfirmOpen}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        You have unsaved design changes.
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Switching versions will discard unsaved changes to both
                        placements and signature positions.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        onClick={() => {
                            setPendingVersionSwitch(null);
                            setIsDiscardConfirmOpen(false);
                        }}
                    >
                        Stay on Draft
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className={buttonVariants({ variant: 'destructive' })}
                        onClick={() => {
                            setIsDiscardConfirmOpen(false);
                            setHasUnsavedChanges(false);

                            if (pendingVersionSwitch) {
                                void doVersionSwitch(pendingVersionSwitch);
                            }

                            setPendingVersionSwitch(null);
                        }}
                    >
                        Discard changes and switch
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );

    const createDraftConfirmDialog = (
        <AlertDialog
            open={isCreateDraftConfirmOpen}
            onOpenChange={setIsCreateDraftConfirmOpen}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Create a new draft?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This copies the published design into a new editable
                        draft. The live published version stays unchanged until
                        you publish this draft. Are you sure you want to
                        continue?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => {
                            setIsCreateDraftConfirmOpen(false);
                            handleCreateDraft();
                        }}
                    >
                        Create Draft
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );

    const publishConfirmDialog = (
        <AlertDialog
            open={isPublishConfirmOpen}
            onOpenChange={setIsPublishConfirmOpen}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Publish this draft?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {hasUnsavedChanges
                            ? 'Your unsaved changes will be saved first. Publishing makes this design the live template for new documents. Are you sure you want to publish?'
                            : 'Publishing makes this design the live template for new documents. Previous published versions are archived. Are you sure you want to publish?'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => {
                            setIsPublishConfirmOpen(false);
                            void handlePublish();
                        }}
                    >
                        Publish Draft
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );

    const closeDiscardDialog = (
        <AlertDialog
            open={isCloseDiscardOpen}
            onOpenChange={setIsCloseDiscardOpen}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Unsaved Design Changes</AlertDialogTitle>
                    <AlertDialogDescription>
                        You have unsaved changes. Are you sure you want to
                        discard them?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        onClick={() => setIsCloseDiscardOpen(false)}
                    >
                        Keep Editing
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className={buttonVariants({ variant: 'destructive' })}
                        onClick={() => {
                            setIsCloseDiscardOpen(false);
                            setHasUnsavedChanges(false);
                            onOpenChange(false);
                        }}
                    >
                        Discard Changes
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );

    if (mode === 'page') {
        return (
            <div className="flex h-full min-h-0 w-full flex-1 flex-col overflow-hidden border border-border/60 bg-background">
                {workspace}
                {versionDiscardDialog}
                {createDraftConfirmDialog}
                {publishConfirmDialog}
                {closeDiscardDialog}
            </div>
        );
    }

    return (
        <Dialog open={open} onOpenChange={handleSafeOpenChange}>
            <DialogContent className="flex h-[92vh] max-h-[92vh] w-[1400px] max-w-[96vw] flex-col overflow-hidden p-0 sm:max-w-[96vw]">
                {workspace}
            </DialogContent>
            {versionDiscardDialog}
            {createDraftConfirmDialog}
            {publishConfirmDialog}
            {closeDiscardDialog}
        </Dialog>
    );
}
