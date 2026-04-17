import { Head } from '@inertiajs/react';
import {
    Activity,
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
    MessageSquare,
    MoreHorizontal,
    Phone,
    Plus,
    Receipt,
    Settings,
    StickyNote,
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
    manager: { id: number; employee_no: string | null; name: string | null } | null;
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
    nationality_ref?: { id: number; name: string | null; code?: string | null } | null;
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

function changedKeys(oldValues: Record<string, unknown> | null, newValues: Record<string, unknown> | null): string[] {
    const keys = new Set<string>([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]);

    return [...keys].filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k)).sort((a, b) => a.localeCompare(b));
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

    const displayName = useMemo(() => {
        return `${employee.first_name ?? ''} ${employee.last_name ?? ''}`.trim() || 'Employee';
    }, [employee.first_name, employee.last_name]);

    const initials = useMemo(() => {
        return `${employee.first_name?.[0] ?? ''}${employee.last_name?.[0] ?? ''}`.toUpperCase() || 'E';
    }, [employee.first_name, employee.last_name]);

    const imageSrc = employee.image
        ? employee.image.startsWith('http')
            ? employee.image
            : `/storage/${employee.image.replace(/^\/+/, '')}`
        : null;

    const stats = [
        { label: 'Documents', count: 0, icon: FileText, color: 'text-blue-400' },
        { label: 'Payslips', count: 0, icon: Receipt, color: 'text-emerald-400', badge: 'New' },
        { label: 'Goals', count: 0, icon: Target, color: 'text-purple-400' },
        { label: 'Offers', count: 0, icon: Briefcase, color: 'text-amber-400', badge: 'New' },
        { label: 'Time Off', count: 0, icon: Clock, color: 'text-rose-400', badge: 'New' },
        { label: 'Work Entries', count: 0, icon: Briefcase, color: 'text-cyan-400' },
        { label: 'Monthly Hours', count: '00:00', icon: Clock, color: 'text-indigo-400' },
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
            <Main className="p-0 bg-background">
                {/* Top Toolbar */}
                <div className="flex items-center justify-between px-6 py-2 border-b border-border/50 bg-card/80 backdrop-blur-md sticky top-0 z-50">
                    <div className="flex items-center gap-4">
                        <Button variant="outline" size="sm" className="bg-primary hover:bg-primary/90 text-primary-foreground border-none h-8 px-4 font-bold">
                            New
                        </Button>
                        <div className="flex items-center text-sm text-zinc-400 font-medium">
                            <span className="hover:text-white cursor-pointer transition-colors">Crew</span>
                            <span className="mx-2 text-zinc-600">/</span>
                            <span className="text-zinc-200 font-semibold">{displayName}</span>
                            <Settings className="w-4 h-4 ml-2 text-zinc-500 hover:text-white cursor-pointer" />
                        </div>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="flex items-center bg-muted/20 rounded-md p-0.5 border border-border/50">
                            {stats.map((stat, i) => (
                                <button
                                    key={i}
                                    className="flex flex-col items-center justify-center px-4 py-1.5 hover:bg-muted/30 rounded transition-colors group relative"
                                >
                                    <div className="flex items-center gap-2">
                                        <stat.icon className={cn('w-4 h-4', stat.color)} />
                                        <span className="text-[11px] font-bold text-zinc-300">{stat.count}</span>
                                    </div>
                                    <span className="text-[9px] uppercase tracking-tighter text-zinc-500 font-bold mt-0.5">{stat.label}</span>
                                    {stat.badge && (
                                        <span className="absolute top-1 right-1 text-[8px] bg-rose-500 text-white px-1 rounded-sm leading-none py-0.5 font-bold uppercase tracking-tighter">
                                            {stat.badge}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <div className="flex items-center ml-4 gap-1">
                            <span className="text-xs text-zinc-500 font-mono">1 / 80</span>
                            <Button variant="ghost" size="icon" className="h-8 w-8 text-zinc-400">
                                <ChevronLeft className="w-4 h-4" />
                            </Button>
                            <Button variant="ghost" size="icon" className="h-8 w-8 text-zinc-400">
                                <ChevronRight className="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Secondary Action Bar */}
                <div className="flex items-center justify-between px-6 py-3 border-b border-border/50 bg-secondary/50">
                    <div className="flex items-center gap-2">
                        <Button className="bg-primary hover:bg-primary/90 text-primary-foreground border-none h-9 px-4 font-bold text-xs uppercase tracking-wider">
                            Create User
                        </Button>
                        <Button variant="secondary" className="bg-muted hover:bg-muted/80 text-foreground border-none h-9 px-4 font-bold text-xs uppercase tracking-wider">
                            Request Appraisal
                        </Button>
                        <Button variant="secondary" className="bg-muted hover:bg-muted/80 text-foreground border-none h-9 px-4 font-bold text-xs uppercase tracking-wider">
                            Launch Plan
                        </Button>
                        <Button variant="secondary" className="bg-muted hover:bg-muted/80 text-foreground border-none h-9 px-4 font-bold text-xs uppercase tracking-wider">
                            Signature Request
                        </Button>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="flex items-center bg-muted/50 rounded-md border border-border/50 h-9 px-3">
                            <span className="text-xs font-bold text-zinc-300">Feb 2, 2026</span>
                            <Plus className="w-4 h-4 ml-4 text-zinc-500 hover:text-white cursor-pointer" />
                        </div>
                        <Separator orientation="vertical" className="h-6 bg-white/[0.1]" />
                        <div className="flex items-center gap-1">
                            <Button variant="secondary" className="bg-primary hover:bg-primary/90 text-primary-foreground border-none h-9 px-4 font-bold text-xs">
                                Send message
                            </Button>
                            <Button variant="ghost" className="text-zinc-400 hover:text-white hover:bg-white/[0.05] h-9 px-3 text-xs font-bold">
                                Log note
                            </Button>
                            <Button variant="ghost" className="text-zinc-400 hover:text-white hover:bg-white/[0.05] h-9 px-3 text-xs font-bold">
                                WhatsApp
                            </Button>
                            <Button variant="ghost" className="text-zinc-400 hover:text-white hover:bg-white/[0.05] h-9 px-3 text-xs font-bold">
                                Activity
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="flex flex-1 min-h-0 overflow-hidden">
                    {/* Main Content Area */}
                    <div className="flex-1 overflow-y-auto bg-background custom-scrollbar">
                        <div className="max-w-6xl mx-auto p-8">
                            <div className="flex gap-8 items-start">
                                {/* Profile Image */}
                                <div className="relative group">
                                    <div className="w-32 h-40 rounded-lg overflow-hidden border border-border/50 bg-secondary/50 shadow-2xl transition-transform duration-500 group-hover:scale-[1.02]">
                                        {imageSrc ? (
                                            <img src={imageSrc} alt={displayName} className="w-full h-full object-cover" />
                                        ) : (
                                            <div className="w-full h-full flex items-center justify-center text-muted-foreground font-bold text-4xl bg-muted/30">
                                                {initials}
                                            </div>
                                        )}
                                    </div>
                                    <button className="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center shadow-lg border-2 border-background opacity-0 group-hover:opacity-100 transition-opacity">
                                        <Plus className="w-4 h-4" />
                                    </button>
                                </div>

                                {/* Header Info */}
                                <div className="flex-1 space-y-4">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <h1 className="text-4xl font-bold tracking-tight text-foreground uppercase">
                                                {displayName}
                                            </h1>
                                            <div className="flex items-center gap-3 mt-3">
                                                <div className="flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold uppercase tracking-widest">
                                                    <div className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                                    {employee.status}
                                                </div>
                                                <div className="flex items-center gap-2 px-3 py-1 rounded-full bg-muted/30 border border-border/50 text-muted-foreground text-[10px] font-bold uppercase tracking-widest">
                                                    {employee.employee_no}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <div className="px-4 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-500 text-[11px] font-bold uppercase tracking-widest flex items-center gap-2 shadow-[0_0_20px_rgba(245,158,11,0.1)]">
                                                <div className="w-1.5 h-1.5 rounded-full bg-amber-500" />
                                                Absent
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-x-12 gap-y-4 pt-4">
                                        <div className="space-y-3">
                                            <div className="flex items-center gap-3 group cursor-pointer">
                                                <div className="w-8 h-8 rounded-lg bg-muted/30 border border-border/50 flex items-center justify-center text-muted-foreground group-hover:text-primary transition-colors">
                                                    <Mail className="w-4 h-4" />
                                                </div>
                                                <span className="text-sm font-medium text-zinc-300 group-hover:text-white transition-colors">{employee.work_email || 'no-email@company.com'}</span>
                                            </div>
                                            <div className="flex items-center gap-3 group cursor-pointer">
                                                <div className="w-8 h-8 rounded-lg bg-muted/30 border border-border/50 flex items-center justify-center text-muted-foreground group-hover:text-primary transition-colors">
                                                    <Phone className="w-4 h-4" />
                                                </div>
                                                <span className="text-sm font-medium text-zinc-300 group-hover:text-white transition-colors">{employee.phone || '+971 -- --- ----'}</span>
                                            </div>
                                            <div className="flex items-center gap-3 group cursor-pointer">
                                                <div className="w-8 h-8 rounded-lg bg-muted/30 border border-border/50 flex items-center justify-center text-muted-foreground group-hover:text-primary transition-colors">
                                                    <LinkIcon className="w-4 h-4" />
                                                </div>
                                                <span className="text-sm font-medium text-zinc-300 group-hover:text-white transition-colors">{employee.id}</span>
                                            </div>
                                            <div className="flex flex-wrap gap-2 pt-1">
                                                <Badge className="bg-primary/10 text-primary border-primary/20 hover:bg-primary/20 px-3 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest flex items-center gap-2">
                                                    <Briefcase className="w-3 h-3" />
                                                    {employee.position?.title || 'Technical Staff'}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Tabs Navigation */}
                            <div className="mt-12">
                                <Tabs defaultValue="personal" className="w-full">
                                    <TabsList className="bg-transparent border-b border-border/50 w-full justify-start h-auto p-0 gap-8 rounded-none">
                                        {tabs.map((tab) => (
                                            <TabsTrigger
                                                key={tab.id}
                                                value={tab.id}
                                                className="bg-transparent border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:text-foreground data-[state=active]:bg-transparent text-muted-foreground text-xs font-bold uppercase tracking-widest px-0 py-4 transition-all rounded-none hover:text-foreground"
                                            >
                                                {tab.label}
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>

                                    <TabsContent value="personal" className="mt-8 space-y-12">
                                        <div className="grid grid-cols-2 gap-24">
                                            {/* Left Column: Private Contact */}
                                            <div className="space-y-8">
                                                <div className="flex items-center gap-4">
                                                    <h3 className="text-[11px] font-bold uppercase tracking-[0.3em] text-muted-foreground">Private Contact</h3>
                                                    <Separator className="flex-1 bg-border/50" />
                                                </div>

                                                <div className="space-y-6">
                                                    {[
                                                        { label: 'Email', value: employee.personal_email || employee.work_email },
                                                        { label: 'Phone (UAE)', value: employee.phone },
                                                        { label: 'Phone (Home Country)', value: employee.phone_home_country || '—' },
                                                        { label: 'Bank Accounts', value: employee.iban || '—', isBadge: true },
                                                        { label: 'Source Of CV', value: employee.cv_source || 'Direct From Applicant' },
                                                    ].map((item, i) => (
                                                        <div key={i} className="flex items-start justify-between group">
                                                            <label className="text-xs font-bold text-zinc-400 w-40">{item.label}</label>
                                                            <div className="flex-1 text-xs font-medium text-zinc-200">
                                                                {item.isBadge && item.value !== '—' ? (
                                                                    <div className="inline-flex items-center gap-2 px-2 py-1 rounded bg-primary/10 border border-primary/20 text-primary text-[10px] font-mono">
                                                                        {item.value}
                                                                        <Plus className="w-3 h-3 cursor-pointer hover:rotate-90 transition-transform" />
                                                                    </div>
                                                                ) : (
                                                                    item.value
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>

                                                <div className="flex items-center gap-4 pt-4">
                                                    <h3 className="text-[11px] font-bold uppercase tracking-[0.3em] text-muted-foreground">Emergency Contact</h3>
                                                    <Separator className="flex-1 bg-border/50" />
                                                </div>

                                                <div className="space-y-6">
                                                    {[
                                                        { label: 'Contact', value: employee.emergency_contact || '—' },
                                                        { label: 'Phone', value: employee.emergency_phone || '—' },
                                                        { label: 'UAE Contact', value: '—' },
                                                        { label: 'UAE Phone', value: '—' },
                                                    ].map((item, i) => (
                                                        <div key={i} className="flex items-start justify-between group">
                                                            <label className="text-xs font-bold text-zinc-400 w-40">{item.label}</label>
                                                            <div className="flex-1 text-xs font-medium text-zinc-200">{item.value}</div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>

                                            {/* Right Column: Personal Information & Citizenship */}
                                            <div className="space-y-8">
                                                <div className="flex items-center gap-4">
                                                    <h3 className="text-[11px] font-bold uppercase tracking-[0.3em] text-muted-foreground">Personal Information</h3>
                                                    <Separator className="flex-1 bg-border/50" />
                                                </div>

                                                <div className="space-y-6">
                                                    {[
                                                        { label: 'Legal Name', value: displayName.toUpperCase() },
                                                        { label: 'Birthday', value: employee.date_of_birth || '—' },
                                                        { label: 'Place of Birth', value: employee.place_of_birth || '—' },
                                                        { label: 'Gender', value: 'Male' },
                                                        { label: 'Religion', value: '—' },
                                                        { label: 'Visa Types', value: '—' },
                                                    ].map((item, i) => (
                                                        <div key={i} className="flex items-start justify-between group">
                                                            <label className="text-xs font-bold text-zinc-400 w-40">{item.label}</label>
                                                            <div className="flex-1 text-xs font-medium text-zinc-200">{item.value}</div>
                                                        </div>
                                                    ))}
                                                </div>

                                                <div className="flex items-center gap-4 pt-4">
                                                    <h3 className="text-[11px] font-bold uppercase tracking-[0.3em] text-muted-foreground">Citizenship</h3>
                                                    <Separator className="flex-1 bg-border/50" />
                                                </div>

                                                <div className="space-y-6">
                                                    {[
                                                        { label: 'Nationality (Country)', value: employee.nationality_ref?.name || '—' },
                                                        { label: 'Labor Contract ID', value: employee.labor_contract_id || '—' },
                                                        { label: 'Passport No', value: employee.passport_number || '—' },
                                                        { label: 'Emirates ID', value: employee.emirates_id || '—' },
                                                    ].map((item, i) => (
                                                        <div key={i} className="flex items-start justify-between group">
                                                            <label className="text-xs font-bold text-zinc-400 w-40">{item.label}</label>
                                                            <div className="flex-1 text-xs font-medium text-zinc-200">{item.value}</div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    </TabsContent>
                                </Tabs>
                            </div>
                        </div>
                    </div>

                    {/* Right Sidebar: Activity Logs */}
                    <div className="w-[380px] border-l border-border/50 bg-card flex flex-col hidden xl:flex">
                        <div className="p-4 border-b border-border/50 flex items-center justify-between">
                            <h3 className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Activity Timeline</h3>
                            <div className="flex items-center gap-2">
                                <Button variant="ghost" size="icon" className="h-7 w-7 text-zinc-500">
                                    <MessageSquare className="w-3.5 h-3.5" />
                                </Button>
                                <Button variant="ghost" size="icon" className="h-7 w-7 text-zinc-500">
                                    <StickyNote className="w-3.5 h-3.5" />
                                </Button>
                                <Button variant="ghost" size="icon" className="h-7 w-7 text-zinc-500">
                                    <Activity className="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto custom-scrollbar p-6">
                            <div className="space-y-8 relative before:absolute before:inset-0 before:left-3 before:w-px before:bg-border/50">
                                {recent_activity.length === 0 ? (
                                    <div className="text-center py-12">
                                        <Activity className="w-8 h-8 text-zinc-800 mx-auto mb-3" />
                                        <p className="text-[10px] font-bold text-zinc-600 uppercase tracking-widest">No Recent Activity</p>
                                    </div>
                                ) : (
                                    recent_activity.map((a, i) => (
                                        <div key={a.id} className="relative pl-8 group">
                                            <div className="absolute left-1 top-1 w-4 h-4 rounded-full bg-secondary border-2 border-border flex items-center justify-center z-10 group-hover:border-primary transition-colors">
                                                <div className="w-1.5 h-1.5 rounded-full bg-muted-foreground group-hover:bg-primary transition-colors" />
                                            </div>

                                            <div className="space-y-1">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-[10px] font-bold text-foreground uppercase tracking-tight">{a.causer?.name || 'System'}</span>
                                                    <span className="text-[9px] font-bold text-muted-foreground uppercase tracking-tighter">
                                                        {new Date(a.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
                                                    </span>
                                                </div>
                                                <p className="text-[11px] leading-relaxed text-zinc-400">
                                                    {a.description}
                                                </p>
                                                <div className="pt-2 flex flex-wrap gap-2">
                                                    {Object.keys(a.new_values || {}).slice(0, 2).map((key) => (
                                                        <div key={key} className="px-2 py-0.5 rounded bg-muted/20 border border-border/50 text-[9px] font-bold text-muted-foreground">
                                                            {key}: <span className="text-foreground">{String(a.new_values?.[key])}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </Main>

            <style>{`
                .custom-scrollbar::-webkit-scrollbar {
                    width: 5px;
                }
                .custom-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(255, 255, 255, 0.05);
                    border-radius: 10px;
                }
                .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                    background: rgba(255, 255, 255, 0.1);
                }
            `}</style>
        </>
    );
}

