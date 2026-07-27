export function SalaryCell({ value }: { value: string | null | undefined }) {
    if (!value || Number(value) === 0) {
        return <span className="text-xs text-muted-foreground/40">—</span>;
    }

    return (
        <span className="font-medium tabular-nums">
            {Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}
        </span>
    );
}
