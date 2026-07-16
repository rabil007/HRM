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

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(storeAssignment.url());
    };

    return (
        <>
            <Head title="New Crew Assignment" />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(crewAssignmentsIndex.url())}
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Crew Assignments
                    </Button>
                </div>

                <PageHeader
                    title="New Crew Assignment"
                    description="Create a new draft crew assignment"
                />

                <div className="glass-card rounded-xl p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label htmlFor="employee_id">Employee *</Label>
                            <Select
                                value={form.data.employee_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData('employee_id', value ? Number(value) : null)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select employee..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {form_options.employees.map((emp) => (
                                        <SelectItem key={emp.id} value={emp.id.toString()}>
                                            {emp.name}
                                            {emp.employee_no && ` (${emp.employee_no})`}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.employee_id && (
                                <p className="mt-1 text-sm text-destructive">
                                    {form.errors.employee_id}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-6 md:grid-cols-2">
                            <div>
                                <Label htmlFor="rank_id">Rank</Label>
                                <Select
                                    value={form.data.rank_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData('rank_id', value ? Number(value) : null)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select rank..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.ranks.map((rank) => (
                                            <SelectItem key={rank.id} value={rank.id.toString()}>
                                                {rank.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="vessel_id">Vessel</Label>
                                <Select
                                    value={form.data.vessel_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData('vessel_id', value ? Number(value) : null)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select vessel..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.vessels.map((vessel) => (
                                            <SelectItem key={vessel.id} value={vessel.id.toString()}>
                                                {vessel.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="client_id">Client</Label>
                                <Select
                                    value={form.data.client_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData('client_id', value ? Number(value) : null)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select client..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.clients.map((client) => (
                                            <SelectItem key={client.id} value={client.id.toString()}>
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="company_visa_type_id">Visa Type</Label>
                                <Select
                                    value={form.data.company_visa_type_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'company_visa_type_id',
                                            value ? Number(value) : null,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select visa type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {form_options.visa_types.map((visa) => (
                                            <SelectItem key={visa.id} value={visa.id.toString()}>
                                                {visa.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <div>
                                <Label htmlFor="planned_join_at">Planned Join</Label>
                                <input
                                    id="planned_join_at"
                                    type="date"
                                    className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_join_at}
                                    onChange={(e) =>
                                        form.setData('planned_join_at', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="planned_signoff_at">Planned Sign-Off</Label>
                                <input
                                    id="planned_signoff_at"
                                    type="date"
                                    className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_signoff_at}
                                    onChange={(e) =>
                                        form.setData('planned_signoff_at', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="planned_travel_at">Planned Travel</Label>
                                <input
                                    id="planned_travel_at"
                                    type="date"
                                    className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                    value={form.data.planned_travel_at}
                                    onChange={(e) =>
                                        form.setData('planned_travel_at', e.target.value)
                                    }
                                />
                            </div>
                        </div>

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
                                Create Assignment
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.visit(crewAssignmentsIndex.url())}
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
