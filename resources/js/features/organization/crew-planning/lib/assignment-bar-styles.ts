/** Opaque assignment bar surfaces — avoids today-line / row tints bleeding through transparent fills. */
export const assignmentBarSurfaceClass =
    'border border-[color-mix(in_oklch,var(--primary)_38%,var(--border))] bg-[color-mix(in_oklch,var(--primary)_14%,var(--background))]';

export const assignmentBarAvatarClass =
    'bg-[color-mix(in_oklch,var(--primary)_24%,var(--background))] text-primary';

export const assignmentBarResizeHandleClass =
    'hover:bg-[color-mix(in_oklch,var(--primary)_28%,var(--background))]';

/** Vacant slot bars — dashed border, muted fill, visually distinct from assigned bars. */
export const vacantBarSurfaceClass =
    'border border-dashed border-muted-foreground/30 bg-muted/20';

export const vacantBarAvatarClass =
    'bg-muted-foreground/10 text-muted-foreground/50';
