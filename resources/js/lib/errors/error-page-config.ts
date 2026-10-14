import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    Ban,
    Clock,
    FileQuestion,
    LogIn,
    ServerCrash,
    ShieldAlert,
    WifiOff,
} from 'lucide-react';

export type ErrorPageStatus = 400 | 401 | 403 | 404 | 419 | 429 | 500 | 503;

export type ErrorPageActionId =
    | 'back'
    | 'dashboard'
    | 'home'
    | 'login'
    | 'refresh'
    | 'retry';

export type ErrorPageConfig = {
    status: ErrorPageStatus;
    title: string;
    heading: string;
    description: string;
    icon: LucideIcon;
    actions: ErrorPageActionId[];
    retryEligible: boolean;
    statusLabel: string;
};

const configs: Record<ErrorPageStatus, ErrorPageConfig> = {
    400: {
        status: 400,
        title: 'Bad request',
        heading: 'Request could not be processed',
        description:
            'Something about this request was invalid. Go back and try again, or return to a safe page.',
        icon: AlertTriangle,
        actions: ['back', 'dashboard'],
        retryEligible: false,
        statusLabel: '400 — Bad request',
    },
    401: {
        status: 401,
        title: 'Sign in required',
        heading: 'You need to sign in',
        description:
            'Your session may have ended or this page requires authentication. Sign in to continue.',
        icon: LogIn,
        actions: ['login', 'home'],
        retryEligible: false,
        statusLabel: '401 — Unauthorized',
    },
    403: {
        status: 403,
        title: 'Forbidden',
        heading: 'Access denied',
        description:
            'You do not have permission to view this page. If you believe this is a mistake, contact your administrator.',
        icon: ShieldAlert,
        actions: ['dashboard', 'back'],
        retryEligible: false,
        statusLabel: '403 — Forbidden',
    },
    404: {
        status: 404,
        title: 'Page not found',
        heading: 'Page not found',
        description:
            'The page you requested does not exist or is no longer available.',
        icon: FileQuestion,
        actions: ['dashboard', 'back'],
        retryEligible: false,
        statusLabel: '404 — Not found',
    },
    419: {
        status: 419,
        title: 'Session expired',
        heading: 'Your session expired',
        description:
            'For your security, this page timed out. Refresh to continue. Unsaved changes on other tabs are not automatically resubmitted.',
        icon: Clock,
        actions: ['refresh', 'login'],
        retryEligible: false,
        statusLabel: '419 — Session expired',
    },
    429: {
        status: 429,
        title: 'Too many requests',
        heading: 'Slow down a moment',
        description:
            'You have made too many requests in a short time. Wait a moment before trying again.',
        icon: Ban,
        actions: ['retry', 'dashboard'],
        retryEligible: true,
        statusLabel: '429 — Too many requests',
    },
    500: {
        status: 500,
        title: 'Server error',
        heading: 'Something went wrong',
        description:
            'Something went wrong while processing your request. Please try again later.',
        icon: ServerCrash,
        actions: ['retry', 'dashboard'],
        retryEligible: true,
        statusLabel: '500 — Server error',
    },
    503: {
        status: 503,
        title: 'Service unavailable',
        heading: 'Temporarily unavailable',
        description:
            'OMS-HRM is temporarily unavailable, often during maintenance. Please try again shortly.',
        icon: WifiOff,
        actions: ['retry', 'dashboard'],
        retryEligible: true,
        statusLabel: '503 — Service unavailable',
    },
};

export function getErrorPageConfig(status: ErrorPageStatus): ErrorPageConfig {
    return configs[status];
}

export function isErrorPageStatus(value: number): value is ErrorPageStatus {
    return Object.prototype.hasOwnProperty.call(configs, value);
}

export const supportedErrorPageStatuses = Object.keys(configs).map(
    Number,
) as ErrorPageStatus[];
