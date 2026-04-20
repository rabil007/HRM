import { Head } from '@inertiajs/react';
import {
    Award,
    Briefcase,
    Building2,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    GraduationCap,
    Languages,
    Link as LinkIcon,
    Mail,
    MapPin,
    MoreHorizontal,
    Phone,
    Plus,
    Receipt,
    Settings,
    Target,
    User2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentOption,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
} from '@/features/organization/employees/types';

type EmployeeDetails = {
    id: number;
    user: { id: number; name: string | null; email: string | null } | null;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    manager: {
        id: number;
        employee_no: string | null;
        name: string | null;
    } | null;
    employee_no: string;
    first_name: string;
    last_name: string;
    personal_email?: string | null;
    phone_home_country?: string | null;
    nearest_airport?: string | null;
    cv_source?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    emergency_contact_home_country?: string | null;
    emergency_phone_home_country?: string | null;
    date_of_birth?: string | null;
    place_of_birth?: string | null;
    gender_id?: number | null;
    religion_id?: number | null;
    nationality_id?: number | null;
    nationality_ref?: {
        id: number;
        name: string | null;
        code?: string | null;
    } | null;
    marital_status?: 'single' | 'married' | 'divorced' | 'widowed' | null;
    spouse_name?: string | null;
    spouse_birthdate?: string | null;
    dependent_children_count?: number | null;
    labor_contract_id?: string | null;
    passport_number?: string | null;
    emirates_id?: string | null;
    labor_card_number?: string | null;
    bank_id?: number | null;
    iban?: string | null;
    basic_salary?: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
    work_email: string | null;
    phone: string | null;
    start_date?: string | null;
    probation_end_date?: string | null;
    end_date?: string | null;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    address?: string | null;
    image?: string | null;
    created_at: string;
    updated_at: string;
};

type ActivityItem = {
    id: number;
    event: string | null;
    description: string;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
};

const HIDDEN_ACTIVITY_KEYS = new Set([
    'id',
    'company_id',
    'created_at',
    'updated_at',
    'deleted_at',
    'remember_token',
    'password',
]);

function formatActivityDate(value: string): string {
    const dt = new Date(value);

    if (Number.isNaN(dt.getTime())) {
        return value;
    }

    return dt.toLocaleString();
}

function titleCaseKey(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'string') {
        return value;
    }

    try {
        return JSON.stringify(value);
    } catch {
        return String(value);
    }
}

function changedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k))
        .sort((a, b) => a.localeCompare(b));
}

function statusBadgeClass(status: EmployeeDetails['status']): string {
    if (status === 'active') {
        return 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/20';
    }

    if (status === 'inactive') {
        return 'bg-zinc-500/10 text-zinc-200 border border-zinc-500/20';
    }

    if (status === 'on_leave') {
        return 'bg-amber-500/10 text-amber-200 border border-amber-500/20';
    }

    return 'bg-rose-500/10 text-rose-200 border border-rose-500/20';
}

