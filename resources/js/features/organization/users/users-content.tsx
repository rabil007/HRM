import { router, useForm } from '@inertiajs/react';
import { Edit2, Eye, Filter, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ViewToggle } from '@/components/view-toggle';
import { useViewPreference } from '@/hooks/use-view-preference';
import { toast } from '@/lib/toast';
import { UserCard } from './components/user-card';
import { UserDeleteDialog } from './components/user-delete-dialog';
import { UserFiltersSheet } from './components/user-filters-sheet';
import type { UserFilters } from './components/user-filters-sheet';
import { UserFormSheet } from './components/user-form-sheet';
import type { User, UserFormData } from './types';

const emptyFilters: UserFilters = {
    status: '',
};

export function UsersContent({
    users,
    roles,
}: {
    users: User[];
    roles: { id: number; name: string }[];
}) {
    const [view, setView] = useViewPreference('users:view', 'grid');
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [currentUser, setCurrentUser] = useState<User | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filters, setFilters] = useState<UserFilters>(emptyFilters);

    const form = useForm<UserFormData>({
        name: '',
        email: '',
        password: '',
        avatar: null,
        role_id: '',
        status: 'active',
    });

    const handleAdd = () => {
        setCurrentUser(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            email: '',
            password: '',
            avatar: null,
            role_id: '',
            status: 'active',
        });
        setIsSheetOpen(true);
    };

    const handleEdit = (user: User) => {
        setCurrentUser(user);
        form.reset();
        form.clearErrors();
        form.setData({
            name: user.name ?? '',
            email: user.email ?? '',
            password: '',
            avatar: null,
            role_id: user.role?.id ?? '',
            status: user.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (user: User) => {
        setCurrentUser(user);
        setIsDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!currentUser) {
            return;
        }

        router.delete(`/organization/users/${currentUser.id}`, {
            onFinish: () => {
                setIsDeleteOpen(false);
                setCurrentUser(null);
            },
        });
    };

    const toggleStatus = (user: User, enabled: boolean) => {
        router.put(
            `/organization/users/${user.id}/status`,
            { status: enabled ? 'active' : 'inactive' },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`User "${user.name}" is now ${enabled ? 'Active' : 'Inactive'}.`);
                },
                onError: () => {
                    toast.error('Failed to update status. Please try again.');
                },
            },
        );
    };

    const submit = () => {
        if (currentUser) {
            form.put(`/organization/users/${currentUser.id}`, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/users', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const filteredUsers = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return users.filter((u) => {
            if (filters.status && (u.status ?? '') !== filters.status) {
                return false;
            }

            if (!query) {
                return true;
            }

            return (
                u.name.toLowerCase().includes(query) ||
                u.email.toLowerCase().includes(query) ||
                (u.company?.name ?? '').toLowerCase().includes(query) ||
                (u.role?.name ?? '').toLowerCase().includes(query)
            );
        });
    }, [users, filters, searchQuery]);

    const activeFiltersCount = useMemo(() => {
        return [filters.status].filter(Boolean).length;
    }, [filters]);

    const getExportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
        const params = new URLSearchParams();

        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }

        if (filters.status) {
            params.set('status', filters.status);
        }

        params.set('format', format);

        return `/organization/users/export?${params.toString()}`;
    };

    return (
        <Main>
            <PageHeader
                title="Users"
                description="Manage users, roles, and access."
                right={
                    <Button onClick={handleAdd} className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6">
                        <Plus className="mr-2 h-4 w-4" />
                        Add User
                    </Button>
                }
            />

            <SearchBar
                placeholder="Search users by name, email, company, or role..."
                value={searchQuery}
                onChange={setSearchQuery}
                right={
                    <>
                        <ViewToggle value={view} onChange={setView} />
                        <Button
                            type="button"
                            variant="secondary"
                            className="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                            onClick={() => setIsFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                            {activeFiltersCount ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {activeFiltersCount}
                                </span>
                            ) : null}
                        </Button>

                        <ExportMenu
                            getUrl={getExportUrl}
                            buttonVariant="secondary"
                            buttonClassName="glass-card rounded-xl h-12 px-5 hover:bg-accent"
                        />
                    </>
                }
            />

            {view === 'grid' ? (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {filteredUsers.map((u) => (
                        <UserCard key={u.id} user={u} onEdit={handleEdit} onDelete={handleDelete} onToggleStatus={toggleStatus} />
                    ))}
                </div>
            ) : (
                <Card className="glass-card w-full overflow-hidden">
                    <CardContent className="w-full p-0 min-h-[360px]">
                        <Table className="min-w-[980px]">
                            <TableHeader>
                                <TableRow className="border-border/60">
                                    <TableHead className="pl-4">User</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right pr-4">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredUsers.map((u) => (
                                    <TableRow
                                        key={u.id}
                                        className="border-border/40 cursor-pointer hover:bg-accent/40"
                                        onClick={() => router.visit(`/organization/users/${u.id}`)}
                                    >
                                        <TableCell className="pl-4 font-semibold">{u.name}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{u.email}</TableCell>
                                        <TableCell className="text-muted-foreground/80">{u.role?.name ?? '—'}</TableCell>
                                        <TableCell className="text-muted-foreground/80">
                                            <div className="flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
                                                <Switch checked={u.status === 'active'} onCheckedChange={(checked) => toggleStatus(u, checked)} />
                                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                    {u.status ?? '—'}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="pr-4">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        router.visit(`/organization/users/${u.id}`);
                                                    }}
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-9 w-9 rounded-xl hover:bg-accent"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleEdit(u);
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
                                                        handleDelete(u);
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

            {filteredUsers.length === 0 ? <EmptyState title="No users found." /> : null}

            <UserFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                user={currentUser}
                roles={roles}
                form={form}
                onSubmit={submit}
            />

            <UserFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                value={filters}
                onChange={setFilters}
                onReset={() => setFilters(emptyFilters)}
            />

            <UserDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} user={currentUser} onConfirm={confirmDelete} />
        </Main>
    );
}

