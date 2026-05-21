import { store as storeBank } from '@/actions/App/Http/Controllers/Settings/MasterData/BankController';
import { store as storeClient } from '@/actions/App/Http/Controllers/Settings/MasterData/ClientController';
import { store as storeCourse } from '@/actions/App/Http/Controllers/Settings/MasterData/CourseController';
import { store as storeDocumentType } from '@/actions/App/Http/Controllers/Settings/MasterData/DocumentTypeController';
import { store as storeGender } from '@/actions/App/Http/Controllers/Settings/MasterData/GenderController';
import { store as storeRank } from '@/actions/App/Http/Controllers/Settings/MasterData/RankController';
import { store as storeReligion } from '@/actions/App/Http/Controllers/Settings/MasterData/ReligionController';
import { store as storeVesselType } from '@/actions/App/Http/Controllers/Settings/MasterData/VesselTypeController';
import { store as storeVisaType } from '@/actions/App/Http/Controllers/Settings/MasterData/VisaTypeController';
import { store as storeDepartment } from '@/actions/App/Http/Controllers/Organization/DepartmentController';
import { store as storePosition } from '@/actions/App/Http/Controllers/Organization/PositionController';

export type CreatableMasterDataKey =
    | 'bank'
    | 'visaType'
    | 'religion'
    | 'gender'
    | 'course'
    | 'rank'
    | 'client'
    | 'vesselType'
    | 'documentType'
    | 'department'
    | 'position';

export type CreatableMasterDataContext = {
    departmentId?: string | number | null;
};

type CreatableRegistryEntry = {
    permission: string;
    labelField: 'name' | 'title';
    url: () => string;
    body: (query: string, context?: CreatableMasterDataContext) => Record<string, unknown>;
};

export const creatableRegistry: Record<CreatableMasterDataKey, CreatableRegistryEntry> = {
    bank: {
        permission: 'settings.master-data.banks.create',
        labelField: 'name',
        url: () => storeBank.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    visaType: {
        permission: 'settings.master-data.visa-types.create',
        labelField: 'name',
        url: () => storeVisaType.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    religion: {
        permission: 'settings.master-data.religions.create',
        labelField: 'name',
        url: () => storeReligion.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    gender: {
        permission: 'settings.master-data.genders.create',
        labelField: 'name',
        url: () => storeGender.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    course: {
        permission: 'settings.master-data.courses.create',
        labelField: 'name',
        url: () => storeCourse.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    rank: {
        permission: 'settings.master-data.ranks.create',
        labelField: 'name',
        url: () => storeRank.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    client: {
        permission: 'settings.master-data.clients.create',
        labelField: 'name',
        url: () => storeClient.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    vesselType: {
        permission: 'settings.master-data.vessel-types.create',
        labelField: 'name',
        url: () => storeVesselType.url(),
        body: (query) => ({ name: query, is_active: true }),
    },
    documentType: {
        permission: 'settings.master-data.document-types.create',
        labelField: 'title',
        url: () => storeDocumentType.url(),
        body: (query) => ({ title: query, is_active: true }),
    },
    department: {
        permission: 'departments.create',
        labelField: 'name',
        url: () => storeDepartment.url(),
        body: (query) => ({ name: query, status: 'active' }),
    },
    position: {
        permission: 'positions.create',
        labelField: 'title',
        url: () => storePosition.url(),
        body: (query, context) => {
            const body: Record<string, unknown> = {
                title: query,
                status: 'active',
            };

            if (context?.departmentId) {
                body.department_id = Number(context.departmentId);
            }

            return body;
        },
    },
};
