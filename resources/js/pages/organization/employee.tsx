import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FileText, GraduationCap, UploadCloud, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { destroy, store, update } from '@/actions/App/Http/Controllers/Organization/EmployeeEducationQualificationController';
import { Main } from '@/components/layout/main';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { DOCUMENT_STATUS_CLASSES, documentStatusLabel } from '@/features/organization/employee-documents/status';
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
import { toast } from '@/lib/toast';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';

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
    bank?: { id: number; name: string | null } | null;
    employee_no: string;
    name: string;
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
    account_name?: string | null;
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

type EmployeeContractDetails = {
    id: number;
    contract_type: string | null;
    start_date: string | null;
    end_date: string | null;
    probation_end_date: string | null;
    labor_contract_id: string | null;
    status: string | null;
    basic_salary: number | null;
    housing_allowance: number | null;
    transport_allowance: number | null;
    other_allowances: number | null;
    created_at: string;
    updated_at: string;
};

type EmployeeDocumentItem = {
    id: number;
    title: string | null;
    type: string | null;
    document_type_id: number | null;
    document_type: string | null;
    document_type_label: string | null;
    file_path: string;
    file_url: string;
    original_filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    current_version: number | null;
    can_preview: boolean;
    issue_date: string | null;
    expiry_date: string | null;
    document_number: string | null;
    notes: string | null;
    status: string | null;
    uploaded_by: string | null;
    created_at: string;
    versions: {
        id: number;
        version: number;
        file_url: string;
        original_filename: string | null;
        mime_type: string | null;
        size_bytes: number | null;
        replaced_by: string | null;
        created_at: string;
    }[];
};

type DocumentTypeOption = {
    id: number;
    title: string;
    slug: string;
};

type EducationQualificationItem = {
    id: number;
    certificate: string;
    issue_date: string | null;
    university: string | null;
    country_id: number | null;
    country_name: string | null;
};

type EmployeeTab = 'personal' | 'contract' | 'bank' | 'education' | 'documents';

