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

export type Auth = {
    user: User;
    permissions?: string[];
    roles?: string[];
    platform?: PlatformAccess;
    two_factor?: TwoFactorStatus;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
