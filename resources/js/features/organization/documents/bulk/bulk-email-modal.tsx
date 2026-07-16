import { router } from '@inertiajs/react';
import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    dedupeEmails,
    formatBulkEmailBodyPreview,
    initialCcFromTemplate,
    isValidEmailAddress,
    substituteBulkEmailTemplate,
} from '@/features/organization/documents/bulk/bulk-email-utils';
import type { BulkEmailPreviewEmployee } from '@/features/organization/documents/bulk/bulk-email-utils';
import type { WiredEmailTemplate } from '@/features/organization/documents/bulk/types';
import { toast } from '@/lib/toast';

type RecipientSearchResult = {
    id: number;
    name: string;
    email: string;
};

export function BulkDocumentsEmailModal({
    onOpenChange,
    documentTypeKey,
    documentTypeLabel,
    employeeIds,
    emailTemplate,
    emailIntent = 'initial',
    companyName,
    previewEmployee,
    onSendComplete,
}: {
    onOpenChange: (open: boolean) => void;
    documentTypeKey: string;
    documentTypeLabel: string;
    employeeIds: number[];
    emailTemplate: WiredEmailTemplate | null;
    emailIntent?: 'initial' | 'reminder';
    companyName: string;
    previewEmployee: BulkEmailPreviewEmployee | null;
    onSendComplete: () => void;
}) {
    const [ccEmails, setCcEmails] = useState(() =>
        emailTemplate ? initialCcFromTemplate(emailTemplate) : [],
    );
    const [ccQuery, setCcQuery] = useState('');
    const [searchResults, setSearchResults] = useState<RecipientSearchResult[]>(
        [],
    );
    const [isSearching, setIsSearching] = useState(false);
    const [isSending, setIsSending] = useState(false);

    const trimmedQuery = ccQuery.trim();
    const looksLikeEmail = trimmedQuery.includes('@');
    const visibleSearchResults =
        !looksLikeEmail && trimmedQuery.length >= 2 ? searchResults : [];

    useEffect(() => {
        const query = trimmedQuery;

        if (query.length < 2 || looksLikeEmail) {
            return;
        }

        let cancelled = false;
        const timeout = window.setTimeout(() => {
            setIsSearching(true);

            const params = new URLSearchParams({ q: query });

            fetch(
                `/organization/documents/bulk/recipients-search?${params.toString()}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            )
                .then((response) => response.json())
                .then((data: { employees?: RecipientSearchResult[] }) => {
                    if (!cancelled) {
                        setSearchResults(data.employees ?? []);
                    }
                })
                .catch(() => {
                    if (!cancelled) {
                        setSearchResults([]);
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setIsSearching(false);
                    }
                });
        }, 300);

        return () => {
            cancelled = true;
            window.clearTimeout(timeout);
        };
    }, [trimmedQuery, looksLikeEmail]);

    const previewRecipient =
        previewEmployee?.email?.trim() ||
        'No email on file for preview employee';

    const previewSubject = emailTemplate
        ? substituteBulkEmailTemplate(
              emailTemplate.subject,
              previewEmployee ?? {
                  name: 'Employee',
                  employee_no: null,
                  email: null,
              },
              companyName,
              documentTypeLabel,
          )
        : '';

    const previewBody = emailTemplate
        ? substituteBulkEmailTemplate(
              emailTemplate.body_html,
              previewEmployee ?? {
                  name: 'Employee',
                  employee_no: null,
                  email: null,
              },
              companyName,
              documentTypeLabel,
          )
        : '';

    const addCcEmail = (email: string) => {
        const trimmed = email.trim();

        if (!isValidEmailAddress(trimmed)) {
            return;
        }

        setCcEmails((current) => dedupeEmails([...current, trimmed]));
    };

    const removeCcEmail = (email: string) => {
        setCcEmails((current) =>
            current.filter(
                (item) => item.toLowerCase() !== email.toLowerCase(),
            ),
        );
    };

    const handleAddCcFromQuery = () => {
        const value = trimmedQuery.replace(/,$/, '');

        if (!isValidEmailAddress(value)) {
            toast.error('Select an employee or enter a valid email address.');

            return;
        }

        addCcEmail(value);
        setCcQuery('');
        setSearchResults([]);
    };

    const handleCcQueryKeyDown = (
        event: React.KeyboardEvent<HTMLInputElement>,
    ) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            handleAddCcFromQuery();
        }
    };

    const handleOpenChange = (next: boolean) => {
        onOpenChange(next);
    };

    const handleSend = () => {
        if (employeeIds.length === 0 || !emailTemplate) {
            return;
        }

        setIsSending(true);

        router.post(
            '/organization/documents/bulk/email',
            {
                document_type_key: documentTypeKey,
                employee_ids: employeeIds,
                email_template_id: emailTemplate.id,
                email_intent: emailIntent,
                cc: ccEmails,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    handleOpenChange(false);
                    onSendComplete();
                    toast.success(
                        `Email queued for ${employeeIds.length} employee(s).`,
                    );
                },
                onFinish: () => setIsSending(false),
            },
        );
    };

    const showSearchSpinner =
        isSearching && trimmedQuery.length >= 2 && !looksLikeEmail;

    return (
        <Dialog open onOpenChange={handleOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Send to {employeeIds.length} employee
                        {employeeIds.length === 1 ? '' : 's'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4 py-2">
                    {emailTemplate ? (
                        <>
                            <div className="grid gap-2">
                                <Label>Email template</Label>
                                <div className="rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                                    <p className="font-medium">
                                        {emailTemplate.label}
                                    </p>
                                    <a
                                        href="/settings/application/email-templates"
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-xs text-primary hover:underline"
                                    >
                                        Manage templates
                                    </a>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label>Preview</Label>
                                <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                    <p className="text-xs text-muted-foreground">
                                        To: {previewRecipient}
                                    </p>
                                    <p className="mt-2 font-medium">
                                        {previewSubject}
                                    </p>
                                    <div
                                        className="prose prose-sm dark:prose-invert mt-2 max-w-none [&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0"
                                        dangerouslySetInnerHTML={{
                                            __html: formatBulkEmailBodyPreview(
                                                previewBody,
                                            ),
                                        }}
                                    />
                                    {employeeIds.length > 1 ? (
                                        <p className="mt-3 text-xs text-muted-foreground">
                                            Each of the {employeeIds.length}{' '}
                                            employees receives this email with
                                            their own details.
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label>CC</Label>
                                {ccEmails.length > 0 ? (
                                    <div className="flex flex-wrap gap-2">
                                        {ccEmails.map((email) => (
                                            <Badge
                                                key={email}
                                                variant="secondary"
                                                className="gap-1 pr-1"
                                            >
                                                {email}
                                                <button
                                                    type="button"
                                                    className="rounded-sm p-0.5 hover:bg-muted"
                                                    onClick={() =>
                                                        removeCcEmail(email)
                                                    }
                                                    aria-label={`Remove ${email}`}
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </Badge>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        No CC recipients added.
                                    </p>
                                )}

                                <div className="relative">
                                    <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        value={ccQuery}
                                        onChange={(event) =>
                                            setCcQuery(event.target.value)
                                        }
                                        onKeyDown={handleCcQueryKeyDown}
                                        placeholder="Search employees or type an email address…"
                                        className="pl-9"
                                    />
                                    {showSearchSpinner ? (
                                        <Loader2 className="absolute top-2.5 right-2.5 h-4 w-4 animate-spin text-muted-foreground" />
                                    ) : null}
                                    {visibleSearchResults.length > 0 ? (
                                        <div className="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-md border bg-popover shadow-md">
                                            {visibleSearchResults.map(
                                                (employee) => (
                                                    <button
                                                        key={employee.id}
                                                        type="button"
                                                        className="flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-muted"
                                                        onClick={() => {
                                                            addCcEmail(
                                                                employee.email,
                                                            );
                                                            setCcQuery('');
                                                            setSearchResults(
                                                                [],
                                                            );
                                                        }}
                                                    >
                                                        <span className="font-medium">
                                                            {employee.name}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {employee.email}
                                                        </span>
                                                    </button>
                                                ),
                                            )}
                                        </div>
                                    ) : null}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Pick an employee from search results, or
                                    type an email and press Enter.
                                </p>
                            </div>
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No email template is configured for this document
                            type.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSend}
                        disabled={
                            isSending ||
                            employeeIds.length === 0 ||
                            !emailTemplate
                        }
                    >
                        {isSending ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : null}
                        Send to {employeeIds.length} employee
                        {employeeIds.length === 1 ? '' : 's'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
