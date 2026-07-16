import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type {
    CrewAssignmentDetail,
    CrewAssignmentFormData,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import {
    show as showAssignment,
    update as updateAssignment,
} from '@/routes/organization/crew-assignments';

export default function CrewAssignmentEdit({
    assignment,
    form_options,
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
        form.put(updateAssignment.url(assignment.id));
    };

    return (
        <>
            <Head title={`Edit ${assignment.assignment_no}`} />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.visit(showAssignment.url(assignment.id))
                        }
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Assignment
                    </Button>
                </div>

                <PageHeader
                    title={`Edit ${assignment.assignment_no}`}
                    description="Update planning fields only. Status and phase dates are managed by movement actions."
                />

                <div className="rounded-xl glass-card p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label>Employee</Label>
                            <p className="mt-1 text-sm">
                                {assignment.employee?.name ?? '—'}
                            </p>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <Label htmlFor="rank_id">Rank</Label>
                                <Select
                                    value={
                                        form.data.rank_id
                                            ? String(form.data.rank_id)
                                            : undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'rank_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                >
                                    <SelectTrigger id="rank_id">
                                        <SelectValue placeholder="Select rank" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.ranks.map((rank) => (
                                            <SelectItem
                                                key={rank.id}
                                                value={String(rank.id)}
                                            >
                                                {rank.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="vessel_id">Vessel</Label>
                                <Select
                                    value={
                                        form.data.vessel_id
                                            ? String(form.data.vessel_id)
                                            : undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'vessel_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                >
                                    <SelectTrigger id="vessel_id">
                                        <SelectValue placeholder="Select vessel" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.vessels.map((vessel) => (
                                            <SelectItem
                                                key={vessel.id}
                                                value={String(vessel.id)}
                                            >
                                                {vessel.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="client_id">Client</Label>
                                <Select
                                    value={
                                        form.data.client_id
                                            ? String(form.data.client_id)
                                            : undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'client_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                >
                                    <SelectTrigger id="client_id">
                                        <SelectValue placeholder="Select client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="company_visa_type_id">
                                    Visa Type
                                </Label>
                                <Select
                                    value={
                                        form.data.company_visa_type_id
                                            ? String(
                                                  form.data
                                                      .company_visa_type_id,
                                              )
                                            : undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'company_visa_type_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                >
                                    <SelectTrigger id="company_visa_type_id">
                                        <SelectValue placeholder="Select visa type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.visa_types.map((visa) => (
                                            <SelectItem
                                                key={visa.id}
                                                value={String(visa.id)}
                                            >
                                                {visa.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <div>
                                <Label htmlFor="planned_join_at">
                                    Planned Join
                                </Label>
                                <input
                                    id="planned_join_at"
                                    type="date"
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_join_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'planned_join_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="planned_signoff_at">
                                    Planned Sign-Off
                                </Label>
                                <input
                                    id="planned_signoff_at"
                                    type="date"
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_signoff_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'planned_signoff_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="planned_travel_at">
                                    Planned Travel
                                </Label>
                                <input
                                    id="planned_travel_at"
                                    type="date"
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_travel_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'planned_travel_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="remarks">Remarks</Label>
                            <Textarea
                                id="remarks"
                                value={form.data.remarks}
                                onChange={(e) =>
                                    form.setData('remarks', e.target.value)
                                }
                                rows={3}
                            />
                        </div>

                        <div className="flex gap-3">
                            <Button type="submit" disabled={form.processing}>
                                Save Changes
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.visit(
                                        showAssignment.url(assignment.id),
                                    )
                                }
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
