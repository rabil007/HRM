import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            settings: {
                platform: {
                    app_name: string;
                    support_email: string;
                    support_phone: string;
                    fallback_timezone: string;
                    default_date_format: string;
                    branding: {
                        main_logo_url: string | null;
                        sidebar_logo_url: string | null;
                        login_logo_url: string | null;
                        favicon_url: string | null;
                        login_background_url: string | null;
                        email_branding_logo_url: string | null;
                    };
                    preferences: {
                        primary_color: string;
                        accent_color: string;
                        sidebar_compact_default: boolean;
                    };
                };
                company: {
                    id: number;
                    name: string;
                    email: string | null;
                    phone: string | null;
                    address: string | null;
                    website: string | null;
                    timezone: string;
                    currency: { code: string; symbol: string | null } | null;
                    logo_url: string | null;
                } | null;
                app_name: string;
                company_name: string;
                support_email: string;
                support_phone: string;
                company_address: string;
                timezone: string;
                currency: string;
                date_format: string;
                branding: {
                    main_logo_url: string | null;
                    sidebar_logo_url: string | null;
                    login_logo_url: string | null;
                    favicon_url: string | null;
                    login_background_url: string | null;
                    email_branding_logo_url: string | null;
                };
                preferences: {
                    primary_color: string;
                    accent_color: string;
                    sidebar_compact_default: boolean;
                };
            };
            auth: Auth;
            sidebarOpen: boolean;
            sidebarStateSet: boolean;
            [key: string]: unknown;
        };
    }
}