export default function EmployeeDetails({
    employee,
    contract,
    documents,
    education_qualifications,
    document_types,
    can,
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
    contract: EmployeeContractDetails | null;
    documents: EmployeeDocumentItem[];
    education_qualifications: EducationQualificationItem[];
    document_types: DocumentTypeOption[];
    can: { documents_upload: boolean; documents_delete: boolean; education_manage: boolean };
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
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const canUpdate = (auth?.permissions ?? []).includes('employees.update');

    // Avoid unused variable warnings
    void branches;
    void departments;
    void positions;
    void managers;
    void users;
    void recent_activity;

    const [activeField, setActiveField] = useState<string | null>(null);
    const [tabValue, setTabValue] = useState<EmployeeTab>(() => {
        if (typeof window === 'undefined') {
            return 'personal';
        }

        if (window.location.hash === '#documents') {
            return 'documents';
        }

        if (window.location.hash === '#education') {
            return 'education';
        }

        return 'personal';
    });
    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

    const [uploadOpen, setUploadOpen] = useState(false);
    const [editDoc, setEditDoc] = useState<EmployeeDocumentItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [educationDialogOpen, setEducationDialogOpen] = useState(false);
    const [editingEducation, setEditingEducation] = useState<EducationQualificationItem | null>(null);
    const [deleteEducationId, setDeleteEducationId] = useState<number | null>(null);
    const [previewDoc, setPreviewDoc] = useState<EmployeeDocumentItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<EmployeeDocumentItem | null>(null);
    const [versionDoc, setVersionDoc] = useState<EmployeeDocumentItem | null>(null);
    const [bulkFiles, setBulkFiles] = useState<File[]>([]);
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);

    const uploadForm = useForm({
        document_type: '',
        title: '',
        file: null as File | null,
        issue_date: '',
        expiry_date: '',
        document_number: '',
        notes: '',
    });

    const editForm = useForm({
        title: '',
        document_number: '',
        issue_date: '',
        expiry_date: '',
        notes: '',
    });

    const replaceForm = useForm({
        file: null as File | null,
    });

    const educationForm = useForm({
        certificate: '',
        issue_date: '',
        university: '',
        country_id: '',
    });

    const addUploadFiles = useCallback((files: File[]) => {
        const supportedFiles = files.filter((file) => {
            return ['application/pdf', 'image/jpeg', 'image/png'].includes(file.type);
        });

        setBulkFiles((current) => {
            const next = [...current];

            supportedFiles.forEach((file) => {
                const exists = next.some((item) => {
                    return item.name === file.name && item.size === file.size && item.lastModified === file.lastModified;
                });

                if (!exists) {
                    next.push(file);
                }
            });

            uploadForm.setData('file', next[0] ?? null);

            return next;
        });

        if (supportedFiles.length !== files.length) {
            toast.error('Only PDF, JPG, JPEG, and PNG files are supported.');
        }
    }, [uploadForm]);

    const removeUploadFile = useCallback((fileIndex: number) => {
        setBulkFiles((current) => {
            const next = current.filter((_, index) => index !== fileIndex);
            uploadForm.setData('file', next[0] ?? null);

            return next;
        });
    }, [uploadForm]);

    const resetUploadDialog = useCallback(() => {
        uploadForm.reset();
        uploadForm.clearErrors();
        setBulkFiles([]);
        setIsDraggingFiles(false);
    }, [uploadForm]);

    const uploadFileSize = useMemo(() => {
        return bulkFiles.reduce((total, file) => total + file.size, 0);
    }, [bulkFiles]);

    const formatFileSize = (bytes: number): string => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }

        if (bytes < 1024 * 1024) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }

        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const initialPersonal = useMemo(
        () => ({
            employee_no: employee.employee_no ?? '',
            name: employee.name ?? '',
            branch_id: employee.branch?.id ? String(employee.branch.id) : '',
            department_id: employee.department?.id ? String(employee.department.id) : '',
            position_id: employee.position?.id ? String(employee.position.id) : '',
            manager_id: employee.manager?.id ? String(employee.manager.id) : '',
            personal_email: employee.personal_email ?? employee.work_email ?? '',
            work_email: employee.work_email ?? '',
            phone: employee.phone ?? '',
            phone_home_country: employee.phone_home_country ?? '',
            cv_source: employee.cv_source ?? '',
            emergency_contact: employee.emergency_contact ?? '',
            emergency_phone: employee.emergency_phone ?? '',
            emergency_contact_home_country: employee.emergency_contact_home_country ?? '',
            emergency_phone_home_country: employee.emergency_phone_home_country ?? '',
            nearest_airport: employee.nearest_airport ?? '',
            address: employee.address ?? '',
            date_of_birth: employee.date_of_birth ?? '',
            place_of_birth: employee.place_of_birth ?? '',
            gender_id: employee.gender_id ? String(employee.gender_id) : '',
            religion_id: employee.religion_id ? String(employee.religion_id) : '',
            nationality_id: employee.nationality_id ? String(employee.nationality_id) : '',
            marital_status: employee.marital_status ?? '',
            spouse_name: employee.spouse_name ?? '',
            spouse_birthdate: employee.spouse_birthdate ?? '',
            dependent_children_count:
                employee.dependent_children_count === null ||
                employee.dependent_children_count === undefined
                    ? ''
                    : String(employee.dependent_children_count),
            passport_number: employee.passport_number ?? '',
            emirates_id: employee.emirates_id ?? '',
            labor_card_number: employee.labor_card_number ?? '',
        }),
        [
            employee.employee_no,
            employee.name,
            employee.branch,
            employee.department,
            employee.position,
            employee.manager,
            employee.personal_email,
            employee.work_email,
            employee.phone,
            employee.phone_home_country,
            employee.cv_source,
            employee.emergency_contact,
            employee.emergency_phone,
            employee.emergency_contact_home_country,
            employee.emergency_phone_home_country,
            employee.nearest_airport,
            employee.address,
            employee.date_of_birth,
            employee.place_of_birth,
            employee.gender_id,
            employee.religion_id,
            employee.nationality_id,
            employee.marital_status,
            employee.spouse_name,
            employee.spouse_birthdate,
            employee.dependent_children_count,
            employee.passport_number,
            employee.emirates_id,
            employee.labor_card_number,
        ],
    );

    const initialContract = useMemo(
        () => ({
            contract_type: contract?.contract_type ?? employee.contract_type ?? 'unlimited',
            start_date: contract?.start_date ?? employee.start_date ?? '',
            end_date: contract?.end_date ?? employee.end_date ?? '',
            probation_end_date: contract?.probation_end_date ?? employee.probation_end_date ?? '',
            labor_contract_id: contract?.labor_contract_id ?? employee.labor_contract_id ?? '',
            basic_salary: contract?.basic_salary === null || contract?.basic_salary === undefined ? '' : String(contract.basic_salary),
            housing_allowance:
                contract?.housing_allowance === null || contract?.housing_allowance === undefined
                    ? ''
                    : String(contract.housing_allowance),
            transport_allowance:
                contract?.transport_allowance === null || contract?.transport_allowance === undefined
                    ? ''
                    : String(contract.transport_allowance),
            other_allowances:
                contract?.other_allowances === null || contract?.other_allowances === undefined
                    ? ''
                    : String(contract.other_allowances),
        }),
        [
            contract,
            employee.contract_type,
            employee.start_date,
            employee.end_date,
            employee.probation_end_date,
            employee.labor_contract_id,
        ],
    );

    const initialBank = useMemo(
        () => ({
            bank_id: employee.bank_id ? String(employee.bank_id) : '',
            account_name: employee.account_name ?? '',
            iban: employee.iban ?? '',
        }),
        [
            employee.account_name,
            employee.bank_id,
            employee.iban,
        ],
    );

    const initialAll = useMemo(() => ({ ...initialPersonal, ...initialContract, ...initialBank }), [initialBank, initialContract, initialPersonal]);

    const form = useForm(initialAll);

    const isDirty = useMemo(() => {
        return (Object.keys(initialAll) as Array<keyof typeof initialAll>).some((key) => {
            return String(form.data[key] ?? '') !== String(initialAll[key] ?? '');
        });
    }, [form.data, initialAll]);

    const requiredFields = useMemo(() => {
        return new Set(['employee_no', 'name', 'start_date', 'contract_type']);
    }, []);

    const requiredDot = (field: string) => {
        if (!requiredFields.has(field)) {
            return null;
        }

        return (
            <span className="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90 align-middle" />
        );
    };

    const beginEdit = (field: string) => {
        if (!canUpdate) {
            return;
        }

        setActiveField(field);
    };

    useEffect(() => {
        if (!canUpdate || !isDirty) {
            return;
        }

        const handler = (e: BeforeUnloadEvent) => {
            e.preventDefault();
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [canUpdate, isDirty]);

    const displayName = useMemo(() => {
        return String(form.data.name ?? '').trim() || 'Employee';
    }, [form.data.name]);

    const tabs = [
        { id: 'personal', label: 'Personal', count: null },
        { id: 'contract', label: 'Contract', count: null },
        { id: 'bank', label: 'Bank', count: form.data.bank_id || form.data.iban ? 1 : null },
        { id: 'education', label: 'Education', count: education_qualifications.length || null },
        { id: 'documents', label: 'Documents', count: documents.length || null },
    ] satisfies Array<{ id: EmployeeTab; label: string; count: number | null }>;

    useEffect(() => {
        if (window.location.hash === '#documents' || window.location.hash === '#education') {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }, []);

    const saveChanges = (afterSuccess?: () => void) => {
        if (canUpdate) {
            const missing: string[] = [];

            if (!String(form.data.employee_no ?? '').trim()) {
                missing.push('employee_no');
            }

            if (!String(form.data.name ?? '').trim()) {
                missing.push('name');
            }

            if (!String(form.data.start_date ?? '').trim()) {
                missing.push('start_date');
            }

            if (!String(form.data.contract_type ?? '').trim()) {
                missing.push('contract_type');
            }

            if (missing.length) {
                toast.error('Please fill the required fields before saving.');
                beginEdit(missing[0]);

                return;
            }
        }

        form.transform((data) => ({
            ...data,
            employee_no: data.employee_no?.trim() || null,
            name: data.name?.trim() || null,
            branch_id: data.branch_id ? Number(data.branch_id) : null,
            department_id: data.department_id ? Number(data.department_id) : null,
            position_id: data.position_id ? Number(data.position_id) : null,
            manager_id: data.manager_id ? Number(data.manager_id) : null,
            personal_email: data.personal_email?.trim() || null,
            work_email: data.work_email?.trim() || null,
            phone: data.phone?.trim() || null,
            phone_home_country: data.phone_home_country?.trim() || null,
            cv_source: data.cv_source?.trim() || null,
            emergency_contact: data.emergency_contact?.trim() || null,
            emergency_phone: data.emergency_phone?.trim() || null,
            emergency_contact_home_country: data.emergency_contact_home_country?.trim() || null,
            emergency_phone_home_country: data.emergency_phone_home_country?.trim() || null,
            nearest_airport: data.nearest_airport?.trim() || null,
            address: data.address?.trim() || null,
            date_of_birth: data.date_of_birth || null,
            place_of_birth: data.place_of_birth?.trim() || null,
            gender_id: data.gender_id ? Number(data.gender_id) : null,
            religion_id: data.religion_id ? Number(data.religion_id) : null,
            nationality_id: data.nationality_id ? Number(data.nationality_id) : null,
            marital_status: data.marital_status || null,
            spouse_name: data.spouse_name?.trim() || null,
            spouse_birthdate: data.spouse_birthdate || null,
            dependent_children_count:
                data.dependent_children_count === ''
                    ? null
                    : Number(data.dependent_children_count),
            contract_type: data.contract_type,
            start_date: data.start_date,
            end_date: data.end_date || null,
            probation_end_date: data.probation_end_date || null,
            labor_contract_id: data.labor_contract_id?.trim() || null,
            passport_number: data.passport_number?.trim() || null,
            emirates_id: data.emirates_id?.trim() || null,
            labor_card_number: data.labor_card_number?.trim() || null,
            basic_salary: data.basic_salary === '' ? null : Number(data.basic_salary),
            housing_allowance: data.housing_allowance === '' ? null : Number(data.housing_allowance),
            transport_allowance: data.transport_allowance === '' ? null : Number(data.transport_allowance),
            other_allowances: data.other_allowances === '' ? null : Number(data.other_allowances),
            bank_id: data.bank_id ? Number(data.bank_id) : null,
            iban: data.iban?.trim() || null,
            account_name: data.account_name?.trim() || null,
        }));

        form.put(`/organization/employees/${employee.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setActiveField(null);
                toast.success('Changes saved.');
                afterSuccess?.();
            },
            onError: (errors) => {
                const first = Object.values(errors ?? {})[0];
                toast.error(typeof first === 'string' && first.length ? first : 'Failed to save changes.');
            },
        });
    };

    const discardChanges = () => {
        form.setData(initialAll);
        form.clearErrors();
        setActiveField(null);
    };

    const handleTabChange = (next: EmployeeTab) => {
        if (!canUpdate || !isDirty) {
            setTabValue(next);

            return;
        }

        setPendingTab(next);
        setUnsavedDialogOpen(true);
    };

    return (
        <>
            <Head title={`Employee • ${displayName}`} />
            <Main className="min-h-screen bg-[radial-gradient(circle_at_top_right,rgba(99,102,241,0.10),transparent_28%),radial-gradient(circle_at_bottom_left,rgba(16,185,129,0.08),transparent_26%)] p-0">
                {/* Main Content Area - Full Width */}
                <div className="w-full px-4 py-5 md:px-6 md:py-6 xl:px-8">
                    <div className="w-full space-y-6">
                        {canUpdate && isDirty ? (
                            <div className="sticky top-4 z-20 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 shadow-lg shadow-black/20 backdrop-blur-xl">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="text-sm font-semibold text-amber-100">
                                        You have unsaved changes
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="h-9 rounded-lg"
                                            onClick={discardChanges}
                                            disabled={form.processing}
                                        >
                                            Discard
                                        </Button>
                                        <Button
                                            type="button"
                                            className="h-9 rounded-lg"
                                            onClick={() => saveChanges()}
                                            disabled={form.processing}
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        ) : null}
                        <AlertDialog open={unsavedDialogOpen} onOpenChange={setUnsavedDialogOpen}>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Unsaved changes</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        You have unsaved changes. What would you like to do?
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel
                                        onClick={() => {
                                            setPendingTab(null);
                                            setUnsavedDialogOpen(false);
                                        }}
                                    >
                                        Stay
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() => {
                                            discardChanges();

                                            if (pendingTab) {
                                                setTabValue(pendingTab);
                                            }

                                            setPendingTab(null);
                                            setUnsavedDialogOpen(false);
                                        }}
                                    >
                                        Discard
                                    </AlertDialogAction>
                                    <AlertDialogAction
                                        onClick={() => {
                                            saveChanges(() => {
                                                if (pendingTab) {
                                                    setTabValue(pendingTab);
                                                }

                                                setPendingTab(null);
                                                setUnsavedDialogOpen(false);
                                            });
                                        }}
                                    >
                                        Save
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>

                        <EmployeeHeaderCard
                            canUpdate={canUpdate}
                            employee={employee}
                            branches={branches}
                            departments={departments}
                            positions={positions}
                            managers={managers}
                            genders={genders}
                            religions={religions}
                            form={form}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            requiredDot={requiredDot}
                        />

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            <div className="rounded-2xl border border-white/10 bg-card/60 p-4 shadow-lg shadow-black/10 backdrop-blur-xl">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">Employee no</div>
                                <div className="mt-2 text-lg font-bold text-zinc-100">{form.data.employee_no || employee.employee_no || '—'}</div>
                            </div>
                            <div className="rounded-2xl border border-white/10 bg-card/60 p-4 shadow-lg shadow-black/10 backdrop-blur-xl">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">Department</div>
                                <div className="mt-2 truncate text-lg font-bold text-zinc-100">{employee.department?.name || '—'}</div>
                            </div>
                            <div className="rounded-2xl border border-white/10 bg-card/60 p-4 shadow-lg shadow-black/10 backdrop-blur-xl">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">Contract</div>
                                <div className="mt-2 capitalize text-lg font-bold text-zinc-100">{form.data.contract_type || contract?.contract_type || '—'}</div>
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    setTabValue('education');
                                    setTimeout(() => document.getElementById('employee-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
                                }}
                                className="rounded-2xl border border-white/10 bg-card/60 p-4 text-left shadow-lg shadow-black/10 backdrop-blur-xl transition-colors hover:border-emerald-500/30 hover:bg-emerald-500/10"
                            >
                                <div className="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    <GraduationCap className="h-3.5 w-3.5 text-emerald-400/80" />
                                    Education
                                </div>
                                <div className="mt-2 text-lg font-bold text-zinc-100">
                                    {education_qualifications.length} qualification{education_qualifications.length !== 1 ? 's' : ''}
                                </div>
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setTabValue('documents');
                                    setTimeout(() => document.getElementById('employee-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
                                }}
                                className="rounded-2xl border border-white/10 bg-card/60 p-4 text-left shadow-lg shadow-black/10 backdrop-blur-xl transition-colors hover:border-primary/30 hover:bg-primary/10"
                            >
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">Documents</div>
                                <div className="mt-2 flex items-center gap-2">
                                    <span className="text-lg font-bold text-zinc-100">{documents.length}</span>
                                    {documents.filter((d) => d.status === 'expired').length > 0 && (
                                        <span className="rounded-md border border-red-500/20 bg-red-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-red-400">
                                            {documents.filter((d) => d.status === 'expired').length} expired
                                        </span>
                                    )}
                                    {documents.filter((d) => d.status === 'expiring_soon').length > 0 && (
                                        <span className="rounded-md border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-400">
                                            {documents.filter((d) => d.status === 'expiring_soon').length} expiring
                                        </span>
                                    )}
                                </div>
                            </button>
                        </div>

                    {/* Tabs Navigation */}
                        <div id="employee-tabs" className="rounded-[1.75rem] border border-white/10 bg-card/60 p-2 shadow-2xl shadow-black/10 backdrop-blur-xl">
                            <Tabs value={tabValue} onValueChange={(v) => handleTabChange(v as EmployeeTab)} className="w-full">
                            <TabsList className="hide-scrollbar h-auto w-full flex-nowrap justify-start gap-2 overflow-x-auto rounded-2xl border border-white/10 bg-black/10 p-1.5">
                                {tabs.map((tab) => (
                                    <TabsTrigger
                                        key={tab.id}
                                        value={tab.id}
                                        className="shrink-0 rounded-xl border border-transparent bg-transparent px-4 py-2.5 text-xs font-bold tracking-wide whitespace-nowrap text-zinc-400 transition-colors hover:bg-white/5 hover:text-zinc-200 data-[state=active]:border-white/10 data-[state=active]:bg-white/10 data-[state=active]:text-white data-[state=active]:shadow-lg data-[state=active]:shadow-black/20"
                                    >
                                        {tab.label}
                                        {tab.count !== null && (
                                            <span className="ml-1.5 rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-zinc-400">
                                                {tab.count}
                                            </span>
                                        )}
                                    </TabsTrigger>
                                ))}
                            </TabsList>

                            <TabsContent
                                value="personal"
                                className="mt-6"
                            >
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 2xl:grid-cols-3">
                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                        <div className="mb-4 flex items-center justify-between gap-3">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Private contact
                                            </h3>
                                        </div>

                                        <div className="space-y-4">
                                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                <label className="text-xs font-medium text-zinc-400">Email</label>
                                                {activeField === 'personal_email' ? (
                                                    <div>
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.personal_email}
                                                            onChange={(e) => form.setData('personal_email', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                        {form.errors.personal_email ? (
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.personal_email}</div>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                        onClick={() => beginEdit('personal_email')}
                                                    >
                                                        {form.data.personal_email || employee.personal_email || '—'}
                                                    </button>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                <label className="text-xs font-medium text-zinc-400">Phone (Home Country)</label>
                                                {activeField === 'phone_home_country' ? (
                                                    <div>
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.phone_home_country}
                                                            onChange={(e) => form.setData('phone_home_country', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                        {form.errors.phone_home_country ? (
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.phone_home_country}</div>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                        onClick={() => beginEdit('phone_home_country')}
                                                    >
                                                        {form.data.phone_home_country || employee.phone_home_country || '—'}
                                                    </button>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                <label className="text-xs font-medium text-zinc-400">Source Of CV</label>
                                                {activeField === 'cv_source' ? (
                                                    <div>
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.cv_source}
                                                            onChange={(e) => form.setData('cv_source', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                        {form.errors.cv_source ? (
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.cv_source}</div>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                        onClick={() => beginEdit('cv_source')}
                                                    >
                                                        {form.data.cv_source || employee.cv_source || '—'}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Emergency contact
                                            </h3>
                                        </div>

                                        <div className="space-y-3">
                                            {[
                                                {
                                                    label: 'Contact',
                                                    value:
                                                        activeField === 'emergency_contact' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_contact}
                                                                onChange={(e) => form.setData('emergency_contact', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_contact')}
                                                            >
                                                                {form.data.emergency_contact || employee.emergency_contact || '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Phone',
                                                    value:
                                                        activeField === 'emergency_phone' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_phone}
                                                                onChange={(e) => form.setData('emergency_phone', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_phone')}
                                                            >
                                                                {form.data.emergency_phone || employee.emergency_phone || '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Home country contact',
                                                    value:
                                                        activeField === 'emergency_contact_home_country' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_contact_home_country}
                                                                onChange={(e) => form.setData('emergency_contact_home_country', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_contact_home_country')}
                                                            >
                                                                {form.data.emergency_contact_home_country ||
                                                                    employee.emergency_contact_home_country ||
                                                                    '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Home country phone',
                                                    value:
                                                        activeField === 'emergency_phone_home_country' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_phone_home_country}
                                                                onChange={(e) => form.setData('emergency_phone_home_country', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_phone_home_country')}
                                                            >
                                                                {form.data.emergency_phone_home_country ||
                                                                    employee.emergency_phone_home_country ||
                                                                    '—'}
                                                            </button>
                                                        ),
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="text-sm font-medium text-zinc-200">
                                                        {item.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Family
                                            </h3>
                                        </div>

                                        <div className="space-y-3">
                                            {[
                                                {
                                                    key: 'spouse_name',
                                                    label: 'Spouse name',
                                                    input: (
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.spouse_name}
                                                            onChange={(e) => form.setData('spouse_name', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ),
                                                    value: form.data.spouse_name || employee.spouse_name || '—',
                                                },
                                                {
                                                    key: 'spouse_birthdate',
                                                    label: 'Spouse birthdate',
                                                    input: (
                                                        <Input
                                                            type="date"
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.spouse_birthdate}
                                                            onChange={(e) => form.setData('spouse_birthdate', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ),
                                                    value: form.data.spouse_birthdate || employee.spouse_birthdate || '—',
                                                },
                                                {
                                                    key: 'dependent_children_count',
                                                    label: 'Dependent children',
                                                    input: (
                                                        <Input
                                                            inputMode="numeric"
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={String(form.data.dependent_children_count ?? '')}
                                                            onChange={(e) => form.setData('dependent_children_count', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ),
                                                    value:
                                                        String(form.data.dependent_children_count ?? '') ||
                                                        (employee.dependent_children_count === null || employee.dependent_children_count === undefined
                                                            ? '—'
                                                            : String(employee.dependent_children_count)),
                                                },
                                            ].map((row) => (
                                                <div
                                                    key={row.key}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {row.label}
                                                    </label>
                                                    {activeField === row.key ? (
                                                        <div>{row.input}</div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit(row.key)}
                                                        >
                                                            {row.value}
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Location
                                            </h3>
                                        </div>

                                        <div className="space-y-3">
                                            {[
                                                {
                                                    key: 'nearest_airport',
                                                    label: 'Nearest airport',
                                                    input: (
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.nearest_airport}
                                                            onChange={(e) => form.setData('nearest_airport', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ),
                                                    value: form.data.nearest_airport || employee.nearest_airport || '—',
                                                },
                                                {
                                                    key: 'address',
                                                    label: 'Address',
                                                    input: (
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={form.data.address}
                                                            onChange={(e) => form.setData('address', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ),
                                                    value: form.data.address || employee.address || '—',
                                                },
                                            ].map((row) => (
                                                <div
                                                    key={row.key}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {row.label}
                                                    </label>
                                                    {activeField === row.key ? (
                                                        <div>{row.input}</div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit(row.key)}
                                                        >
                                                            {row.value}
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl xl:col-span-2 2xl:col-span-3">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Citizenship
                                            </h3>
                                        </div>

                                        <div className="space-y-4">
                                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                <label className="text-xs font-medium text-zinc-400">
                                                    Nationality (Country)
                                                </label>
                                                {activeField === 'nationality_id' ? (
                                                    <div>
                                                        <select
                                                            className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                            value={form.data.nationality_id}
                                                            onChange={(e) => form.setData('nationality_id', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        >
                                                            <option value="">—</option>
                                                            {countries.map((c) => (
                                                                <option key={c.id} value={String(c.id)}>
                                                                    {c.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {form.errors.nationality_id ? (
                                                            <div className="mt-1 text-xs text-destructive">
                                                                {form.errors.nationality_id}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                        onClick={() => beginEdit('nationality_id')}
                                                    >
                                                        {countries.find((c) => String(c.id) === String(form.data.nationality_id || employee.nationality_id || ''))?.name ??
                                                            employee.nationality_ref?.name ??
                                                            '—'}
                                                    </button>
                                                )}
                                            </div>

                                            {[
                                                {
                                                    key: 'passport_number',
                                                    label: 'Passport No',
                                                    value: form.data.passport_number || employee.passport_number || '—',
                                                },
                                                {
                                                    key: 'emirates_id',
                                                    label: 'Emirates ID',
                                                    value: form.data.emirates_id || employee.emirates_id || '—',
                                                },
                                                {
                                                    key: 'labor_card_number',
                                                    label: 'Labor card number',
                                                    value: form.data.labor_card_number || employee.labor_card_number || '—',
                                                },
                                            ].map((item) => (
                                                <div
                                                    key={item.key}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    {activeField === item.key ? (
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={(form.data as any)[item.key]}
                                                            onChange={(e) => form.setData(item.key as any, e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit(item.key)}
                                                        >
                                                            {item.value}
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="contract" className="mt-6">
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                    <div className="space-y-6 xl:col-span-7">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Contract
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        Contract type
                                                        {requiredDot('contract_type')}
                                                    </label>
                                                    {activeField === 'contract_type' ? (
                                                        <div>
                                                            <select
                                                                className="w-full h-10 rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                                value={form.data.contract_type}
                                                                onChange={(e) => form.setData('contract_type', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            >
                                                                <option value="limited">Limited</option>
                                                                <option value="unlimited">Unlimited</option>
                                                                <option value="part_time">Part Time</option>
                                                                <option value="contract">Contract</option>
                                                            </select>
                                                            {form.errors.contract_type ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.contract_type}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('contract_type')}
                                                        >
                                                            {form.data.contract_type || contract?.contract_type || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        Start date
                                                        {requiredDot('start_date')}
                                                    </label>
                                                    {activeField === 'start_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.start_date}
                                                                onChange={(e) => form.setData('start_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.start_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.start_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('start_date')}
                                                        >
                                                            {form.data.start_date || contract?.start_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">End date</label>
                                                    {activeField === 'end_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.end_date}
                                                                onChange={(e) => form.setData('end_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.end_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.end_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('end_date')}
                                                        >
                                                            {form.data.end_date || contract?.end_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Probation end date</label>
                                                    {activeField === 'probation_end_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.probation_end_date}
                                                                onChange={(e) => form.setData('probation_end_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.probation_end_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.probation_end_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('probation_end_date')}
                                                        >
                                                            {form.data.probation_end_date || contract?.probation_end_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Labor contract ID</label>
                                                    {activeField === 'labor_contract_id' ? (
                                                        <div>
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.labor_contract_id}
                                                                onChange={(e) => form.setData('labor_contract_id', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.labor_contract_id ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.labor_contract_id}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('labor_contract_id')}
                                                        >
                                                            {form.data.labor_contract_id || contract?.labor_contract_id || '—'}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-6 xl:col-span-5">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Salary
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                {(
                                                    [
                                                        { key: 'basic_salary', label: 'Basic salary' },
                                                        { key: 'housing_allowance', label: 'Housing allowance' },
                                                        { key: 'transport_allowance', label: 'Transport allowance' },
                                                        { key: 'other_allowances', label: 'Other allowances' },
                                                    ] as const
                                                ).map((row) => (
                                                    <div
                                                        key={row.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">
                                                            {row.label}
                                                        </label>
                                                        {activeField === row.key ? (
                                                            <div>
                                                                <Input
                                                                    inputMode="decimal"
                                                                    className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                    value={String((form.data as any)[row.key] ?? '')}
                                                                    onChange={(e) => form.setData(row.key as any, e.target.value)}
                                                                    onBlur={() => setActiveField(null)}
                                                                    autoFocus
                                                                />
                                                                {(form.errors as any)[row.key] ? (
                                                                    <div className="text-xs text-destructive mt-1">
                                                                        {(form.errors as any)[row.key]}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit(row.key)}
                                                            >
                                                                {String((form.data as any)[row.key] ?? '') ||
                                                                ((contract as any)?.[row.key] === null || (contract as any)?.[row.key] === undefined
                                                                    ? '—'
                                                                    : String((contract as any)?.[row.key]))}
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="bank" className="mt-6">
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                    <div className="space-y-6 xl:col-span-7">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Bank account
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Bank</label>
                                                    {activeField === 'bank_id' ? (
                                                        <div>
                                                            <select
                                                                className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                                value={form.data.bank_id}
                                                                onChange={(e) => form.setData('bank_id', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            >
                                                                <option value="">—</option>
                                                                {banks.map((bank) => (
                                                                    <option key={bank.id} value={String(bank.id)}>
                                                                        {bank.name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {form.errors.bank_id ? (
                                                                <div className="mt-1 text-xs text-destructive">{form.errors.bank_id}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('bank_id')}
                                                        >
                                                            {banks.find((bank) => String(bank.id) === String(form.data.bank_id || employee.bank_id || ''))?.name ??
                                                                employee.bank?.name ??
                                                                '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                {[
                                                    {
                                                        key: 'account_name',
                                                        label: 'Account holder',
                                                        value: form.data.account_name || employee.account_name || '—',
                                                    },
                                                    {
                                                        key: 'iban',
                                                        label: 'IBAN',
                                                        value: form.data.iban || employee.iban || '—',
                                                    },
                                                ].map((item) => (
                                                    <div
                                                        key={item.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">{item.label}</label>
                                                        {activeField === item.key ? (
                                                            <div>
                                                                <Input
                                                                    className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                    value={(form.data as any)[item.key]}
                                                                    onChange={(e) => form.setData(item.key as any, e.target.value)}
                                                                    onBlur={() => setActiveField(null)}
                                                                    autoFocus
                                                                />
                                                                {(form.errors as any)[item.key] ? (
                                                                    <div className="mt-1 text-xs text-destructive">
                                                                        {(form.errors as any)[item.key]}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit(item.key)}
                                                            >
                                                                {item.value}
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-6 xl:col-span-5">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Payroll payment
                                                </h3>
                                            </div>

                                            <div className="space-y-3">
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">Primary bank</div>
                                                    <div className="mt-1 text-sm font-semibold text-zinc-200">
                                                        {banks.find((bank) => String(bank.id) === String(form.data.bank_id || employee.bank_id || ''))?.name ??
                                                            employee.bank?.name ??
                                                            'Not selected'}
                                                    </div>
                                                </div>
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">Account holder</div>
                                                    <div className="mt-1 text-sm font-semibold text-zinc-200">
                                                        {form.data.account_name || employee.account_name || '—'}
                                                    </div>
                                                </div>
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">IBAN</div>
                                                    <div className="mt-1 break-all font-mono text-sm font-semibold text-zinc-200">
                                                        {form.data.iban || employee.iban || '—'}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="education" className="mt-6">
                                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Education qualifications
                                            <span className="ml-2 text-xs font-normal text-zinc-500">{education_qualifications.length} total</span>
                                        </h3>
                                        {can.education_manage && (
                                            <Button
                                                size="sm"
                                                className="h-8 gap-1.5 text-xs"
                                                type="button"
                                                onClick={() => {
                                                    educationForm.reset();
                                                    educationForm.clearErrors();
                                                    setEditingEducation(null);
                                                    setEducationDialogOpen(true);
                                                }}
                                            >
                                                + Add qualification
                                            </Button>
                                        )}
                                    </div>

                                    {education_qualifications.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">
                                            No qualifications recorded.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[680px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">Certificate</th>
                                                        <th className="py-2 pr-4">Issue date</th>
                                                        <th className="py-2 pr-4">University</th>
                                                        <th className="py-2 pr-4">Country</th>
                                                        {can.education_manage ? <th className="py-2 pr-4" /> : null}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {education_qualifications.map((row) => (
                                                        <tr key={row.id} className="text-sm text-zinc-200">
                                                            <td className="py-3 pr-4 font-medium">{row.certificate}</td>
                                                            <td className="py-3 pr-4 font-mono text-xs text-zinc-400">{row.issue_date ?? '—'}</td>
                                                            <td className="py-3 pr-4 text-zinc-300">{row.university ?? '—'}</td>
                                                            <td className="py-3 pr-4 text-xs text-zinc-400">{row.country_name ?? '—'}</td>
                                                            {can.education_manage ? (
                                                                <td className="py-3 pr-4">
                                                                    <div className="flex items-center gap-2">
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                                            onClick={() => {
                                                                                setEditingEducation(row);
                                                                                educationForm.setData({
                                                                                    certificate: row.certificate,
                                                                                    issue_date: row.issue_date ?? '',
                                                                                    university: row.university ?? '',
                                                                                    country_id: row.country_id ? String(row.country_id) : '',
                                                                                });
                                                                                educationForm.clearErrors();
                                                                                setEducationDialogOpen(true);
                                                                            }}
                                                                        >
                                                                            Edit
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                                            onClick={() => setDeleteEducationId(row.id)}
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            ) : null}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>

                                <Dialog
                                    open={educationDialogOpen}
                                    onOpenChange={(open) => {
                                        setEducationDialogOpen(open);

                                        if (!open) {
                                            educationForm.reset();
                                            educationForm.clearErrors();
                                            setEditingEducation(null);
                                        }
                                    }}
                                >
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>{editingEducation ? 'Edit qualification' : 'Add qualification'}</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-2">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Certificate</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.certificate}
                                                    onChange={(e) => educationForm.setData('certificate', e.target.value)}
                                                />
                                                {educationForm.errors.certificate ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.certificate}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Issue date</Label>
                                                <Input
                                                    type="date"
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.issue_date}
                                                    onChange={(e) => educationForm.setData('issue_date', e.target.value)}
                                                />
                                                {educationForm.errors.issue_date ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.issue_date}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">University / institution</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.university}
                                                    onChange={(e) => educationForm.setData('university', e.target.value)}
                                                />
                                                {educationForm.errors.university ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.university}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Country</Label>
                                                <select
                                                    value={educationForm.data.country_id}
                                                    onChange={(e) => educationForm.setData('country_id', e.target.value)}
                                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                                                >
                                                    <option value="">—</option>
                                                    {countries.map((c) => (
                                                        <option key={c.id} value={String(c.id)}>
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                {educationForm.errors.country_id ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.country_id}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" type="button" onClick={() => setEducationDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={educationForm.processing}
                                                onClick={() => {
                                                    educationForm.clearErrors();
                                                    educationForm.transform((data) => ({
                                                        certificate: data.certificate.trim(),
                                                        issue_date: data.issue_date === '' ? null : data.issue_date,
                                                        university: data.university.trim() === '' ? null : data.university.trim(),
                                                        country_id: data.country_id === '' ? null : Number(data.country_id),
                                                    }));

                                                    const url = editingEducation
                                                        ? update.url({
                                                            employee: employee.id,
                                                            qualification: editingEducation.id,
                                                        })
                                                        : store.url({ employee: employee.id });

                                                    if (editingEducation) {
                                                        educationForm.put(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setEducationDialogOpen(false);
                                                                educationForm.reset();
                                                                setEditingEducation(null);
                                                                toast.success('Qualification updated.');
                                                            },
                                                        });
                                                    } else {
                                                        educationForm.post(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setEducationDialogOpen(false);
                                                                educationForm.reset();
                                                                toast.success('Qualification added.');
                                                            },
                                                        });
                                                    }
                                                }}
                                            >
                                                {educationForm.processing ? 'Saving…' : 'Save'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <AlertDialog
                                    open={!!deleteEducationId}
                                    onOpenChange={(open) => {
                                        if (!open) {
                                            setDeleteEducationId(null);
                                        }
                                    }}
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Remove qualification?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This education record will be permanently removed.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                onClick={() => {
                                                    if (!deleteEducationId) {
                                                        return;
                                                    }

                                                    router.delete(
                                                        destroy.url({
                                                            employee: employee.id,
                                                            qualification: deleteEducationId,
                                                        }), {
                                                        preserveScroll: true,
                                                        onSuccess: () => {
                                                            setDeleteEducationId(null);
                                                            toast.success('Qualification removed.');
                                                        },
                                                    });
                                                }}
                                            >
                                                Remove
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </TabsContent>

                            <TabsContent value="documents" className="mt-6">
                                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                    <div className="mb-4 flex items-center justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Documents
                                            <span className="ml-2 text-xs font-normal text-zinc-500">{documents.length} total</span>
                                        </h3>
                                        {can.documents_upload && (
                                            <Button
                                                size="sm"
                                                className="h-8 gap-1.5 text-xs"
                                                onClick={() => {
                                                    uploadForm.reset();
                                                    setBulkFiles([]);
                                                    setUploadOpen(true);
                                                }}
                                            >
                                                + Upload Document
                                            </Button>
                                        )}
                                    </div>

                                    {documents.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">
                                            No documents uploaded.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[900px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">Type</th>
                                                        <th className="py-2 pr-4">Title</th>
                                                        <th className="py-2 pr-4">Number</th>
                                                        <th className="py-2 pr-4">Issue</th>
                                                        <th className="py-2 pr-4">Expiry</th>
                                                        <th className="py-2 pr-4">Status</th>
                                                        <th className="py-2 pr-4">Uploaded by</th>
                                                        <th className="py-2 pr-4">File</th>
                                                        {(can.documents_upload || can.documents_delete) && (
                                                            <th className="py-2 pr-4" />
                                                        )}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {documents.map((doc) => {
                                                        const statusColor = DOCUMENT_STATUS_CLASSES[doc.status ?? ''] ?? 'bg-white/5 text-zinc-400 border-white/10';

                                                        return (
                                                            <tr key={doc.id} className="text-sm text-zinc-200">
                                                                <td className="py-3 pr-4 text-zinc-400 text-xs">
                                                                    {doc.document_type_label ?? document_types.find(t => t.slug === doc.document_type || String(t.id) === doc.document_type)?.title ?? doc.document_type ?? doc.type ?? '—'}
                                                                    {doc.current_version && doc.current_version > 1 ? (
                                                                        <span className="ml-1 text-[10px] text-zinc-500">v{doc.current_version}</span>
                                                                    ) : null}
                                                                </td>
                                                                <td className="py-3 pr-4 font-medium">{doc.title || '—'}</td>
                                                                <td className="py-3 pr-4 text-zinc-400 font-mono text-xs">{doc.document_number || '—'}</td>
                                                                <td className="py-3 pr-4 text-zinc-400 text-xs">{doc.issue_date || '—'}</td>
                                                                <td className="py-3 pr-4 text-zinc-400 text-xs">{doc.expiry_date || '—'}</td>
                                                                <td className="py-3 pr-4">
                                                                    <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium capitalize ${statusColor}`}>
                                                                        {documentStatusLabel(doc.status)}
                                                                    </span>
                                                                </td>
                                                                <td className="py-3 pr-4 text-zinc-500 text-xs">{doc.uploaded_by || '—'}</td>
                                                                <td className="py-3 pr-4">
                                                                    <div className="flex gap-2">
                                                                        {doc.can_preview ? (
                                                                            <button type="button" onClick={() => setPreviewDoc(doc)} className="text-xs font-semibold text-primary hover:underline">
                                                                                Preview
                                                                            </button>
                                                                        ) : null}
                                                                        <a href={doc.file_url} target="_blank" rel="noreferrer" className="text-xs font-semibold text-zinc-400 hover:text-primary hover:underline">
                                                                            View
                                                                        </a>
                                                                    </div>
                                                                </td>
                                                                {(can.documents_upload || can.documents_delete) && (
                                                                    <td className="py-3 pr-4">
                                                                        <div className="flex items-center gap-2">
                                                                            {can.documents_upload && (
                                                                                <>
                                                                                <button
                                                                                    type="button"
                                                                                    className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                                                    onClick={() => setVersionDoc(doc)}
                                                                                >
                                                                                    Versions
                                                                                </button>
                                                                                <button
                                                                                    type="button"
                                                                                    className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                                                    onClick={() => {
                                                                                        replaceForm.reset();
                                                                                        setReplaceDoc(doc);
                                                                                    }}
                                                                                >
                                                                                    Replace
                                                                                </button>
                                                                                <button
                                                                                    type="button"
                                                                                    className="text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                                                                                    onClick={() => {
                                                                                        setEditDoc(doc);
                                                                                        editForm.setData({
                                                                                            title: doc.title ?? '',
                                                                                            document_number: doc.document_number ?? '',
                                                                                            issue_date: doc.issue_date ?? '',
                                                                                            expiry_date: doc.expiry_date ?? '',
                                                                                            notes: doc.notes ?? '',
                                                                                        });
                                                                                    }}
                                                                                >
                                                                                    Edit
                                                                                </button>
                                                                                </>
                                                                            )}
                                                                            {can.documents_delete && (
                                                                                <button
                                                                                    type="button"
                                                                                    className="text-xs text-red-400/60 hover:text-red-400 transition-colors"
                                                                                    onClick={() => setDeleteDocId(doc.id)}
                                                                                >
                                                                                    Delete
                                                                                </button>
                                                                            )}
                                                                        </div>
                                                                    </td>
                                                                )}
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>

                                <Dialog
                                    open={uploadOpen}
                                    onOpenChange={(open) => {
                                        setUploadOpen(open);

                                        if (!open) {
                                            resetUploadDialog();
                                        }
                                    }}
                                >
                                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                                        <DialogHeader>
                                            <DialogTitle>Upload Employee Documents</DialogTitle>
                                            <p className="text-sm text-muted-foreground">
                                                Add one or many files for {employee.name}. Shared details below will be applied to every selected file.
                                            </p>
                                        </DialogHeader>

                                        <div className="grid gap-5 py-2 lg:grid-cols-[1.1fr_0.9fr]">
                                            <div className="space-y-4">
                                                <div
                                                    onDragOver={(event) => {
                                                        event.preventDefault();
                                                        setIsDraggingFiles(true);
                                                    }}
                                                    onDragLeave={() => setIsDraggingFiles(false)}
                                                    onDrop={(event) => {
                                                        event.preventDefault();
                                                        setIsDraggingFiles(false);
                                                        addUploadFiles(Array.from(event.dataTransfer.files));
                                                    }}
                                                    className={`rounded-2xl border border-dashed p-6 transition-colors ${
                                                        isDraggingFiles
                                                            ? 'border-primary bg-primary/10'
                                                            : 'border-border bg-muted/20 hover:bg-muted/30'
                                                    }`}
                                                >
                                                    <div className="flex flex-col items-center gap-3 text-center">
                                                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                                            <UploadCloud className="h-6 w-6" />
                                                        </div>
                                                        <div>
                                                            <div className="text-sm font-semibold">Drag and drop files here</div>
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                Upload up to 20 files. Supported formats: PDF, JPG, JPEG, PNG. Max 20 MB each.
                                                            </div>
                                                        </div>
                                                        <label className="inline-flex cursor-pointer items-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90">
                                                            Browse files
                                                            <input
                                                                type="file"
                                                                accept=".pdf,.jpg,.jpeg,.png"
                                                                multiple
                                                                className="sr-only"
                                                                onChange={(event) => {
                                                                    addUploadFiles(Array.from(event.target.files ?? []));
                                                                    event.currentTarget.value = '';
                                                                }}
                                                            />
                                                        </label>
                                                        {uploadForm.errors.file ? (
                                                            <p className="text-xs text-destructive">{uploadForm.errors.file}</p>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                <div className="rounded-2xl border border-border bg-card/40">
                                                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                                                        <div>
                                                            <div className="text-sm font-semibold">Selected files</div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {bulkFiles.length} file(s), {formatFileSize(uploadFileSize)} total
                                                            </div>
                                                        </div>
                                                        {bulkFiles.length > 0 ? (
                                                            <Button variant="ghost" size="sm" onClick={() => {
                                                                setBulkFiles([]);
                                                                uploadForm.setData('file', null);
                                                            }}>
                                                                Clear
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                    <div className="max-h-56 space-y-2 overflow-y-auto p-3">
                                                        {bulkFiles.length === 0 ? (
                                                            <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                                                No files selected yet.
                                                            </div>
                                                        ) : (
                                                            bulkFiles.map((file, index) => (
                                                                <div key={`${file.name}-${file.size}-${file.lastModified}`} className="flex items-center justify-between gap-3 rounded-xl border border-border bg-background px-3 py-2">
                                                                    <div className="flex min-w-0 items-center gap-3">
                                                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                                            <FileText className="h-4 w-4" />
                                                                        </div>
                                                                        <div className="min-w-0">
                                                                            <div className="truncate text-sm font-medium">{file.name}</div>
                                                                            <div className="text-xs text-muted-foreground">
                                                                                {file.type || 'Unknown type'} · {formatFileSize(file.size)}
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <button
                                                                        type="button"
                                                                        className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                                        onClick={() => removeUploadFile(index)}
                                                                    >
                                                                        <X className="h-4 w-4" />
                                                                    </button>
                                                                </div>
                                                            ))
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="space-y-4 rounded-2xl border border-border bg-card/40 p-4">
                                                <div>
                                                    <div className="text-sm font-semibold">Document information</div>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        These values will be saved with every selected file. Leave title empty to use each file name.
                                                    </p>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Document Type <span className="text-destructive">*</span></Label>
                                                    <select
                                                        value={uploadForm.data.document_type}
                                                        onChange={(event) => uploadForm.setData('document_type', event.target.value)}
                                                        className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                                                    >
                                                        <option value="">Select type…</option>
                                                        {document_types.map((type) => (
                                                            <option key={type.id} value={type.slug}>{type.title}</option>
                                                        ))}
                                                    </select>
                                                    {uploadForm.errors.document_type ? (
                                                        <p className="text-xs text-destructive">{uploadForm.errors.document_type}</p>
                                                    ) : null}
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Title</Label>
                                                    <Input
                                                        className="h-10 text-sm"
                                                        placeholder="e.g. Passport Copy"
                                                        value={uploadForm.data.title}
                                                        onChange={(event) => uploadForm.setData('title', event.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Document Number</Label>
                                                    <Input
                                                        className="h-10 text-sm"
                                                        placeholder="e.g. A123456"
                                                        value={uploadForm.data.document_number}
                                                        onChange={(event) => uploadForm.setData('document_number', event.target.value)}
                                                    />
                                                </div>

                                                <div className="grid grid-cols-2 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label className="text-xs">Issue Date</Label>
                                                        <Input
                                                            type="date"
                                                            className="h-10 text-sm"
                                                            value={uploadForm.data.issue_date}
                                                            onChange={(event) => uploadForm.setData('issue_date', event.target.value)}
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label className="text-xs">Expiry Date</Label>
                                                        <Input
                                                            type="date"
                                                            className="h-10 text-sm"
                                                            value={uploadForm.data.expiry_date}
                                                            onChange={(event) => uploadForm.setData('expiry_date', event.target.value)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Notes</Label>
                                                    <textarea
                                                        rows={4}
                                                        className="w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary"
                                                        placeholder="Optional notes, renewal reminders, or source details…"
                                                        value={uploadForm.data.notes}
                                                        onChange={(event) => uploadForm.setData('notes', event.target.value)}
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        <DialogFooter className="items-center sm:justify-between">
                                            <div className="text-xs text-muted-foreground">
                                                {bulkFiles.length > 1 ? 'Bulk upload will create one document record per file.' : 'Select at least one file to upload.'}
                                            </div>
                                            <div className="flex gap-2">
                                                <Button variant="outline" size="sm" onClick={() => setUploadOpen(false)}>Cancel</Button>
                                                <Button
                                                    size="sm"
                                                    disabled={uploadForm.processing || bulkFiles.length === 0 || !uploadForm.data.document_type}
                                                    onClick={() => {
                                                        if (bulkFiles.length > 1) {
                                                            router.post(
                                                                `/organization/employees/${employee.id}/documents/bulk`,
                                                                {
                                                                    documents: bulkFiles.map((file) => ({
                                                                        document_type: uploadForm.data.document_type,
                                                                        title: uploadForm.data.title || file.name,
                                                                        file,
                                                                        document_number: uploadForm.data.document_number,
                                                                        issue_date: uploadForm.data.issue_date,
                                                                        expiry_date: uploadForm.data.expiry_date,
                                                                        notes: uploadForm.data.notes,
                                                                    })),
                                                                },
                                                                {
                                                                    forceFormData: true,
                                                                    onSuccess: () => {
                                                                        setUploadOpen(false);
                                                                        resetUploadDialog();
                                                                        toast.success('Documents uploaded.');
                                                                    },
                                                                },
                                                            );

                                                            return;
                                                        }

                                                        uploadForm.post(`/organization/employees/${employee.id}/documents`, {
                                                            forceFormData: true,
                                                            onSuccess: () => {
                                                                setUploadOpen(false);
                                                                resetUploadDialog();
                                                                toast.success('Document uploaded.');
                                                            },
                                                        });
                                                    }}
                                                >
                                                    {uploadForm.processing ? 'Uploading…' : `Upload ${bulkFiles.length || ''}`.trim()}
                                                </Button>
                                            </div>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                {/* Edit Dialog */}
                                <Dialog open={!!editDoc} onOpenChange={open => {
 if (!open) {
setEditDoc(null);
} 
}}>
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>Edit Document</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-2">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Title</Label>
                                                <Input className="h-9 text-sm" value={editForm.data.title} onChange={e => editForm.setData('title', e.target.value)} />
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Document Number</Label>
                                                    <Input className="h-9 text-sm" value={editForm.data.document_number} onChange={e => editForm.setData('document_number', e.target.value)} />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Issue Date</Label>
                                                    <Input type="date" className="h-9 text-sm" value={editForm.data.issue_date} onChange={e => editForm.setData('issue_date', e.target.value)} />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Expiry Date</Label>
                                                    <Input type="date" className="h-9 text-sm" value={editForm.data.expiry_date} onChange={e => editForm.setData('expiry_date', e.target.value)} />
                                                </div>
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Notes</Label>
                                                <textarea
                                                    rows={2}
                                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary resize-none"
                                                    value={editForm.data.notes}
                                                    onChange={e => editForm.setData('notes', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" onClick={() => setEditDoc(null)}>Cancel</Button>
                                            <Button
                                                size="sm"
                                                disabled={editForm.processing}
                                                onClick={() => {
                                                    if (!editDoc) {
return;
}

                                                    editForm.put(`/organization/employees/${employee.id}/documents/${editDoc.id}`, {
                                                        onSuccess: () => {
                                                            setEditDoc(null);
                                                            toast.success('Document updated.');
                                                        },
                                                    });
                                                }}
                                            >
                                                {editForm.processing ? 'Saving…' : 'Save Changes'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <Dialog open={!!replaceDoc} onOpenChange={open => {
 if (!open) {
setReplaceDoc(null);
} 
}}>
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>Replace Document File</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-3 py-2">
                                            <p className="text-sm text-muted-foreground">
                                                The current file will be kept in version history.
                                            </p>
                                            <input
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={e => replaceForm.setData('file', e.target.files?.[0] ?? null)}
                                                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                            />
                                            {replaceForm.errors.file && <p className="text-xs text-destructive">{replaceForm.errors.file}</p>}
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" onClick={() => setReplaceDoc(null)}>Cancel</Button>
                                            <Button
                                                size="sm"
                                                disabled={replaceForm.processing}
                                                onClick={() => {
                                                    if (!replaceDoc) {
return;
}

                                                    replaceForm.post(`/organization/employees/${employee.id}/documents/${replaceDoc.id}/replace`, {
                                                        forceFormData: true,
                                                        onSuccess: () => {
                                                            setReplaceDoc(null);
                                                            replaceForm.reset();
                                                            toast.success('Document file replaced.');
                                                        },
                                                    });
                                                }}
                                            >
                                                {replaceForm.processing ? 'Replacing…' : 'Replace'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <Dialog open={!!versionDoc} onOpenChange={open => {
 if (!open) {
setVersionDoc(null);
} 
}}>
                                    <DialogContent className="sm:max-w-lg">
                                        <DialogHeader>
                                            <DialogTitle>Version History</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-3 py-2">
                                            <div className="rounded-lg border border-border p-3">
                                                <div className="text-sm font-medium">Current version v{versionDoc?.current_version ?? 1}</div>
                                                <div className="text-xs text-muted-foreground">{versionDoc?.original_filename ?? 'Current file'}</div>
                                            </div>
                                            {versionDoc?.versions.length ? (
                                                <div className="space-y-2">
                                                    {versionDoc.versions.map((version) => (
                                                        <div key={version.id} className="flex items-center justify-between rounded-lg border border-border p-3">
                                                            <div>
                                                                <div className="text-sm font-medium">Version v{version.version}</div>
                                                                <div className="text-xs text-muted-foreground">
                                                                    {[version.original_filename, version.replaced_by ? `replaced by ${version.replaced_by}` : null].filter(Boolean).join(' · ')}
                                                                </div>
                                                            </div>
                                                            <a href={version.file_url} target="_blank" rel="noreferrer" className="text-xs font-medium text-primary hover:underline">
                                                                View
                                                            </a>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <p className="text-sm text-muted-foreground">No previous versions yet.</p>
                                            )}
                                        </div>
                                    </DialogContent>
                                </Dialog>

                                <DocumentPreviewDialog document={previewDoc} onOpenChange={(open) => !open && setPreviewDoc(null)} />

                                {/* Delete Confirmation */}
                                <AlertDialog open={!!deleteDocId} onOpenChange={open => {
 if (!open) {
setDeleteDocId(null);
} 
}}>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Delete document?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                The file and all metadata will be permanently removed. This cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                onClick={() => {
                                                    if (!deleteDocId) {
return;
}

                                                    router.delete(`/organization/employees/${employee.id}/documents/${deleteDocId}`, {
                                                        onSuccess: () => {
                                                            setDeleteDocId(null);
                                                            toast.success('Document deleted.');
                                                        },
                                                    });
                                                }}
                                            >
                                                Delete
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </TabsContent>
                        </Tabs>
                    </div>
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
