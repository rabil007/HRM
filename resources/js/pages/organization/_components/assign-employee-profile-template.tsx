import { router } from '@inertiajs/react';
import { ClipboardList, Loader2 } from 'lucide-react';
import { useState, type ReactElement } from 'react';
import { assignProfileTemplate } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import type {
    ProfileTemplateOption,
} from '@/pages/organization/employee-page.types';

type AssignEmployeeProfileTemplateProps = {
    employeeId: number;
    profileTemplates: ProfileTemplateOption[];
};

export function AssignEmployeeProfileTemplate({
    employeeId,
    profileTemplates,
}: AssignEmployeeProfileTemplateProps): ReactElement {
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [isAssigning, setIsAssigning] = useState(false);

    const handleAssign = () => {
        if (!selectedTemplateId) {
            return;
        }

        setIsAssigning(true);

        router.put(
            assignProfileTemplate.url({ employee: employeeId }),
            {
                employee_profile_template_id: Number(selectedTemplateId),
            },
            {
                preserveScroll: true,
                onFinish: () => setIsAssigning(false),
            },
        );
    };

    if (profileTemplates.length === 0) {
        return (
            <p className="max-w-[14rem] text-center text-[10px] text-amber-300/90 md:text-right">
                No profile templates available. Create one under Organization → Employee
                templates.
            </p>
        );
    }

    return (
        <div className="flex w-full max-w-[18rem] flex-col gap-2 md:items-end">
            <p className="text-center text-[10px] font-medium uppercase tracking-wide text-amber-300/90 md:text-right">
                No profile template
            </p>
            <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center">
                <AppSelect
                    value={selectedTemplateId}
                    onValueChange={setSelectedTemplateId}
                    placeholder="Select template"
                    variant="dark"
                    className="min-w-0 flex-1"
                >
                    {profileTemplates.map((template) => (
                        <AppSelectItem key={template.id} value={String(template.id)}>
                            {template.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                <Button
                    type="button"
                    size="sm"
                    className="h-9 shrink-0 gap-1.5 text-xs"
                    disabled={!selectedTemplateId || isAssigning}
                    onClick={handleAssign}
                >
                    {isAssigning ? (
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    ) : (
                        <ClipboardList className="h-3.5 w-3.5" />
                    )}
                    Assign
                </Button>
            </div>
        </div>
    );
}
