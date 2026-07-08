import { FolderTree } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { DepartmentTreeNode } from '../types';
import { DepartmentEmployeeTree } from './department-employee-tree';

type DepartmentFilterControlsProps = {
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    department_tree_selected_position_id: number | null;
    selectionCount?: number;
    onSelectDepartment: (id: number | null) => void;
    onSelectPosition: (positionId: number, departmentId: number) => void;
    buttonClassName?: string;
};

export function DepartmentFilterControls({
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    selectionCount,
    onSelectDepartment,
    onSelectPosition,
    buttonClassName,
}: DepartmentFilterControlsProps) {
    const [isDepartmentsOpen, setIsDepartmentsOpen] = useState(false);
    const [isDepartmentsPopoverOpen, setIsDepartmentsPopoverOpen] =
        useState(false);

    const activeSelectionCount =
        selectionCount ??
        (department_tree_selected_id || department_tree_selected_position_id
            ? 1
            : 0);

    const handleDepartmentSelect = (id: number | null) => {
        onSelectDepartment(id);
        setIsDepartmentsOpen(false);
        setIsDepartmentsPopoverOpen(false);
    };

    const handlePositionSelect = (
        positionId: number,
        departmentId: number,
    ) => {
        onSelectPosition(positionId, departmentId);
        setIsDepartmentsOpen(false);
        setIsDepartmentsPopoverOpen(false);
    };

    const desktopButtonClass = cn(
        'hidden h-12 rounded-xl glass-card px-5 hover:bg-accent lg:flex',
        buttonClassName,
    );
    const mobileButtonClass = cn(
        'h-12 rounded-xl glass-card px-5 hover:bg-accent lg:hidden',
        buttonClassName,
    );

    return (
        <div className="flex shrink-0 items-center gap-3">
            <Popover
                open={isDepartmentsPopoverOpen}
                onOpenChange={setIsDepartmentsPopoverOpen}
            >
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="secondary"
                        className={desktopButtonClass}
                    >
                        <FolderTree className="mr-2 h-4 w-4" />
                        Departments
                        {activeSelectionCount ? (
                            <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                {activeSelectionCount}
                            </span>
                        ) : null}
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="w-72 border-border p-3 dark:border-white/6"
                >
                    <DepartmentEmployeeTree
                        nodes={department_tree}
                        selectedDepartmentId={department_tree_selected_id}
                        selectedPositionId={
                            department_tree_selected_position_id
                        }
                        onSelectDepartment={handleDepartmentSelect}
                        onSelectPosition={handlePositionSelect}
                    />
                </PopoverContent>
            </Popover>
            <Button
                type="button"
                variant="secondary"
                className={mobileButtonClass}
                onClick={() => setIsDepartmentsOpen(true)}
            >
                <FolderTree className="mr-2 h-4 w-4" />
                Departments
                {activeSelectionCount ? (
                    <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                        {activeSelectionCount}
                    </span>
                ) : null}
            </Button>
            <Sheet open={isDepartmentsOpen} onOpenChange={setIsDepartmentsOpen}>
                <SheetContent side="bottom" className="h-[80vh]">
                    <SheetHeader>
                        <SheetTitle>Departments</SheetTitle>
                    </SheetHeader>
                    <div className="mt-4 overflow-y-auto">
                        <DepartmentEmployeeTree
                            nodes={department_tree}
                            selectedDepartmentId={department_tree_selected_id}
                            selectedPositionId={
                                department_tree_selected_position_id
                            }
                            onSelectDepartment={handleDepartmentSelect}
                            onSelectPosition={handlePositionSelect}
                        />
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    );
}
