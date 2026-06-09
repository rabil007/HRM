import { cn } from '@/lib/utils';

type Props = {
    id: string;
    value: string;
    onChange: (value: string) => void;
};

function toColorInputValue(value: string): string {
    const hex = value.trim();

    if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
        return hex.toLowerCase();
    }

    if (/^#[0-9A-Fa-f]{3}$/.test(hex)) {
        const [, r, g, b] = hex;

        return `#${r}${r}${g}${g}${b}${b}`.toLowerCase();
    }

    return '#000000';
}

export function ThemeColorPicker({ id, value, onChange }: Props) {
    const colorValue = toColorInputValue(value);

    return (
        <input
            id={id}
            type="color"
            value={colorValue}
            onChange={(event) => onChange(event.target.value)}
            aria-label="Choose color"
            className={cn(
                'h-14 w-full cursor-pointer rounded-xl border border-white/10 bg-white/5 p-1.5 transition-all',
                'hover:border-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                '[&::-webkit-color-swatch-wrapper]:p-0',
                '[&::-webkit-color-swatch]:rounded-lg [&::-webkit-color-swatch]:border-0',
                '[&::-moz-color-swatch]:rounded-lg [&::-moz-color-swatch]:border-0',
            )}
        />
    );
}
