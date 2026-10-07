import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    messages: string[];
    onContinueEditing?: () => void;
    continueEditingLabel?: string;
};

export function SubmissionReadinessBlockedDialog({
    open,
    onOpenChange,
    messages,
    onContinueEditing,
    continueEditingLabel = 'Continue editing',
}: Props) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Requirement isn&apos;t ready for approval
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-2 text-sm text-muted-foreground">
                            <p>Please complete:</p>
                            <ul className="list-disc space-y-1 pl-5 text-foreground">
                                {messages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    {onContinueEditing ? (
                        <>
                            <AlertDialogCancel>Close</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => {
                                    onOpenChange(false);
                                    onContinueEditing();
                                }}
                            >
                                {continueEditingLabel}
                            </AlertDialogAction>
                        </>
                    ) : (
                        <AlertDialogAction onClick={() => onOpenChange(false)}>
                            Continue editing
                        </AlertDialogAction>
                    )}
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
