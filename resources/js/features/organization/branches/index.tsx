import { router, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { BranchCard } from './components/branch-card';
import { BranchDeleteDialog } from './components/branch-delete-dialog';
import { BranchFormSheet } from './components/branch-form-sheet';
import type { Branch, BranchFormData, Company, Country } from './types';

export function BranchesContent({
    branches,
    companies,
    countries,
}: {
    branches: Branch[];
    companies: Company[];
    countries: Country[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [currentBranch, setCurrentBranch] = useState<Branch | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const form = useForm<BranchFormData>({
        company_id: '',
        name: '',
        code: '',
        address: '',
        city: '',
        country: '',
        phone: '',
        email: '',
        is_headquarters: false,
        status: 'active',
    });

    const handleAdd = () => {
        setCurrentBranch(null);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: companies[0]?.id ?? '',
            name: '',
            code: '',
            address: '',
            city: '',
            country: countries.find((c) => c.code === 'UAE')?.code ?? countries[0]?.code ?? '',
            phone: '',
            email: '',
            is_headquarters: false,
            status: 'active',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (branch: Branch) => {
        setCurrentBranch(branch);
        form.reset();
        form.clearErrors();
        form.setData({
            company_id: branch.company.id ?? '',
            name: branch.name ?? '',
            code: branch.code ?? '',
            address: branch.address ?? '',
            city: branch.city ?? '',
            country: branch.country ?? '',
            phone: branch.phone ?? '',
            email: branch.email ?? '',
            is_headquarters: branch.is_headquarters ?? false,
            status: branch.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDeleteClick = (branch: Branch) => {
        setCurrentBranch(branch);
        setIsDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!currentBranch) {
            return;
        }

        router.delete(`/organization/branches/${currentBranch.id}`, {
            onFinish: () => {
                setIsDeleteDialogOpen(false);
                setCurrentBranch(null);
            },
        });
    };

    const filteredBranches = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        if (!query) {
            return branches;
        }

        return branches.filter((b) => {
            return (
                b.name.toLowerCase().includes(query) ||
                (b.code ?? '').toLowerCase().includes(query) ||
                (b.company.name ?? '').toLowerCase().includes(query) ||
                (`${b.city ?? ''} ${b.country ?? ''}`.trim().toLowerCase().includes(query)) ||
                (b.email ?? '').toLowerCase().includes(query)
            );
        });
    }, [branches, searchQuery]);

    const submit = () => {
        if (currentBranch) {
            form.put(`/organization/branches/${currentBranch.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/branches', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2 mb-1">
                        <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                            Organization Management
                        </span>
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                        Branches
                    </h1>
                    <p className="text-sm text-muted-foreground/80 font-medium">
                        Manage branches across your companies.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Branch
                    </Button>
                </div>
            </div>

            <div className="flex items-center gap-4 mb-8">
                <div className="relative flex-1 group">
                    <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground transition-colors group-focus-within:text-foreground" />
                    <Input
                        placeholder="Search branches by name, code, company, or location..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="pl-10 rounded-xl border-white/5 bg-white/5 focus-visible:ring-primary/20 focus-visible:bg-white/10 transition-all py-6 text-base"
                    />
                </div>
            </div>

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredBranches.map((branch) => (
                    <BranchCard
                        key={branch.id}
                        branch={branch}
                        onEdit={handleEdit}
                        onDelete={handleDeleteClick}
                    />
                ))}
            </div>

            {filteredBranches.length === 0 ? (
                <div className="rounded-xl border border-white/5 bg-white/5 backdrop-blur-xl p-10 text-sm text-muted-foreground/80 text-center">
                    No branches found.
                </div>
            ) : null}

            <BranchFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                branch={currentBranch}
                companies={companies}
                countries={countries}
                form={form}
                onSubmit={submit}
            />

            <BranchDeleteDialog
                open={isDeleteDialogOpen}
                onOpenChange={setIsDeleteDialogOpen}
                branch={currentBranch}
                onConfirm={confirmDelete}
            />
        </Main>
    );
}

