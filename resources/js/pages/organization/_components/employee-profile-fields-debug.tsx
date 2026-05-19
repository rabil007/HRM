import type { ProfileFieldsDebugPayload } from '@/pages/organization/employee-page.types';

const DETAILS_FIELD_KEYS = [
    'work_email',
    'phone',
    'marital_status',
    'date_of_birth',
    'rank_id',
    'place_of_birth',
    'gender_id',
    'religion_id',
] as const;

function wouldShowField(
    key: string,
    profileFields: string[] | null | undefined,
): boolean {
    return !profileFields || profileFields.includes(key);
}

export function EmployeeProfileFieldsDebug({
    profileFields,
    serverDebug,
    clientMarker,
}: {
    profileFields: string[] | null | undefined;
    serverDebug: ProfileFieldsDebugPayload | null | undefined;
    clientMarker: string;
}) {
    const fieldVisibility = DETAILS_FIELD_KEYS.map((key) => ({
        key,
        visible: wouldShowField(key, profileFields),
    }));

    return (
        <div className="rounded-2xl border-2 border-amber-500/40 bg-amber-950/40 p-4 font-mono text-xs text-amber-100 shadow-lg">
            <p className="mb-3 text-sm font-bold uppercase tracking-wider text-amber-300">
                Profile fields debug (remove after fixing production)
            </p>

            <div className="mb-3 grid gap-2 sm:grid-cols-2">
                <div>
                    <span className="text-amber-500/80">Client bundle:</span>{' '}
                    {clientMarker}
                </div>
                <div>
                    <span className="text-amber-500/80">Server marker:</span>{' '}
                    {serverDebug?.marker ?? '— (add ?debug_fields=1 to URL)'}
                </div>
            </div>

            <p className="mb-2 font-semibold text-amber-200">
                Details grid — field visibility (client)
            </p>
            <ul className="mb-4 space-y-1">
                {fieldVisibility.map(({ key, visible }) => (
                    <li key={key}>
                        <span
                            className={
                                visible ? 'text-emerald-400' : 'text-red-400'
                            }
                        >
                            {visible ? 'SHOW' : 'HIDE'}
                        </span>{' '}
                        {key}
                    </li>
                ))}
            </ul>

            <p className="mb-1 font-semibold text-amber-200">
                employee_tabs.profile_fields (from page props)
            </p>
            <pre className="mb-4 max-h-40 overflow-auto rounded-lg bg-black/40 p-3 text-[11px] leading-relaxed">
                {profileFields === null
                    ? 'null → all Details fields shown (no template filter)'
                    : JSON.stringify(profileFields, null, 2)}
            </pre>

            {serverDebug ? (
                <>
                    <p className="mb-1 font-semibold text-amber-200">
                        profile_fields_debug (server)
                    </p>
                    <pre className="max-h-64 overflow-auto rounded-lg bg-black/40 p-3 text-[11px] leading-relaxed">
                        {JSON.stringify(serverDebug, null, 2)}
                    </pre>
                </>
            ) : (
                <p className="text-amber-400/90">
                    Server debug payload missing — PHP may be outdated, or URL is
                    missing{' '}
                    <code className="text-amber-200">?debug_fields=1</code>
                </p>
            )}
        </div>
    );
}
