import type { PropsWithChildren } from 'react';

export default function SettingsLayout({ children }: PropsWithChildren) {
    return (
        <div className="px-4 py-6">
            <div className="flex-1">
                <section className="space-y-12">{children}</section>
            </div>
        </div>
    );
}
