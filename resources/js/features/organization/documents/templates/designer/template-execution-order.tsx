type Props = {
    steps: string[];
};

export function TemplateExecutionOrder({ steps }: Props) {
    return (
        <ol className="space-y-0">
            {steps.map((step, index) => (
                <li key={step}>
                    <div className="flex items-center gap-2">
                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-foreground">
                            {index + 1}
                        </span>
                        <span className="text-xs font-medium text-foreground">
                            {step}
                        </span>
                    </div>
                    {index < steps.length - 1 ? (
                        <div
                            className="ml-2.5 flex h-4 items-center"
                            aria-hidden
                        >
                            <span className="h-full border-l border-border" />
                        </div>
                    ) : null}
                </li>
            ))}
        </ol>
    );
}
