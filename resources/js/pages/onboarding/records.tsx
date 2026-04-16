import { Head, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';

type RecordRow = {
    id: number;
    employee: { id: number; employee_no: string; name: string } | null;
    template: { id: number; name: string } | null;
    status: 'pending' | 'in_progress' | 'completed';
    stage: string;
    start_date: string | null;
    completed_at: string | null;
    created_at: string;
};

type Pagination<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: unknown;
};

export default function OnboardingRecords({ records }: { records: Pagination<RecordRow> }) {
    const [query, setQuery] = useState('');

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return records.data;
        }

        return records.data.filter((r) => {
            const employee = r.employee ? `${r.employee.employee_no} ${r.employee.name}` : '';
            const template = r.template?.name ?? '';

            return employee.toLowerCase().includes(q) || template.toLowerCase().includes(q) || r.stage.toLowerCase().includes(q);
        });
    }, [query, records.data]);

    const advance = (id: number) => {
        router.post(`/onboarding/records/${id}/advance`, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Onboarding records" />

            <Main>
                <PageHeader
                    kicker="Onboarding"
                    title="Onboarding records"
                    description="Track onboarding stage progress per employee."
                    right={
                        <Button variant="outline" onClick={() => router.reload({ preserveScroll: true })}>
                            Refresh
                        </Button>
                    }
                />

                <SearchBar value={query} onChange={setQuery} placeholder="Search by employee, template, stage..." />

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[980px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-4">Employee</div>
                                <div className="col-span-3">Template</div>
                                <div className="col-span-2">Stage</div>
                                <div className="col-span-1">Status</div>
                                <div className="col-span-2 text-right">Actions</div>
                            </div>

                            {rows.map((r) => (
                                <div key={r.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-4">
                                        <div className="text-sm font-medium truncate">{r.employee ? r.employee.name : '—'}</div>
                                        <div className="text-xs text-muted-foreground/70 truncate">
                                            {r.employee ? r.employee.employee_no : '—'}
                                        </div>
                                    </div>
                                    <div className="col-span-3 text-sm text-muted-foreground truncate">{r.template?.name ?? '—'}</div>
                                    <div className="col-span-2 text-sm font-mono">{r.stage}</div>
                                    <div className="col-span-1 text-sm text-muted-foreground">{r.status}</div>
                                    <div className="col-span-2 flex justify-end gap-2 flex-nowrap">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                advance(r.id);
                                            }}
                                        >
                                            Next
                                            <ArrowRight className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No onboarding records found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </Main>
        </>
    );
}

