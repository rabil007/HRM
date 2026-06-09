import { Download, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import type { EmployeeFolder } from '@/features/organization/documents/shared/types';
import { cn } from '@/lib/utils';

export function DocumentsIndexFolderGrid({
    employees,
    canDownload,
    isSearching,
    selectionMode = true,
    selectedFolderCount,
    isFolderSelected,
    allFoldersSelected,
    foldersPartiallySelected,
    onToggleFolder,
    onToggleAllFolders,
    onClearFolderSelection,
    onBulkDownload,
    isBulkDownloading,
}: {
    employees: EmployeeFolder[];
    canDownload: boolean;
    isSearching?: boolean;
    selectionMode?: boolean;
    selectedFolderCount: number;
    isFolderSelected: (id: number) => boolean;
    allFoldersSelected: boolean;
    foldersPartiallySelected: boolean;
    onToggleFolder: (id: number) => void;
    onToggleAllFolders: () => void;
    onClearFolderSelection: () => void;
    onBulkDownload: () => void;
    isBulkDownloading: boolean;
}) {
    return (
        <div className="space-y-4">
            {selectionMode ? (
                <DocumentsBulkToolbar
                    count={selectedFolderCount}
                    itemLabel="folders"
                    onClear={onClearFolderSelection}
                    selectAll={
                        <Checkbox
                            checked={
                                allFoldersSelected
                                    ? true
                                    : foldersPartiallySelected
                                      ? 'indeterminate'
                                      : false
                            }
                            onCheckedChange={onToggleAllFolders}
                            aria-label="Select all folders"
                        />
                    }
                    actions={
                        canDownload ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-lg"
                                disabled={isBulkDownloading}
                                onClick={onBulkDownload}
                            >
                                {isBulkDownloading ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <Download className="mr-2 h-4 w-4" />
                                )}
                                Download ZIP
                            </Button>
                        ) : null
                    }
                />
            ) : null}

            <section
                className={cn(
                    'rounded-xl border border-border bg-muted/20 p-4 sm:p-6 dark:border-white/5 dark:bg-white/[0.02]',
                    'transition-opacity duration-200',
                    isSearching && 'pointer-events-none opacity-60',
                )}
                aria-busy={isSearching}
            >
                <div className="grid grid-cols-[repeat(auto-fill,minmax(8rem,1fr))] gap-4 sm:grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] sm:gap-5">
                    {employees.map((employee) => (
                        <EmployeeFolderItem
                            key={employee.employee_id}
                            employee={employee}
                            canDownload={canDownload}
                            selectionMode={selectionMode}
                            selected={isFolderSelected(employee.employee_id)}
                            onSelectedChange={() => onToggleFolder(employee.employee_id)}
                        />
                    ))}
                </div>
            </section>
        </div>
    );
}
