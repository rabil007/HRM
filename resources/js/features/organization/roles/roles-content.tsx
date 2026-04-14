import { useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { RoleCard } from './components/role-card';
import { RoleDeleteDialog } from './components/role-delete-dialog';
import { RoleFormSheet } from './components/role-form-sheet';
import type { Company, Role, RoleFormData } from './types';

export function RolesContent({
    roles,
    company,
    permissions,
}: {
    roles: Role[];
    company: Company | null;
    permissions: { id: number; name: string }[];
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentRole, setCurrentRole] = useState<Role | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const form = useForm<RoleFormData>({
        name: '',
        permissions: [],
    });

    const handleAdd = () => {
        setCurrentRole(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            permissions: [],
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (role: Role) => {
        setCurrentRole(role);
        form.reset();
        form.clearErrors();
        form.setData({
            name: role.name ?? '',
            permissions: role.permissions ?? [],
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (role: Role) => {
        setCurrentRole(role);
        setIsDeleteOpen(true);
    };

    const submit = () => {
        if (currentRole) {
            form.put(`/organization/roles/${currentRole.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/roles', {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const filteredRoles = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return roles.filter((r) => {
            if (!query) {
                return true;
            }

            return (
                r.name.toLowerCase().includes(query) ||
                (r.permissions ?? []).some((p) => p.toLowerCase().includes(query))
            );
        });
    }, [roles, searchQuery]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        params.set('format', format);

        return `/organization/roles/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Roles & Permissions"
                description={company?.name ? `Manage roles for ${company.name}.` : 'Manage roles and permissions.'}
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add Role
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search roles by name, slug, company, or permission..."
                value={searchQuery}
                onChange={setSearchQuery}
                right={
                    <>
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
                        />
                    </>
                }
            />

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredRoles.map((role) => (
                    <RoleCard key={role.id} role={role} onEdit={handleEdit} onDelete={handleDelete} />
                ))}
            </div>

            {filteredRoles.length === 0 ? <EmptyState title="No roles found." /> : null}

            <RoleFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                role={currentRole}
                permissions={permissions}
                form={form}
                onSubmit={submit}
            />

            <RoleDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} role={currentRole} />
        </Main>
    );
}

