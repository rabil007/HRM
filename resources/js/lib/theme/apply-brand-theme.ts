function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
    const normalized = hex.trim().replace(/^#/, '');

    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
        return null;
    }

    return {
        r: parseInt(normalized.slice(0, 2), 16),
        g: parseInt(normalized.slice(2, 4), 16),
        b: parseInt(normalized.slice(4, 6), 16),
    };
}

function relativeLuminance({ r, g, b }: { r: number; g: number; b: number }): number {
    const channel = (value: number) => {
        const normalized = value / 255;

        return normalized <= 0.03928
            ? normalized / 12.92
            : ((normalized + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function foregroundForBackground(hex: string): string {
    const rgb = hexToRgb(hex);

    if (!rgb) {
        return '#ffffff';
    }

    return relativeLuminance(rgb) > 0.45 ? '#0f172a' : '#f8fafc';
}

function mixWithAlpha(hex: string, alpha: number): string {
    const rgb = hexToRgb(hex);

    if (!rgb) {
        return hex;
    }

    return `rgb(${rgb.r} ${rgb.g} ${rgb.b} / ${alpha})`;
}

export function applyBrandTheme(primaryColor: string, accentColor: string): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const primary = primaryColor.trim();
    const accent = accentColor.trim();
    const primaryForeground = foregroundForBackground(primary);
    const accentForeground = foregroundForBackground(accent);

    /** Primary — buttons, active states, focus rings */
    root.style.setProperty('--brand-primary', primary);
    root.style.setProperty('--primary', primary);
    root.style.setProperty('--primary-foreground', primaryForeground);
    root.style.setProperty('--ring', mixWithAlpha(primary, 0.45));

    /** Sidebar active items inherit primary */
    root.style.setProperty('--sidebar-primary', primary);
    root.style.setProperty('--sidebar-primary-foreground', primaryForeground);
    root.style.setProperty('--sidebar-ring', mixWithAlpha(primary, 0.45));

    /** Accent — hover surfaces, badges, secondary highlights */
    root.style.setProperty('--brand-accent', accent);
    root.style.setProperty('--accent', accent);
    root.style.setProperty('--accent-foreground', accentForeground);

    /** Chart-1 tracks primary for consistency */
    root.style.setProperty('--chart-1', primary);
}
