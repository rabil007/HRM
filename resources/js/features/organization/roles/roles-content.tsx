import { router, useForm } from '@inertiajs/react';
import { Edit2, Eye, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useViewPreference } from '@/hooks/use-view-preference';
import { RoleCard } from './components/role-card';
import { RoleDeleteDialog } from './components/role-delete-dialog';
import { RoleFormSheet } from './components/role-form-sheet';
import type { Company, Role, RoleFormData } from './types';

export function RolesContent({
    roles,
    company,
}: {
    roles: Role[];
    company: Company | null;
}) {
    const [view, setView] = useViewPreference('roles:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [currentRole, setCurrentRole] = useState<Role | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const form = useForm<RoleFormData>({
        name: '',
    });

    const handleAdd = () => {
        setCurrentRole(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (role: Role) => {
        setCurrentRole(role);
        form.reset();
        form.clearErrors();
        form.setData({
            name: role.name ?? '',
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (role: Role) => {
        setCurrentRole(role);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentRole) {
            return;
        }

        router.delete(`/organization/roles/${currentRole.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentRole(null);
            },
        });
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
                        <ViewToggle value={view} onChange={setView} />
                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
                        />
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {filteredRoles.map((role) => (
                        <RoleCard key={role.id} role={role} onEdit={handleEdit} onDelete={handleDelete} />
                    ))}
                </div>
            ) : (
                <Card className="w-full border-white/5 bg-white/5 backdrop-blur-xl overflow-hidden">
                    <CardContent className="w-full p-0 min-h-[360px]">
                        <Table className="min-w-[980px]">
                            <TableHeader>
                                <TableRow className="border-white/10">
                                    <TableHead className="pl-4">Role</TableHead>
                                    <TableHead>Permissions</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredRoles.map((role) => (
                                    <TableRow
                                        key={role.id}
                                        className="border-white/5 cursor-pointer hover:bg-white/5"
                                        onClick={() => router.visit(`/organization/roles/${role.id}`)}
                                    >
                                        <TableCell className="pl-4 font-semibold">{role.name}</TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            {role.permissions.length ? role.permissions.slice(0, 4).join(', ') : '—'}
                                            {role.permissions.length > 4 ? ` (+${role.permissions.length - 4} more)` : ''}
                                        </TableCell>
                                        <TableCell className="pr-4">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-white/10"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        router.visit(`/organization/roles/${role.id}`);
                                                    }}
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-white/10"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleEdit(role);
                                                    }}
                                                    title="Edit"
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-destructive/10 text-destructive hover:text-destructive"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleDelete(role);
                                                    }}
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}

            {filteredRoles.length === 0 ? <EmptyState title="No roles found." /> : null}

            <RoleFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                role={currentRole}
                form={form}
                onSubmit={submit}
            />

            <RoleDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} role={currentRole} onConfirm={confirmDelete} />
        </Main>
    );
}

