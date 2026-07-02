export function DocumentsIndexSectionHeading({
    label,
    count,
}: {
    label: string;
    count: number;
}) {
    return (
        <h2 className="text-base font-semibold tracking-tight text-foreground">
            {label}{' '}
            <span className="font-normal text-muted-foreground tabular-nums">
                ({count})
            </span>
        </h2>
    );
}