export default function EmployeeDetails({
    employee,
    branches,
    departments,
    positions,
    managers,
    users,
    countries,
    religions,
    genders,
    banks,
    recent_activity,
}: {
    employee: EmployeeDetails;
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
    recent_activity: ActivityItem[];
}) {
    // Avoid unused variable warnings
    void branches;
    void departments;
    void positions;
    void managers;
    void users;
    void countries;
    void religions;
    void genders;
    void banks;
    void recent_activity;

    const displayName = useMemo(() => {
        return (
            `${employee.first_name ?? ''} ${employee.last_name ?? ''}`.trim() ||
            'Employee'
        );
    }, [employee.first_name, employee.last_name]);

    const initials = useMemo(() => {
        return (
            `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() ||
            'E'
        );
    }, [employee.first_name, employee.last_name]);

    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const stats = [
        {
            label: 'Documents',
            count: 0,
            icon: FileText,
            color: 'text-blue-400',
        },
        {
            label: 'Payslips',
            count: 0,
            icon: Receipt,
            color: 'text-emerald-400',
            badge: 'New',
        },
        { label: 'Goals', count: 0, icon: Target, color: 'text-purple-400' },
        {
            label: 'Offers',
            count: 0,
            icon: Briefcase,
            color: 'text-amber-400',
            badge: 'New',
        },
        {
            label: 'Time Off',
            count: 0,
            icon: Clock,
            color: 'text-rose-400',
            badge: 'New',
        },
        {
            label: 'Work Entries',
            count: 0,
            icon: Briefcase,
            color: 'text-cyan-400',
        },
        {
            label: 'Monthly Hours',
            count: '00:00',
            icon: Clock,
            color: 'text-indigo-400',
        },
    ];

    const tabs = [
        { id: 'work', label: 'Work' },
        { id: 'resume', label: 'Resume' },
        { id: 'personal', label: 'Personal' },
        { id: 'payroll', label: 'Payroll' },
        { id: 'adjustments', label: 'Salary Adjustments' },
        { id: 'settings', label: 'Settings' },
        { id: 'education', label: 'Educational Qualifications' },
        { id: 'trainings', label: 'Trainings' },
        { id: 'languages', label: 'Languages' },
        { id: 'references', label: 'References' },
        { id: 'document', label: 'Document' },
        { id: 'notes', label: 'Notes' },
    ];

    return (
        <>
            <Head title={`Employee • ${displayName}`} />
            <Main className="bg-background p-0">
                {/* Top Toolbar */}
                <div className="hide-scrollbar sticky top-0 z-50 flex items-center justify-between overflow-x-auto border-b border-border/50 bg-card/80 px-4 py-2 backdrop-blur-md md:px-6">
                    <div className="flex shrink-0 items-center gap-2 md:gap-4">
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 border-none bg-primary px-3 text-xs font-bold text-primary-foreground hover:bg-primary/90 md:px-4 md:text-sm"
                        >
                            New
                        </Button>
                        <div className="flex items-center text-xs font-medium whitespace-nowrap text-zinc-400 md:text-sm">
                            <span className="hidden cursor-pointer transition-colors hover:text-white sm:inline">
                                Crew
                            </span>
                            <span className="mx-2 hidden text-zinc-600 sm:inline">
                                /
                            </span>
                            <span className="font-semibold text-zinc-200">
                                {displayName}
                            </span>
                            <Settings className="ml-2 h-4 w-4 shrink-0 cursor-pointer text-zinc-500 hover:text-white" />
                        </div>
                    </div>

                    <div className="ml-4 flex shrink-0 items-center gap-1">
                        <div className="flex hidden items-center rounded-md border border-border/50 bg-muted/20 p-0.5 md:flex">
                            {stats.map((stat, i) => (
                                <button
                                    key={i}
                                    className="group relative flex flex-col items-center justify-center rounded px-2 py-1.5 transition-colors hover:bg-muted/30 lg:px-4"
                                >
                                    <div className="flex items-center gap-2">
                                        <stat.icon
                                            className={cn(
                                                'h-4 w-4',
                                                stat.color,
                                            )}
                                        />
                                        <span className="text-[11px] font-bold text-zinc-300">
                                            {stat.count}
                                        </span>
                                    </div>
                                    <span className="mt-0.5 text-[9px] font-bold tracking-tighter whitespace-nowrap text-zinc-500 uppercase">
                                        {stat.label}
                                    </span>
                                    {stat.badge && (
                                        <span className="absolute top-1 right-1 rounded-sm bg-rose-500 px-1 py-0.5 text-[8px] leading-none font-bold tracking-tighter text-white uppercase">
                                            {stat.badge}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <div className="ml-2 flex items-center gap-1 md:ml-4">
                            <span className="hidden font-mono text-xs text-zinc-500 sm:inline">
                                1 / 80
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-zinc-400"
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-zinc-400"
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Main Content Area */}
                <div className="mx-auto max-w-6xl p-4 md:p-8">
                    <div className="flex flex-col items-start gap-6 md:flex-row md:gap-8">
                        {/* Profile Image */}
                        <div className="group relative mx-auto shrink-0 md:mx-0">
                            <div className="h-40 w-32 overflow-hidden rounded-lg border border-border/50 bg-secondary/50 shadow-2xl transition-transform duration-500 group-hover:scale-[1.02]">
                                {imageSrc ? (
                                    <img
                                        src={imageSrc}
                                        alt={displayName}
                                        className="h-full w-full object-cover"
                                    />
                                ) : (
                                    <div className="flex h-full w-full items-center justify-center bg-muted/30 text-4xl font-bold text-muted-foreground">
                                        {initials}
                                    </div>
                                )}
                            </div>
                            <button className="absolute -right-2 -bottom-2 flex h-8 w-8 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
                                <Plus className="h-4 w-4" />
                            </button>
                        </div>

                        {/* Header Info */}
                        <div className="w-full flex-1 space-y-4 text-center md:text-left">
                            <div className="flex flex-col items-center justify-between gap-4 md:flex-row md:items-start">
                                <div>
                                    <h1 className="text-3xl font-bold tracking-tight text-foreground uppercase md:text-4xl">
                                        {displayName}
                                    </h1>
                                    <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start md:gap-3">
                                        <div className="flex items-center gap-2 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-[10px] font-bold tracking-widest text-emerald-400 uppercase">
                                            <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
                                            {employee.status}
                                        </div>
                                        <div className="flex items-center gap-2 rounded-full border border-border/50 bg-muted/30 px-3 py-1 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            {employee.employee_no}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="flex items-center gap-2 rounded-full border border-amber-500/20 bg-amber-500/10 px-4 py-1.5 text-[11px] font-bold tracking-widest text-amber-500 uppercase shadow-[0_0_20px_rgba(245,158,11,0.1)]">
                                        <div className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                        Absent
                                    </div>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-x-12 gap-y-4 pt-4 text-left sm:grid-cols-2">
                                <div className="space-y-3">
                                    <div className="group flex cursor-pointer items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-muted/30 text-muted-foreground transition-colors group-hover:text-primary">
                                            <Mail className="h-4 w-4" />
                                        </div>
                                        <span className="text-sm font-medium text-zinc-300 transition-colors group-hover:text-white">
                                            {employee.work_email ||
                                                'no-email@company.com'}
                                        </span>
                                    </div>
                                    <div className="group flex cursor-pointer items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-muted/30 text-muted-foreground transition-colors group-hover:text-primary">
                                            <Phone className="h-4 w-4" />
                                        </div>
                                        <span className="text-sm font-medium text-zinc-300 transition-colors group-hover:text-white">
                                            {employee.phone ||
                                                '+971 -- --- ----'}
                                        </span>
                                    </div>
                                    <div className="group flex cursor-pointer items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-muted/30 text-muted-foreground transition-colors group-hover:text-primary">
                                            <LinkIcon className="h-4 w-4" />
                                        </div>
                                        <span className="text-sm font-medium text-zinc-300 transition-colors group-hover:text-white">
                                            {employee.id}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        <Badge className="flex items-center gap-2 rounded-md border-primary/20 bg-primary/10 px-3 py-1 text-[10px] font-bold tracking-widest text-primary uppercase hover:bg-primary/20">
                                            <Briefcase className="h-3 w-3" />
                                            {employee.position?.title ||
                                                'Technical Staff'}
                                        </Badge>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Tabs Navigation */}
                    <div className="mt-8 md:mt-12">
                        <Tabs defaultValue="personal" className="w-full">
                            <TabsList className="hide-scrollbar h-auto w-full flex-nowrap justify-start gap-4 overflow-x-auto rounded-none border-b border-border/50 bg-transparent p-0 md:gap-8">
                                {tabs.map((tab) => (
                                    <TabsTrigger
                                        key={tab.id}
                                        value={tab.id}
                                        className="shrink-0 rounded-none border-b-2 border-transparent bg-transparent px-0 py-3 text-xs font-bold tracking-widest whitespace-nowrap text-muted-foreground uppercase transition-all hover:text-foreground data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-foreground md:py-4"
                                    >
                                        {tab.label}
                                    </TabsTrigger>
                                ))}
                            </TabsList>

                            <TabsContent
                                value="personal"
                                className="mt-8 space-y-12"
                            >
                                <div className="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:gap-24">
                                    {/* Left Column: Private Contact */}
                                    <div className="space-y-8">
                                        <div className="flex items-center gap-4">
                                            <h3 className="text-[11px] font-bold tracking-[0.3em] text-muted-foreground uppercase">
                                                Private Contact
                                            </h3>
                                            <Separator className="flex-1 bg-border/50" />
                                        </div>

                                        <div className="space-y-6">
                                            {[
                                                {
                                                    label: 'Email',
                                                    value:
                                                        employee.personal_email ||
                                                        employee.work_email,
                                                },
                                                {
                                                    label: 'Phone (UAE)',
                                                    value: employee.phone,
                                                },
                                                {
                                                    label: 'Phone (Home Country)',
                                                    value:
                                                        employee.phone_home_country ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Bank Accounts',
                                                    value: employee.iban || '—',
                                                    isBadge: true,
                                                },
                                                {
                                                    label: 'Source Of CV',
                                                    value:
                                                        employee.cv_source ||
                                                        'Direct From Applicant',
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="group flex items-start justify-between"
                                                >
                                                    <label className="w-40 text-xs font-bold text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="flex-1 text-xs font-medium text-zinc-200">
                                                        {item.isBadge &&
                                                        item.value !== '—' ? (
                                                            <div className="inline-flex items-center gap-2 rounded border border-primary/20 bg-primary/10 px-2 py-1 font-mono text-[10px] text-primary">
                                                                {item.value}
                                                                <Plus className="h-3 w-3 cursor-pointer transition-transform hover:rotate-90" />
                                                            </div>
                                                        ) : (
                                                            item.value
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="flex items-center gap-4 pt-4">
                                            <h3 className="text-[11px] font-bold tracking-[0.3em] text-muted-foreground uppercase">
                                                Emergency Contact
                                            </h3>
                                            <Separator className="flex-1 bg-border/50" />
                                        </div>

                                        <div className="space-y-6">
                                            {[
                                                {
                                                    label: 'Contact',
                                                    value:
                                                        employee.emergency_contact ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Phone',
                                                    value:
                                                        employee.emergency_phone ||
                                                        '—',
                                                },
                                                {
                                                    label: 'UAE Contact',
                                                    value: '—',
                                                },
                                                {
                                                    label: 'UAE Phone',
                                                    value: '—',
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="group flex items-start justify-between"
                                                >
                                                    <label className="w-40 text-xs font-bold text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="flex-1 text-xs font-medium text-zinc-200">
                                                        {item.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Right Column: Personal Information & Citizenship */}
                                    <div className="space-y-8">
                                        <div className="flex items-center gap-4">
                                            <h3 className="text-[11px] font-bold tracking-[0.3em] text-muted-foreground uppercase">
                                                Personal Information
                                            </h3>
                                            <Separator className="flex-1 bg-border/50" />
                                        </div>

                                        <div className="space-y-6">
                                            {[
                                                {
                                                    label: 'Legal Name',
                                                    value: displayName.toUpperCase(),
                                                },
                                                {
                                                    label: 'Birthday',
                                                    value:
                                                        employee.date_of_birth ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Place of Birth',
                                                    value:
                                                        employee.place_of_birth ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Gender',
                                                    value: 'Male',
                                                },
                                                {
                                                    label: 'Religion',
                                                    value: '—',
                                                },
                                                {
                                                    label: 'Visa Types',
                                                    value: '—',
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="group flex items-start justify-between"
                                                >
                                                    <label className="w-40 text-xs font-bold text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="flex-1 text-xs font-medium text-zinc-200">
                                                        {item.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="flex items-center gap-4 pt-4">
                                            <h3 className="text-[11px] font-bold tracking-[0.3em] text-muted-foreground uppercase">
                                                Citizenship
                                            </h3>
                                            <Separator className="flex-1 bg-border/50" />
                                        </div>

                                        <div className="space-y-6">
                                            {[
                                                {
                                                    label: 'Nationality (Country)',
                                                    value:
                                                        employee.nationality_ref
                                                            ?.name || '—',
                                                },
                                                {
                                                    label: 'Labor Contract ID',
                                                    value:
                                                        employee.labor_contract_id ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Passport No',
                                                    value:
                                                        employee.passport_number ||
                                                        '—',
                                                },
                                                {
                                                    label: 'Emirates ID',
                                                    value:
                                                        employee.emirates_id ||
                                                        '—',
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="group flex items-start justify-between"
                                                >
                                                    <label className="w-40 text-xs font-bold text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="flex-1 text-xs font-medium text-zinc-200">
                                                        {item.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </div>
                </div>
            </Main>

            <style>{`
                .hide-scrollbar::-webkit-scrollbar {
                    display: none;
                }
                .hide-scrollbar {
                    -ms-overflow-style: none;
                    scrollbar-width: none;
                }
            `}</style>
        </>
    );
}
