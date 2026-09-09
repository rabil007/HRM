import { Download, Loader2, MessageCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmployeeFolderItem } from '@/features/organization/documents/employee-folder-item';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import type { EmployeeFolder } from '@/features/organization/documents/shared/types';
import { cn } from '@/lib/utils';

export function DocumentsIndexFolderGrid({
    employees,
    canDownload,
    canShare = false,
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
    onBulkShare,
    isBulkDownloading,
}: {
    employees: EmployeeFolder[];
    canDownload: boolean;
    canShare?: boolean;
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
    onBulkShare?: () => void;
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
                        <>
                            {canDownload ? (
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
                            ) : null}
                            {canShare ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg"
                                    disabled={selectedFolderCount === 0}
                                    onClick={onBulkShare}
                                >
                                    <MessageCircle className="mr-2 h-4 w-4" />
                                    Share links
                                </Button>
                            ) : null}
                        </>
                    }
                />
            ) : null}

            <section
                className={cn(
                    'min-w-0',
                    'transition-opacity duration-200',
                    isSearching && 'pointer-events-none opacity-60',
                )}
                aria-busy={isSearching}
            >
                <div className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    {employees.map((employee) => (
                        <EmployeeFolderItem
                            key={employee.employee_id}
                            employee={employee}
                            canDownload={canDownload}
                            selectionMode={selectionMode}
                            selected={isFolderSelected(employee.employee_id)}
                            onSelectedChange={() =>
                                onToggleFolder(employee.employee_id)
                            }
                        />
                    ))}
                </div>
            </section>
        </div>
    );
}
