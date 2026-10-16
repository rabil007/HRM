export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type PlatformAccess = {
    view: boolean;
    manage: boolean;
    database: boolean;
};

export type TwoFactorStatus = {
    enabled: boolean;
    required_for_privileged_actions: boolean;
};

export type ImpersonationData = {
    is_impersonating: boolean;
    original_user: {
        id: number;
        name: string;
        email: string;
    };
    target_user: {
        id: number;
        name: string;
        email: string;
        role_name?: string | null;
    };
    time_remaining_seconds: number;
};

export type Auth = {
    user: User | null;
    permissions?: string[];
    roles?: string[];
    platform?: PlatformAccess;
    two_factor?: TwoFactorStatus;
    impersonation?: ImpersonationData | null;
    my_tasks_count?: number;
    leave_approvals_count?: number;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
