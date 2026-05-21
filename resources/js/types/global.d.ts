import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            settings: {
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
            [key: string]: unknown;
        };
    }
}
