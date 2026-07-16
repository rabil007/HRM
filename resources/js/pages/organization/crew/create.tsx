import { Head, router, useForm } from '@inertiajs/react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { CrewAssignmentFormFields } from '@/features/organization/crew/components/crew-assignment-form-fields';
import type {
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import {
    index as crewAssignmentsIndex,
    store as storeAssignment,
} from '@/routes/organization/crew-assignments';

export default function CrewAssignmentCreate({
    form_options,
}: {
    form_options: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const form = useForm<CrewAssignmentFormData>({
        employee_id: null,
        rank_id: null,
        client_id: null,
        vessel_id: null,
        company_visa_type_id: null,
        planned_join_at: '',
        planned_signoff_at: '',
        planned_travel_at: '',
        remarks: '',
    });

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();
        form.post(storeAssignment.url());
    };

    return (
        <>
            <Head title="New Crew Assignment" />
            <Main>
                <DetailsHeader
                    kicker="Current Crew"
                    title="New Assignment"
                    description="Create a draft mobilisation cycle. Movement actions advance the phase later."
                    backHref={crewAssignmentsIndex.url()}
                    backLabel="Back to Current Crew"
                />

                <Card className="border-border/80 dark:border-white/10">
                    <CardContent className="p-6 md:p-8">
                        <form onSubmit={handleSubmit} className="space-y-8">
                            <CrewAssignmentFormFields
                                form={form}
                                formOptions={form_options}
                            />

                            <div className="flex flex-wrap gap-3 border-t border-border/60 pt-6">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="h-11 rounded-xl px-6"
                                >
                                    {form.processing ? (
                                        <Spinner className="mr-2" />
                                    ) : null}
                                    Create Draft Assignment
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11 rounded-xl px-6"
                                    onClick={() =>
                                        router.visit(crewAssignmentsIndex.url())
                                    }
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </Main>
        </>
    );
}
