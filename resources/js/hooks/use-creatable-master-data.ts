import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { creatableRegistry } from '@/lib/master-data/creatable-registry';
import type {
    CreatableMasterDataContext,
    CreatableMasterDataKey,
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

    const createConfig = useMemo(() => {
        const registryEntry = creatableRegistry[key];

        return {
            submit: async (query: string): Promise<QuickCreateResult> => {
                const payload = await postQuickCreate(
                    registryEntry.url(),
                    registryEntry.body(query.trim(), context),
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
        };
    }, [key, context]);

    return {
        canCreate,
        createConfig,
    };
}
