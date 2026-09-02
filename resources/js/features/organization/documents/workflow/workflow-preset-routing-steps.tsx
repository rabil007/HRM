type Stage = {
    sequence: number;
    action_label: string;
    targets: Array<{ label: string }>;
};

export function WorkflowPresetRoutingSteps({
    stages,
    summary,
}: {
    stages: Stage[] | undefined;
    summary: string;
}) {
    if (!stages || stages.length === 0) {
        return (
            <p
                className="text-sm break-words text-muted-foreground"
                title={summary}
            >
                {summary || '—'}
            </p>
        );
    }

    return (
        <ol className="flex flex-wrap items-center gap-1.5" title={summary}>
            {stages.map((stage, index) => {
                const assignees = stage.targets
                    .map((target) => target.label)
                    .filter(Boolean)
                    .join(', ');

                return (
                    <li
                        key={`${stage.sequence}-${stage.action_label}`}
                        className="flex items-center gap-1.5"
                    >
                        {index > 0 ? (
                            <span
                                className="text-muted-foreground/70"
                                aria-hidden
                            >
                                →
                            </span>
                        ) : null}
                        <span className="inline-flex max-w-full min-w-0 items-baseline gap-1 rounded-md bg-muted/70 px-1.5 py-0.5 text-xs leading-5 text-foreground">
                            <span className="font-semibold tabular-nums">
                                {stage.sequence}
                            </span>
                            <span className="font-medium">
                                {stage.action_label}
                            </span>
                            {assignees ? (
                                <span className="truncate text-muted-foreground">
                                    · {assignees}
                                </span>
                            ) : null}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
