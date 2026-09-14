<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionPerson;
use Illuminate\Support\Collection;

final class ResolveHikvisionPersonFromAcsEvent
{
    /**
     * Resolve the company-scoped Hikvision person for an authoritative ACS/ISAPI door event.
     *
     * Priority:
     * 1. Explicit Hikvision personId from the ACS payload (when present)
     * 2. Unique company-scoped person_code match via employeeNoString / employeeNo
     * 3. Unique exact full_name match (case-insensitive)
     * 4. Unique safe short trailing-initial alias (e.g. "Mohammed Rabil" ↔ "Mohammed Rabil T")
     *
     * Ambiguous matches never attach a person. Lookups never cross companies.
     *
     * @param  array<string, mixed>  $acsEvent
     * @return array{person_hikvision_id: string, hikvision_person_id: int|null}
     */
    public static function resolve(int $companyId, array $acsEvent): array
    {
        $empty = [
            'person_hikvision_id' => '',
            'hikvision_person_id' => null,
        ];

        if ($companyId <= 0) {
            return $empty;
        }

        $person = self::findPerson($companyId, $acsEvent);

        if ($person === null) {
            return $empty;
        }

        $personHikvisionId = trim((string) ($person->person_id ?? ''));

        if ($personHikvisionId === '') {
            return $empty;
        }

        return [
            'person_hikvision_id' => $personHikvisionId,
            'hikvision_person_id' => (int) $person->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    private static function findPerson(int $companyId, array $acsEvent): ?HikvisionPerson
    {
        $explicitPersonId = self::extractExplicitPersonId($acsEvent);

        if ($explicitPersonId !== '') {
            $byId = HikvisionPerson::query()
                ->forCompany($companyId)
                ->where('person_id', $explicitPersonId)
                ->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        $employeeNo = self::extractEmployeeNo($acsEvent);

        if ($employeeNo !== '') {
            $byCode = HikvisionPerson::query()
                ->forCompany($companyId)
                ->where('person_code', $employeeNo)
                ->limit(2)
                ->get();

            if ($byCode->count() === 1) {
                return $byCode->first();
            }
        }

        $personName = trim((string) ($acsEvent['name'] ?? ''));

        if ($personName === '') {
            return null;
        }

        return self::findUniqueByName($companyId, $personName);
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    private static function extractExplicitPersonId(array $acsEvent): string
    {
        $personInfo = is_array($acsEvent['personInfo'] ?? null) ? $acsEvent['personInfo'] : [];

        foreach ([
            $acsEvent['personId'] ?? null,
            $acsEvent['personID'] ?? null,
            $personInfo['personId'] ?? null,
            $personInfo['personID'] ?? null,
            $personInfo['id'] ?? null,
        ] as $candidate) {
            $value = trim((string) ($candidate ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $acsEvent
     */
    private static function extractEmployeeNo(array $acsEvent): string
    {
        foreach (['employeeNoString', 'employeeNo'] as $key) {
            $value = trim((string) ($acsEvent[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function findUniqueByName(int $companyId, string $personName): ?HikvisionPerson
    {
        $normalized = mb_strtolower($personName);

        $exact = HikvisionPerson::query()
            ->forCompany($companyId)
            ->whereRaw('LOWER(full_name) = ?', [$normalized])
            ->limit(2)
            ->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        if ($exact->count() > 1) {
            return null;
        }

        /** @var Collection<int, HikvisionPerson> $candidates */
        $candidates = HikvisionPerson::query()
            ->forCompany($companyId)
            ->whereNotNull('full_name')
            ->where('full_name', '!=', '')
            ->whereRaw('LOWER(full_name) LIKE ?', [$normalized.' %'])
            ->get()
            ->filter(function (HikvisionPerson $person) use ($normalized): bool {
                $aliases = array_map(
                    mb_strtolower(...),
                    HikvisionPersonNameAliases::forPerson($person),
                );

                return in_array($normalized, $aliases, true);
            })
            ->values();

        if ($candidates->count() !== 1) {
            return null;
        }

        return $candidates->first();
    }
}
