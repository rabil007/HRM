import { Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    AnnouncementAiAssistAction,
    AnnouncementAiAssistResult,
} from '@/features/organization/announcements/types';

const ACTIONS: { value: AnnouncementAiAssistAction; label: string }[] = [
    { value: 'generate', label: 'Generate from instructions' },
    { value: 'improve', label: 'Improve writing' },
    { value: 'make_professional', label: 'Make professional' },
    { value: 'make_friendly', label: 'Make friendly' },
    { value: 'shorten', label: 'Shorten' },
    { value: 'fix_grammar', label: 'Fix grammar' },
    { value: 'create_whatsapp_version', label: 'Create WhatsApp version' },
    { value: 'suggest_template', label: 'Suggest WhatsApp template' },
];

type AnnouncementAiAssistDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    available: boolean;
    processing: boolean;
    error: string | null;
    result: AnnouncementAiAssistResult | null;
    onRun: (action: AnnouncementAiAssistAction, instructions: string) => void;
    onApplyContent: (result: AnnouncementAiAssistResult) => void;
    onUseSuggestedTemplate: (templateId: number) => void;
    onKeepCurrentTemplate: () => void;
};

export function AnnouncementAiAssistDialog({
    open,
    onOpenChange,
    available,
    processing,
    error,
    result,
    onRun,
    onApplyContent,
    onUseSuggestedTemplate,
    onKeepCurrentTemplate,
}: AnnouncementAiAssistDialogProps) {
    const [action, setAction] = useState<AnnouncementAiAssistAction>('improve');
    const [instructions, setInstructions] = useState('');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Sparkles className="size-4 text-primary" />
                        AI Assist
                    </DialogTitle>
                    <DialogDescription>
                        Improve announcement content or suggest a WhatsApp
                        template. AI never publishes or sends automatically.
                    </DialogDescription>
                </DialogHeader>

                {!available ? (
                    <p className="text-sm text-muted-foreground">
                        AI assistance is not configured. You can still write
                        content and select templates manually.
                    </p>
                ) : (
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label>Action</Label>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {ACTIONS.map((item) => (
                                    <button
                                        key={item.value}
                                        type="button"
                                        onClick={() => setAction(item.value)}
                                        className={
                                            action === item.value
                                                ? 'rounded-lg border border-primary/40 bg-primary/5 px-3 py-2 text-left text-sm font-medium'
                                                : 'rounded-lg border border-border/70 px-3 py-2 text-left text-sm text-muted-foreground hover:bg-muted/40'
                                        }
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {action === 'generate' ? (
                            <div className="space-y-2">
                                <Label htmlFor="ai_instructions">
                                    Instructions
                                </Label>
                                <Textarea
                                    id="ai_instructions"
                                    value={instructions}
                                    onChange={(event) =>
                                        setInstructions(event.target.value)
                                    }
                                    rows={4}
                                    placeholder="Describe the announcement you want to create…"
                                />
                            </div>
                        ) : null}

                        {error ? <InputError message={error} /> : null}

                        {result ? (
                            <div className="space-y-3 rounded-xl border border-border/70 bg-muted/20 p-3 text-sm">
                                {result.title ? (
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Title
                                        </p>
                                        <p className="mt-1">{result.title}</p>
                                    </div>
                                ) : null}
                                {result.whatsapp_message ? (
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            WhatsApp
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap">
                                            {result.whatsapp_message}
                                        </p>
                                    </div>
                                ) : null}
                                {result.suggested_template ? (
                                    <div className="rounded-lg border border-primary/25 bg-primary/5 p-3">
                                        <p className="text-sm font-medium">
                                            Suggested:{' '}
                                            {result.suggested_template.label}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    onUseSuggestedTemplate(
                                                        result
                                                            .suggested_template!
                                                            .id,
                                                    )
                                                }
                                            >
                                                Use suggestion
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={onKeepCurrentTemplate}
                                            >
                                                Keep current
                                            </Button>
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                )}

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    {available ? (
                        <>
                            {result &&
                            (result.title ||
                                result.main_body ||
                                result.whatsapp_message) ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => onApplyContent(result)}
                                >
                                    Apply content
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                disabled={
                                    processing ||
                                    (action === 'generate' &&
                                        instructions.trim() === '')
                                }
                                onClick={() => onRun(action, instructions)}
                            >
                                {processing ? (
                                    <>
                                        <Loader2 className="size-4 animate-spin" />
                                        Working…
                                    </>
                                ) : (
                                    'Run'
                                )}
                            </Button>
                        </>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
