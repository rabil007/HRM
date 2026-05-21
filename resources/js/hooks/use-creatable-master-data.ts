import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    creatableRegistry,
    type CreatableMasterDataContext,
    type CreatableMasterDataKey,
} from '@/lib/master-data/creatable-registry';
import { postQuickCreate } from '@/lib/master-data/quick-create-post';

export type QuickCreateResult = {
    id: number | string;
    label: string;
};

export type UseCreatableMasterDataReturn = {
    canCreate: boolean;
    createConfig: {
        submit: (query: string) => Promise<QuickCreateResult>;
    };
};

export function useCreatableMasterData(
    key: CreatableMasterDataKey,
    context?: CreatableMasterDataContext,
): UseCreatableMasterDataReturn {
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const entry = creatableRegistry[key];
    const permissions = auth?.permissions ?? [];
    const canCreate = permissions.includes(entry.permission);

    const createConfig = useMemo(
        () => ({
            submit: async (query: string): Promise<QuickCreateResult> => {
                const payload = await postQuickCreate(
                    entry.url(),
                    entry.body(query.trim(), context),
                );

                const label =
                    payload.label ??
                    payload.name ??
                    payload.title ??
                    query.trim();

                return {
                    id: payload.id,
                    label,
                };
            },
        }),
        [context?.departmentId, entry],
    );

    return {
        canCreate,
        createConfig,
    };
}
