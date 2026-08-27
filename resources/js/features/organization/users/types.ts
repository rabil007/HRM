export type Company = {
    id: number;
    name: string;
};

export interface UserInvitation {
    id: number;
    email: string;
    name: string | null;
    role: { id: number; name: string } | null;
    expires_at: string;
    last_sent_at: string | null;
    created_at: string;
}

export type LinkedEmployee = {
    id: number;
    name: string;
    employee_no: string;
    image_url: string | null;
};

export type EmployeeForLinking = LinkedEmployee & {
    user_id: number | null;
};

export type UserDirectorySummary = {
    total: number;
    online: number;
    never: number;
    pending_invites: number;
};

export type UserCapabilities = {
    can_edit_global_identity: boolean;
    can_delete_global_identity: boolean;
    can_password_reset: boolean;
    can_revoke_sessions: boolean;
    can_manage_membership: boolean;
};

export type User = {
    id: number;
    company: { id: number; name: string | null } | null;
    role: { id: number; name: string | null } | null;
    name: string;
    email: string;
    avatar: string | null;
    status: 'active' | 'inactive' | 'suspended';
    last_login_at?: string | null;
    last_active_at?: string | null;
    presence?: 'online' | 'recent' | 'offline' | 'never';
    two_factor_enabled?: boolean;
    created_at?: string;
    linked_employee?: LinkedEmployee | null;
    capabilities?: UserCapabilities;
};

export type UserFormData = {
    name: string;
    email: string;
    avatar: File | null;
    use_employee_avatar: boolean;
    employee_id: number | '';
    role_id: number | '';
    status: 'active' | 'inactive' | 'suspended';
};
