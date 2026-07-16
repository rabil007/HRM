import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';

export default function CrewAssignmentEdit({
    assignment,
    form_options,
    can,
}: {
    assignment: CrewAssignmentDetail;
    form_options: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const form = useForm<CrewAssignmentFormData>({
        employee_id: assignment.employee?.id ?? null,
        rank_id: assignment.rank?.id ?? null,
        client_id: assignment.client?.id ?? null,
        vessel_id: assignment.vessel?.id ?? null,
        company_visa_type_id: assignment.company_visa_type?.id ?? null,
        planned_join_at: assignment.planned_join_at ?? '',
        planned_signoff_at: assignment.planned_signoff_at ?? '',
        planned_travel_at: assignment.planned_travel_at ?? '',
        remarks: assignment.remarks ?? '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/organization/crew/${assignment.id}`);
    };

    return (
        <>
            <Head title={`Edit ${assignment.assignment_no}`} />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(`/organization/crew/${assignment.id}`)}
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Assignment
                    </Button>
                </div>

                <PageHeader
                    title={`Edit ${assignment.assignment_no}`}
                    description="Update crew assignment details"
                />

                <div className="glass-card rounded-xl p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label htmlFor="remarks">Remarks</Label>
                            <Textarea
                                id="remarks"
                                value={form.data.remarks}
                                onChange={(e) => form.setData('remarks', e.target.value)}
                                rows={3}
                            />
                        </div>

                        <div className="flex gap-3">
                            <Button type="submit" disabled={form.processing}>
                                Update Assignment
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.visit(`/organization/crew/${assignment.id}`)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </div>
            </Main>
        </>
    );
}
