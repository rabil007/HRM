export type DocumentsModuleSection =
    | 'overview'
    | 'library'
    | 'generate'
    | 'requests'
    | 'templates'
    | 'activity';

export const DOCUMENTS_MODULE_PATHS: Record<DocumentsModuleSection, string> = {
    overview: '/organization/documents',
    library: '/organization/documents/library',
    generate: '/organization/documents/generate',
    requests: '/organization/documents/requests',
    templates: '/organization/documents/templates',
    activity: '/organization/documents/activity',
};

export const DOCUMENTS_MODULE_LABELS: Record<DocumentsModuleSection, string> = {
    overview: 'Overview',
    library: 'Library',
    generate: 'Generate & Send',
    requests: 'Requests',
    templates: 'Templates',
    activity: 'Activity',
};

const DOCUMENTS_MODULE_ORDER: DocumentsModuleSection[] = [
    'overview',
    'library',
    'generate',
    'requests',
    'templates',
    'activity',
];

function normalizePath(url: string): { path: string; search: string } {
    const [rawPath = url, search = ''] = url.split('?');
    const path =
        rawPath.length > 1 && rawPath.endsWith('/')
            ? rawPath.slice(0, -1)
            : rawPath;

    return { path, search };
}

export function documentsModuleIndexPath(
    section: Extract<DocumentsModuleSection, 'overview' | 'library'>,
): string {
    return DOCUMENTS_MODULE_PATHS[section];
}

export function documentsShowBackFromSection(
    section: Extract<DocumentsModuleSection, 'overview' | 'library'>,
): 'index' | 'library' {
    return section === 'library' ? 'library' : 'index';
}

export function documentsModuleSectionFromUrl(
    url: string,
): DocumentsModuleSection | null {
    const { path, search } = normalizePath(url);
    const view = new URLSearchParams(search).get('view');

    if (path === DOCUMENTS_MODULE_PATHS.overview) {
        return 'overview';
    }

    if (path === DOCUMENTS_MODULE_PATHS.library) {
        return 'library';
    }

    if (path.startsWith('/organization/documents/employees')) {
        return 'library';
    }

    if (path === DOCUMENTS_MODULE_PATHS.templates) {
        return 'templates';
    }

    if (path === DOCUMENTS_MODULE_PATHS.generate) {
        return 'generate';
    }

    if (path === DOCUMENTS_MODULE_PATHS.requests) {
        return 'requests';
    }

    if (path === DOCUMENTS_MODULE_PATHS.activity) {
        return 'activity';
    }

    if (
        path === '/organization/documents/bulk' ||
        path.startsWith('/organization/documents/bulk/')
    ) {
        if (view === 'signatures') {
            return 'requests';
        }

        if (view === 'history') {
            return 'activity';
        }

        return 'generate';
    }

    return null;
}

export function isDocumentsModuleNavUrlActive(
    href: string,
    itemUrl: string,
): boolean | null {
    const itemSection = documentsModuleSectionFromUrl(itemUrl);

    if (itemSection === null) {
        return null;
    }

    return documentsModuleSectionFromUrl(href) === itemSection;
}

export function canViewDocumentsModuleSection(
    section: DocumentsModuleSection,
    permissions: string[],
    platformView = false,
): boolean {
    if (section === 'overview' || section === 'library') {
        return permissions.includes('documents.view');
    }

    if (
        section === 'generate' ||
        section === 'requests' ||
        section === 'activity'
    ) {
        return permissions.includes('bulk_documents.view');
    }

    return (
        permissions.includes('bulk_documents.view') ||
        permissions.includes('settings.master-data.document-types.view') ||
        platformView
    );
}

export function visibleDocumentsModuleSections(
    permissions: string[],
    platformView = false,
): DocumentsModuleSection[] {
    return DOCUMENTS_MODULE_ORDER.filter((section) =>
        canViewDocumentsModuleSection(section, permissions, platformView),
    );
}
