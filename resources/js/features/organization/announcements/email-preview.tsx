import { Mail } from 'lucide-react';

type EmailPreviewProps = {
    subject: string;
    html: string;
    accountName: string;
    hint?: string;
};

export function EmailPreview({
    subject,
    html,
    accountName,
    hint,
}: EmailPreviewProps) {
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                <Mail className="size-4 text-sky-500" />
                Email preview
            </div>

            {hint ? (
                <p className="text-xs text-muted-foreground">{hint}</p>
            ) : null}

            <div className="overflow-hidden rounded-lg border border-border/70 bg-background shadow-sm">
                {/* Email header mockup */}
                <div className="border-b border-border/60 bg-muted/30 px-3 py-2.5">
                    <div className="flex items-center gap-2 text-xs">
                        <span className="font-medium text-muted-foreground">
                            From:
                        </span>
                        <span className="text-foreground">{accountName}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2 text-xs">
                        <span className="font-medium text-muted-foreground">
                            Subject:
                        </span>
                        <span className="text-foreground">{subject}</span>
                    </div>
                </div>

                {/* Email content preview */}
                <div className="max-h-96 overflow-y-auto bg-white">
                    <iframe
                        srcDoc={html}
                        title="Email preview"
                        className="h-full min-h-[300px] w-full border-0"
                        sandbox="allow-same-origin"
                    />
                </div>
            </div>
        </div>
    );
}
