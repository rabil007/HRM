import { useForm } from '@inertiajs/react';
import { Filter, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
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
}: {
    users: User[];
}) {
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
        avatar: '',
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
            avatar: '',
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
            avatar: user.avatar ?? '',
            status: user.status ?? 'active',
        });
        setIsSheetOpen(true);
    };

    const handleDelete = (user: User) => {
        setCurrentUser(user);
        setIsDeleteOpen(true);
    };

    const submit = () => {
        if (currentUser) {
            form.put(`/organization/users/${currentUser.id}`, {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post('/organization/users', {
            preserveScroll: true,
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
                        <Button
                            type="button"
                            variant="secondary"
                            className="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
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
                            buttonClassName="rounded-xl h-12 px-5 border border-white/5 bg-white/5 hover:bg-white/10"
                        />
                    </>
                }
            />

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {filteredUsers.map((u) => (
                    <UserCard key={u.id} user={u} onEdit={handleEdit} onDelete={handleDelete} />
                ))}
            </div>

            {filteredUsers.length === 0 ? <EmptyState title="No users found." /> : null}

            <UserFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                user={currentUser}
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

            <UserDeleteDialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen} user={currentUser} />
        </Main>
    );
}

