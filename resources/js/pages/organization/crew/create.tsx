import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

export default function CrewAssignmentCreate({
    form_options,
    can,
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
        form.post('/organization/crew');
    };

    return (
        <>
            <Head title="New Crew Assignment" />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit('/organization/crew')}
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
                                            {emp.employee_number && ` (${emp.employee_number})`}
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
                                onClick={() => router.visit('/organization/crew')}
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
