type Stage = {
    sequence: number;
    action_label: string;
};

type Props = {
    presetName: string;
    stages: Stage[];
};

export function TemplateApprovalFlowSteps({ presetName, stages }: Props) {
    return (
        <div className="space-y-2">
            <p className="truncate text-xs font-medium text-foreground">
                {presetName}
            </p>
            <ol className="space-y-0">
                {stages.map((stage, index) => (
                    <li key={`${stage.sequence}-${stage.action_label}`}>
                        <div className="flex items-start gap-2">
                            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-foreground">
                                {stage.sequence}
                            </span>
                            <span className="pt-0.5 text-xs leading-4 text-foreground">
                                {stage.action_label}
                            </span>
                        </div>
                        {index < stages.length - 1 ? (
                            <div
                                className="ml-2.5 h-3 border-l border-border"
                                aria-hidden
                            />
                        ) : null}
                    </li>
                ))}
            </ol>
        </div>
    );
}
