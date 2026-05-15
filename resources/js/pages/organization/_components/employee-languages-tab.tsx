import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyLanguage,
    store as storeLanguage,
    update as updateLanguage,
} from '@/actions/App/Http/Controllers/Organization/EmployeeLanguageController';
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
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import { toast } from '@/lib/toast';
import type { LanguageItem } from '@/pages/organization/employee-page.types';

export type EmployeeLanguagesTabProps = {
    employeeId: number;
    languages: LanguageItem[];
    canManage: boolean;
};

export function EmployeeLanguagesTab({
    employeeId,
    languages,
    canManage,
}: EmployeeLanguagesTabProps): ReactElement {
    const [languageDialogOpen, setLanguageDialogOpen] = useState(false);
    const [editingLanguage, setEditingLanguage] = useState<LanguageItem | null>(
        null,
    );
    const [deleteLanguageId, setDeleteLanguageId] = useState<number | null>(
        null,
    );

    const languageForm = useForm({
        language_name: '',
        is_spoken: false,
        is_written: false,
        is_understood: false,
        is_mother_tongue: false,
    });

    return (
        <TabsContent value="languages" className="mt-6">
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Languages
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {languages.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={() => {
                                languageForm.reset();
                                languageForm.clearErrors();
                                languageForm.setData({
                                    language_name: '',
                                    is_spoken: false,
                                    is_written: false,
                                    is_understood: false,
                                    is_mother_tongue: false,
                                });
                                setEditingLanguage(null);
                                setLanguageDialogOpen(true);
                            }}
                        >
                            + Add line
                        </Button>
                    ) : null}
                </div>

                {languages.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No languages recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Language</th>
                                    <th className="py-2 pr-4 text-center">
                                        Spoken
                                    </th>
                                    <th className="py-2 pr-4 text-center">
                                        Written
                                    </th>
                                    <th className="py-2 pr-4 text-center">
                                        Understood
                                    </th>
                                    <th className="py-2 pr-4 text-center">
                                        Mother tongue
                                    </th>
                                    <th className="py-2 pr-4">Added</th>
                                    {canManage ? (
                                        <th className="py-2 pr-4 text-right" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {languages.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="text-sm text-zinc-200"
                                    >
                                        <td
                                            className="max-w-[220px] truncate py-3 pr-4 font-medium"
                                            title={row.language_name}
                                        >
                                            {row.language_name}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_spoken ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_written ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_understood ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_mother_tongue ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-xs whitespace-nowrap text-zinc-500">
                                            {new Date(
                                                row.created_at,
                                            ).toLocaleString(undefined, {
                                                month: 'short',
                                                day: 'numeric',
                                                hour: 'numeric',
                                                minute: '2-digit',
                                            })}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 pr-0 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                        onClick={() => {
                                                            setEditingLanguage(
                                                                row,
                                                            );
                                                            languageForm.setData(
                                                                {
                                                                    language_name:
                                                                        row.language_name,
                                                                    is_spoken:
                                                                        row.is_spoken,
                                                                    is_written:
                                                                        row.is_written,
                                                                    is_understood:
                                                                        row.is_understood,
                                                                    is_mother_tongue:
                                                                        row.is_mother_tongue,
                                                                },
                                                            );
                                                            languageForm.clearErrors();
                                                            setLanguageDialogOpen(
                                                                true,
                                                            );
                                                        }}
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                        onClick={() =>
                                                            setDeleteLanguageId(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog
                open={languageDialogOpen}
                onOpenChange={(openDialog) => {
                    setLanguageDialogOpen(openDialog);

                    if (!openDialog) {
                        languageForm.reset();
                        languageForm.clearErrors();
                        setEditingLanguage(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingLanguage ? 'Edit language' : 'Add language'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs">Language</Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                value={languageForm.data.language_name}
                                onChange={(e) =>
                                    languageForm.setData(
                                        'language_name',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. English (US)"
                            />
                            {languageForm.errors.language_name ? (
                                <p className="text-xs text-destructive">
                                    {languageForm.errors.language_name}
                                </p>
                            ) : null}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="flex items-center gap-2 text-sm text-zinc-200">
                                <Checkbox
                                    checked={languageForm.data.is_spoken}
                                    onCheckedChange={(v) =>
                                        languageForm.setData(
                                            'is_spoken',
                                            v === true,
                                        )
                                    }
                                />
                                Spoken
                            </label>
                            <label className="flex items-center gap-2 text-sm text-zinc-200">
                                <Checkbox
                                    checked={languageForm.data.is_written}
                                    onCheckedChange={(v) =>
                                        languageForm.setData(
                                            'is_written',
                                            v === true,
                                        )
                                    }
                                />
                                Written
                            </label>
                            <label className="flex items-center gap-2 text-sm text-zinc-200">
                                <Checkbox
                                    checked={languageForm.data.is_understood}
                                    onCheckedChange={(v) =>
                                        languageForm.setData(
                                            'is_understood',
                                            v === true,
                                        )
                                    }
                                />
                                Understood
                            </label>
                            <label className="flex items-center gap-2 text-sm text-zinc-200">
                                <Checkbox
                                    checked={languageForm.data.is_mother_tongue}
                                    onCheckedChange={(v) =>
                                        languageForm.setData(
                                            'is_mother_tongue',
                                            v === true,
                                        )
                                    }
                                />
                                Mother tongue
                            </label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={() => setLanguageDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            disabled={languageForm.processing}
                            onClick={() => {
                                languageForm.clearErrors();
                                languageForm.transform((data) => ({
                                    language_name: data.language_name.trim(),
                                    is_spoken: !!data.is_spoken,
                                    is_written: !!data.is_written,
                                    is_understood: !!data.is_understood,
                                    is_mother_tongue: !!data.is_mother_tongue,
                                }));

                                const url = editingLanguage
                                    ? updateLanguage.url({
                                          employee: employeeId,
                                          language: editingLanguage.id,
                                      })
                                    : storeLanguage.url({
                                          employee: employeeId,
                                      });

                                if (editingLanguage) {
                                    languageForm.put(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setLanguageDialogOpen(false);
                                            languageForm.reset();
                                            setEditingLanguage(null);
                                            toast.success('Language updated.');
                                        },
                                    });
                                } else {
                                    languageForm.post(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setLanguageDialogOpen(false);
                                            languageForm.reset();
                                            toast.success('Language added.');
                                        },
                                    });
                                }
                            }}
                        >
                            {languageForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteLanguageId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteLanguageId(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove language?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This entry will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="text-destructive-foreground bg-destructive hover:bg-destructive/90"
                            onClick={() => {
                                if (!deleteLanguageId) {
                                    return;
                                }

                                router.delete(
                                    destroyLanguage.url({
                                        employee: employeeId,
                                        language: deleteLanguageId,
                                    }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDeleteLanguageId(null);
                                            toast.success('Language removed.');
                                        },
                                    },
                                );
                            }}
                        >
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </TabsContent>
    );
}
