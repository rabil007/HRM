import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
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
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';
import type { TrainingItem } from '@/pages/organization/employee-page.types';
import { linkDocument } from '@/routes/organization/employees/training';

type SearchDocument = {
    id: number;
    title: string;
    original_filename: string | null;
    expiry_date: string | null;
    has_file: boolean;
};

export function LinkTrainingCertificateDialog({
    training,
    employeeId,
    onOpenChange,
    partialReloadKeys = ['trainings'],
}: {
    training: TrainingItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
    partialReloadKeys?: string[];
}): ReactElement {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchDocument[]>([]);
    const [isSearching, setIsSearching] = useState(false);

    const form = useForm({
        employee_document_id: null as number | null,
        confirm_replace_link: false,
    });

    useEffect(() => {
        if (!training) {
            return;
        }

        form.setData({
            employee_document_id: null,
            confirm_replace_link: false,
        });
        form.clearErrors();
        setQuery('');
        setResults([]);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset per training
    }, [training?.id]);

    useEffect(() => {
        if (!training) {
            return;
        }

        const handle = window.setTimeout(async () => {
            setIsSearching(true);

            try {
                const url = `/organization/employees/${employeeId}/training/documents/search?q=${encodeURIComponent(query)}`;
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Search failed');
                }

                const payload = (await response.json()) as {
                    documents: SearchDocument[];
                };

                setResults(payload.documents);
            } catch {
                toast.error('Could not search employee documents.');
            } finally {
                setIsSearching(false);
            }
        }, 300);

        return () => window.clearTimeout(handle);
    }, [employeeId, query, training]);

    const selected = useMemo(
        () =>
            results.find(
                (document) => document.id === form.data.employee_document_id,
            ) ?? null,
        [form.data.employee_document_id, results],
    );

    const submit = (): void => {
        if (!training || form.data.employee_document_id === null) {
            return;
        }

        form.post(
            linkDocument.url({
                employee: employeeId,
                training: training.id,
            }),
            {
                preserveScroll: true,
                only: partialReloadKeys,
                onSuccess: () => {
                    toast.success('Training certificate linked.');
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={!!training} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Select existing document</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        Link a Training Certificate document already on this
                        employee profile. The file is not duplicated.
                    </p>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="training-document-search">Search</Label>
                        <Input
                            id="training-document-search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search by title or filename"
                        />
                    </div>

                    <div className="max-h-56 space-y-2 overflow-y-auto rounded-xl border border-border p-2">
                        {isSearching ? (
                            <p className="px-2 py-3 text-sm text-muted-foreground">
                                Searching…
                            </p>
                        ) : results.length === 0 ? (
                            <p className="px-2 py-3 text-sm text-muted-foreground">
                                No Training Certificate documents found.
                            </p>
                        ) : (
                            results.map((document) => (
                                <button
                                    key={document.id}
                                    type="button"
                                    className={`w-full rounded-lg px-3 py-2 text-left text-sm transition hover:bg-muted ${
                                        form.data.employee_document_id ===
                                        document.id
                                            ? 'bg-muted ring-1 ring-primary/30'
                                            : ''
                                    }`}
                                    onClick={() =>
                                        form.setData(
                                            'employee_document_id',
                                            document.id,
                                        )
                                    }
                                >
                                    <div className="font-medium">
                                        {document.title}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {document.original_filename ??
                                            'No filename'}
                                        {document.expiry_date
                                            ? ` · Expires ${document.expiry_date}`
                                            : ''}
                                    </div>
                                </button>
                            ))
                        )}
                    </div>

                    {training?.employee_document_id ? (
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.confirm_replace_link}
                                onChange={(event) =>
                                    form.setData(
                                        'confirm_replace_link',
                                        event.target.checked,
                                    )
                                }
                            />
                            <span>
                                Replace the current linked document association
                                for this training record.
                            </span>
                        </label>
                    ) : null}

                    {selected ? (
                        <p className="text-sm text-muted-foreground">
                            Selected: {selected.title}
                        </p>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className={actions.dialogPrimary}
                        disabled={
                            form.processing ||
                            form.data.employee_document_id === null
                        }
                        onClick={submit}
                    >
                        Link document
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
