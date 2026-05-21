import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyLanguage,
    store as storeLanguage,
    update as updateLanguage,
} from '@/actions/App/Http/Controllers/Organization/EmployeeLanguageController';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
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
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import type { LanguageItem } from '@/pages/organization/employee-page.types';

const LANGUAGES_RELOAD = {
    preserveScroll: true,
    only: ['languages'],
} as const;

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
            <EmployeeRecordsPanel
                title="Languages"
                count={languages.length}
                isEmpty={languages.length === 0}
                emptyMessage="No languages recorded."
                actions={
                    canManage ? (
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
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[720px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Language</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Spoken</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Written</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Understood</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Mother tongue</th>
                            <th className={employeeRecordsTableThClass()}>Added</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {languages.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[220px] truncate font-medium text-foreground',
                                    )}
                                    title={row.language_name}
                                >
                                    {row.language_name}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_spoken ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-muted-foreground/50">—</span>
                                    )}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_written ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-muted-foreground/50">—</span>
                                    )}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_understood ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-muted-foreground/50">—</span>
                                    )}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_mother_tongue ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-muted-foreground/50">—</span>
                                    )}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-muted-foreground')}>
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingLanguage(row);
                                                languageForm.setData({
                                                    language_name: row.language_name,
                                                    is_spoken: row.is_spoken,
                                                    is_written: row.is_written,
                                                    is_understood: row.is_understood,
                                                    is_mother_tongue: row.is_mother_tongue,
                                                });
                                                languageForm.clearErrors();
                                                setLanguageDialogOpen(true);
                                            }}
                                            onDelete={() => setDeleteLanguageId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
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
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingLanguage ? 'Edit language' : 'Add language'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Specify the language and the employee's proficiency.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Language details</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Language <span className="text-red-400">*</span></Label>
                            <Input
                                className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                value={languageForm.data.language_name}
                                onChange={(e) => languageForm.setData('language_name', e.target.value)}
                                placeholder="e.g. English, Arabic, Spanish"
                            />
                            {languageForm.errors.language_name ? (
                                <p className="text-xs text-destructive">{languageForm.errors.language_name}</p>
                            ) : (
                                <p className="text-[11px] text-muted-foreground">The name of the language</p>
                            )}
                        </div>

                        <div className="flex items-center gap-2 pt-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Proficiencies</span>
                            <div className="h-px flex-1 bg-muted/50" />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                <label className="flex items-center gap-3 text-sm text-foreground">
                                    <Checkbox
                                        checked={languageForm.data.is_spoken}
                                        onCheckedChange={(v) => languageForm.setData('is_spoken', v === true)}
                                    />
                                    <div>
                                        <div className="font-medium">Spoken</div>
                                        <div className="mt-0.5 text-[11px] text-muted-foreground">Can converse in this language</div>
                                    </div>
                                </label>
                            </div>
                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                <label className="flex items-center gap-3 text-sm text-foreground">
                                    <Checkbox
                                        checked={languageForm.data.is_written}
                                        onCheckedChange={(v) => languageForm.setData('is_written', v === true)}
                                    />
                                    <div>
                                        <div className="font-medium">Written</div>
                                        <div className="mt-0.5 text-[11px] text-muted-foreground">Can write in this language</div>
                                    </div>
                                </label>
                            </div>
                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                <label className="flex items-center gap-3 text-sm text-foreground">
                                    <Checkbox
                                        checked={languageForm.data.is_understood}
                                        onCheckedChange={(v) => languageForm.setData('is_understood', v === true)}
                                    />
                                    <div>
                                        <div className="font-medium">Understood</div>
                                        <div className="mt-0.5 text-[11px] text-muted-foreground">Can understand this language</div>
                                    </div>
                                </label>
                            </div>
                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                <label className="flex items-center gap-3 text-sm text-foreground">
                                    <Checkbox
                                        checked={languageForm.data.is_mother_tongue}
                                        onCheckedChange={(v) => languageForm.setData('is_mother_tongue', v === true)}
                                    />
                                    <div>
                                        <div className="font-medium">Mother tongue</div>
                                        <div className="mt-0.5 text-[11px] text-muted-foreground">Native language</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setLanguageDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
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
                                        ...LANGUAGES_RELOAD,
                                        onSuccess: () => {
                                            setLanguageDialogOpen(false);
                                            languageForm.reset();
                                            setEditingLanguage(null);
                                        },
                                    });
                                } else {
                                    languageForm.post(url, {
                                        ...LANGUAGES_RELOAD,
                                        onSuccess: () => {
                                            setLanguageDialogOpen(false);
                                            languageForm.reset();
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

            <EmployeeRecordDeleteDialog
                open={!!deleteLanguageId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteLanguageId(null);
                    }
                }}
                title="Remove language?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteLanguageId
                        ? destroyLanguage.url({
                              employee: employeeId,
                              language: deleteLanguageId,
                          })
                        : null
                }
                reloadOptions={LANGUAGES_RELOAD}
            />
        </TabsContent>
    );
}
