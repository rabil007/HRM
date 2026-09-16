import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { assignProfileTemplate } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { ProfileTemplateOption } from '@/pages/organization/employee-page.types';

type ChangeEmployeeProfileTemplateDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    currentTemplateName: string;
    currentTemplateId: number;
    profileTemplates: ProfileTemplateOption[];
};

export function ChangeEmployeeProfileTemplateDialog({
    open,
    onOpenChange,
    employeeId,
    currentTemplateName,
    currentTemplateId,
    profileTemplates,
}: ChangeEmployeeProfileTemplateDialogProps): ReactElement {
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [isChanging, setIsChanging] = useState(false);

    const handleOpenChange = (nextOpen: boolean): void => {
        if (isChanging) {
            return;
        }

        if (nextOpen) {
            setSelectedTemplateId('');
        }

        onOpenChange(nextOpen);
    };

    const handleChange = (): void => {
        if (!selectedTemplateId) {
            return;
        }

        setIsChanging(true);

        router.put(
            assignProfileTemplate.url({ employee: employeeId }),
            {
                employee_profile_template_id: Number(selectedTemplateId),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsChanging(false);
                },
                onSuccess: () => {
                    onOpenChange(false);
                },
            },
        );
    };

    const availableTemplates = profileTemplates.filter(
        (template) => template.id !== currentTemplateId,
    );

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Change profile template</DialogTitle>
                    <DialogDescription>
                        This changes which employee fields and profile sections
                        are shown. Existing employee data will be preserved.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Label className="text-xs text-muted-foreground">
                            Current
                        </Label>
                        <p className="text-sm font-medium">
                            {currentTemplateName}
                        </p>
                    </div>

                    {availableTemplates.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No other active profile templates are available.
                            Create one under Organization → Employee templates.
                        </p>
                    ) : (
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">
                                New template
                            </Label>
                            <AppSelect
                                value={selectedTemplateId}
                                onValueChange={setSelectedTemplateId}
                                placeholder="Select template"
                            >
                                {availableTemplates.map((template) => (
                                    <AppSelectItem
                                        key={template.id}
                                        value={String(template.id)}
                                    >
                                        {template.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={isChanging}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            !selectedTemplateId ||
                            isChanging ||
                            availableTemplates.length === 0
                        }
                        onClick={handleChange}
                    >
                        {isChanging ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : null}
                        Change template
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
