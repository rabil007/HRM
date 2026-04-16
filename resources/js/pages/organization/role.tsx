import { Head, router, useForm } from '@inertiajs/react';
import { Search, Shield, CheckCircle2, Circle, LayoutGrid, ChevronRight } from 'lucide-react';
import { useMemo, useState, useEffect } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { Company, Role, RoleFormData } from '@/features/organization/roles/types';

function normalizePermissions(value: string[]): string[] {
    return Array.from(
        new Set(
            value
                .map((p) => p.trim())
                .filter(Boolean),
        ),
    ).sort();
}

export default function RoleDetails({
    role,
    company,
    permissions,
}: {
    role: Role & { updated_at?: string };
    company: (Company & { slug?: string }) | null;
    permissions: { id: number; name: string }[];
}) {
    const form = useForm<RoleFormData>({
        name: role.name ?? '',
    });

    const [permissionQuery, setPermissionQuery] = useState('');
    const [permissionView, setPermissionView] = useState<'all' | 'selected' | 'unselected'>('all');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(normalizePermissions(role.permissions ?? []));

    const availablePermissions = useMemo(() => normalizePermissions(permissions.map((p) => p.name)), [permissions]);
    const selectedSet = useMemo(() => new Set(selectedPermissions), [selectedPermissions]);

    const formatPermissionName = (name: string) => {
        const parts = name.split('.');

        if (parts.length <= 1) {
return name;
}
        
        // Remove the group from the name for display if it's the first part
        const rest = parts.slice(1);

        return rest.map(p => p.split('-').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')).join(': ');
    };

    const grouped = useMemo(() => {
        const query = permissionQuery.trim().toLowerCase();
        const list = query ? availablePermissions.filter((p) => p.toLowerCase().includes(query)) : availablePermissions;
        
        // Map<MainGroup, Map<SubGroup, Permission[]>>
        const mainMap = new Map<string, Map<string, string[]>>();

        for (const permission of list) {
            const parts = permission.split('.');
            const mainGroup = (parts[0] || 'other').toUpperCase();
            
            // Sub-group logic: take all parts between the first and the last
            let subGroup = 'GENERAL';

            if (parts.length > 2) {
                subGroup = parts.slice(1, -1)
                    .map(p => p.replace(/-/g, ' ').toUpperCase())
                    .join(' • ');
            } else if (parts.length === 2 && mainGroup === 'SETTINGS') {
                // Handle cases like settings.view
                subGroup = 'CORE';
            }

            if (!mainMap.has(mainGroup)) {
                mainMap.set(mainGroup, new Map());
            }

            const subMap = mainMap.get(mainGroup)!;

            if (!subMap.has(subGroup)) {
                subMap.set(subGroup, []);
            }

            subMap.get(subGroup)!.push(permission);
        }

        // Convert to sorted array structure
        return Array.from(mainMap.entries())
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([mainGroup, subMap]) => {
                const subGroups = Array.from(subMap.entries())
                    .sort(([a], [b]) => a.localeCompare(b))
                    .map(([name, items]) => [name, items.sort()] as const);
                
                return [mainGroup, subGroups] as const;
            });
    }, [availablePermissions, permissionQuery, permissionView, selectedSet]);

    const [activeGroup, setActiveGroup] = useState<string | null>(null);

    const togglePermission = (permission: string, next: boolean) => {
        if (next) {
            setSelectedPermissions((prev) => normalizePermissions([...prev, permission]));

            return;
        }

        setSelectedPermissions((prev) => prev.filter((p) => p !== permission));
    };

    // Set initial active group
    useEffect(() => {
        if (grouped.length > 0 && !activeGroup) {
            setActiveGroup(grouped[0][0]);
        }
    }, [grouped, activeGroup]);

    return (
        <>
            <Head title={`Role • ${role.name}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={role.name}
                    description={company?.name ?? '—'}
                    backHref="/organization/roles"
                    backLabel="Back to roles"
                    actions={
                        <Button
                            className="rounded-xl h-11 px-5"
                            onClick={() => {
                                router.put(
                                    `/organization/roles/${role.id}`,
                                    {
                                        name: form.data.name,
                                        permissions: selectedPermissions,
                                    },
                                    {
                                        preserveScroll: true,
                                    },
                                );
                            }}
                            disabled={form.processing}
                        >
                            Save
                        </Button>
                    }
                />

                <div className="flex flex-col gap-6">
                    {/* Top Action Bar */}
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-4 flex flex-col md:flex-row items-center justify-between gap-6">
                            <div className="w-full md:max-w-md space-y-1.5 font-medium">
                                <Label htmlFor="role-name" className="text-[10px] uppercase tracking-widest text-muted-foreground/60 ml-1">
                                    Role Display Name
                                </Label>
                                <Input
                                    id="role-name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="Enter role name..."
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 text-base font-semibold transition-all px-4"
                                />
                                {form.errors.name ? <div className="text-xs font-medium text-destructive mt-1">{form.errors.name}</div> : null}
                            </div>

                            <div className="flex items-center gap-4 w-full md:w-auto">
                                <div className="relative flex-1 md:w-80 group">
                                    <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 group-focus-within:text-primary transition-colors" />
                                    <Input
                                        value={permissionQuery}
                                        onChange={(e) => setPermissionQuery(e.target.value)}
                                        placeholder="Search permissions..."
                                        className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 pl-11 transition-all"
                                    />
                                </div>
                                <div className="flex items-center gap-1.5 p-1 bg-white/[0.03] border border-white/5 rounded-xl">
                                    {[
                                        { id: 'all', label: 'All', icon: LayoutGrid },
                                        { id: 'selected', label: 'Selected', icon: CheckCircle2 },
                                        { id: 'unselected', label: 'Unselected', icon: Circle },
                                    ].map((view) => (
                                        <Button
                                            key={view.id}
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className={`rounded-lg h-9 px-4 gap-2 text-xs font-bold transition-all ${
                                                permissionView === view.id 
                                                    ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20' 
                                                    : 'text-muted-foreground hover:bg-white/5'
                                            }`}
                                            onClick={() => setPermissionView(view.id as any)}
                                        >
                                            <view.icon className="w-3.5 h-3.5" />
                                            {view.label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Main Workspace */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[600px]">
                        {/* Sidebar Navigator */}
                        <aside className="lg:col-span-3 space-y-4">
                            <Card className="border-white/5 bg-white/5 h-full flex flex-col overflow-hidden">
                                <div className="p-4 border-b border-white/5 bg-white/[0.02] flex items-center justify-between">
                                    <h3 className="text-xs font-bold uppercase tracking-widest text-muted-foreground">Categories</h3>
                                    <Badge variant="outline" className="text-[10px] border-white/5 font-mono opacity-60">
                                        {grouped.length}
                                    </Badge>
                                </div>
                                <ScrollArea className="flex-1">
                                    <div className="p-2 space-y-1">
                                        {grouped.map(([group, subGroups]) => {
                                            const allItems = subGroups.flatMap(([_, items]) => items);
                                            const selectedCount = allItems.filter((p) => selectedSet.has(p)).length;
                                            const isActive = activeGroup === group;
                                            const isComplete = selectedCount === allItems.length && allItems.length > 0;

                                            return (
                                                <button
                                                    key={group}
                                                    onClick={() => setActiveGroup(group)}
                                                    className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl transition-all group ${
                                                        isActive 
                                                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20' 
                                                            : 'text-muted-foreground hover:bg-white/5 hover:text-foreground'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-3 overflow-hidden">
                                                        <Shield className={`w-4 h-4 flex-shrink-0 ${isActive ? 'text-primary-foreground' : 'text-primary'}`} />
                                                        <span className="text-sm font-bold truncate tracking-tight">{group}</span>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {isComplete && <CheckCircle2 className={`w-3.5 h-3.5 ${isActive ? 'text-primary-foreground' : 'text-primary'}`} />}
                                                        <span className={`text-[10px] font-mono ${isActive ? 'opacity-80' : 'opacity-40'}`}>
                                                            {selectedCount}/{allItems.length}
                                                        </span>
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </ScrollArea>
                                <div className="p-3 bg-white/[0.02] border-t border-white/5">
                                    <Button 
                                        variant="ghost" 
                                        className="w-full text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 hover:text-primary h-8"
                                        onClick={() => setSelectedPermissions(availablePermissions)}
                                    >
                                        Enable All Permissions
                                    </Button>
                                </div>
                            </Card>
                        </aside>

                        {/* Content Area */}
                        <main className="lg:col-span-9">
                            <Card className="border-white/5 bg-white/5 h-full flex flex-col overflow-hidden shadow-2xl">
                                {activeGroup ? (
                                    <>
                                        {(() => {
                                            const groupData = grouped.find(([g]) => g === activeGroup);

                                            if (!groupData) {
return null;
}

                                            const [group, subGroups] = groupData;
                                            
                                            const allItems = subGroups.flatMap(([_, items]) => items);
                                            const selectedCount = allItems.filter((p) => selectedSet.has(p)).length;
                                            const allSelected = selectedCount === allItems.length && allItems.length > 0;

                                            return (
                                                <>
                                                    <div className="p-6 border-b border-white/5 bg-white/[0.02] flex items-center justify-between sticky top-0 bg-background/80 backdrop-blur-md z-10">
                                                        <div className="flex items-center gap-4">
                                                            <div className="w-10 h-10 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                                                                <Shield className="w-5 h-5" />
                                                            </div>
                                                            <div>
                                                                <h2 className="text-lg font-bold tracking-tight text-foreground">{group}</h2>
                                                                <p className="text-xs text-muted-foreground font-medium flex items-center gap-1.5">
                                                                    Manage permissions for the {group.toLowerCase()} module
                                                                    <span className="inline-block w-1 h-1 rounded-full bg-muted-foreground/40 mx-1" />
                                                                    {selectedCount} selected
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 text-xs font-bold"
                                                                onClick={() => {
                                                                    if (allSelected) {
                                                                        const remove = new Set(allItems);
                                                                        setSelectedPermissions((prev) => prev.filter((p) => !remove.has(p)));

                                                                        return;
                                                                    }

                                                                    setSelectedPermissions((prev) => normalizePermissions([...prev, ...allItems]));
                                                                }}
                                                            >
                                                                {allSelected ? 'Unselect All' : 'Select All'}
                                                            </Button>
                                                        </div>
                                                    </div>
                                                    <ScrollArea className="flex-1">
                                                        <div className="p-8 space-y-12">
                                                            {subGroups.map(([subName, items]) => {
                                                                const subSelectedCount = items.filter((p) => selectedSet.has(p)).length;
                                                                const subAllSelected = subSelectedCount === items.length && items.length > 0;

                                                                return (
                                                                    <div key={subName} className="space-y-6">
                                                                        <div className="flex items-center justify-between group/sub">
                                                                            <div className="flex items-center gap-3">
                                                                                <div className="h-6 w-1 rounded-full bg-primary" />
                                                                                <div>
                                                                                    <h4 className="text-sm font-bold tracking-widest text-foreground uppercase">{subName}</h4>
                                                                                    <p className="text-[10px] font-medium text-muted-foreground/40 tracking-wider">
                                                                                        {subSelectedCount} of {items.length} Selected
                                                                                    </p>
                                                                                </div>
                                                                            </div>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-8 px-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 hover:text-primary hover:bg-primary/5 rounded-lg opacity-0 group-hover/sub:opacity-100 transition-opacity"
                                                                                onClick={() => {
                                                                                    if (subAllSelected) {
                                                                                        const remove = new Set(items);
                                                                                        setSelectedPermissions((prev) => prev.filter((p) => !remove.has(p)));

                                                                                        return;
                                                                                    }

                                                                                    setSelectedPermissions((prev) => normalizePermissions([...prev, ...items]));
                                                                                }}
                                                                            >
                                                                                {subAllSelected ? 'Unselect' : 'Select'} Group
                                                                            </Button>
                                                                        </div>

                                                                        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                                                            {items.map((permission) => {
                                                                                const checked = selectedSet.has(permission);

                                                                                return (
                                                                                    <label 
                                                                                        key={permission} 
                                                                                        className={`flex items-center gap-4 p-4 rounded-2xl cursor-pointer transition-all border ${
                                                                                            checked 
                                                                                                ? 'bg-primary/[0.08] border-primary/20 ring-1 ring-primary/10 shadow-lg shadow-primary/[0.03]' 
                                                                                                : 'bg-white/[0.02] border-white/5 hover:bg-white/[0.04] hover:border-white/10'
                                                                                        }`}
                                                                                    >
                                                                                        <Checkbox
                                                                                            checked={checked}
                                                                                            onCheckedChange={(value) => togglePermission(permission, Boolean(value))}
                                                                                            className="h-5 w-5 border-white/10 data-[state=checked]:bg-primary data-[state=checked]:border-primary"
                                                                                        />
                                                                                        <div className="flex-1 overflow-hidden">
                                                                                            <p className={`text-sm font-bold truncate tracking-tight ${checked ? 'text-primary' : 'text-foreground/80'}`}>
                                                                                                {formatPermissionName(permission)}
                                                                                            </p>
                                                                                            <p className="text-[10px] font-medium text-muted-foreground/40 mt-0.5 truncate uppercase tracking-tighter">
                                                                                                {permission.split('.').pop()?.replace(/-/g, ' ')} Access
                                                                                            </p>
                                                                                        </div>
                                                                                    </label>
                                                                                );
                                                                            })}
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    </ScrollArea>
                                                </>
                                            );
                                        })()}
                                    </>
                                ) : (
                                    <div className="flex-1 flex flex-col items-center justify-center p-12 text-center">
                                        <div className="w-20 h-20 rounded-3xl bg-white/5 border border-dashed border-white/10 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                                            <Shield className="w-10 h-10 text-muted-foreground/20" />
                                        </div>
                                        <h3 className="text-xl font-bold text-foreground mb-2">Select a Category</h3>
                                        <p className="text-sm text-muted-foreground max-w-sm mx-auto leading-relaxed">
                                            Choose a module from the left categories to manage its specific access permissions for this role.
                                        </p>
                                    </div>
                                )}
                            </Card>
                        </main>
                    </div>
                </div>
            </Main>
        </>
    );
}
