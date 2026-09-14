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
     * 4. Unique safe trailing-initial alias (e.g. "Mohammed Rabil" ↔ "Mohammed Rabil T" / "T.")
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
     * Resolve a legacy unlinked event identity against already-loaded company people.
     *
     * Priority matches ACS import fallback (no payload personId): unique person_code, then unique
     * exact full_name, then unique safe trailing-initial alias. Ambiguous matches return null.
     * $people must already be scoped to the active company.
     *
     * @param  Collection<int, HikvisionPerson>  $people
     */
    public static function uniquePersonFromUnlinkedIdentity(
        Collection $people,
        string $personName,
        string $personCode = '',
    ): ?HikvisionPerson {
        $byCode = self::uniquePersonFromCodeAmong($people, $personCode);

        if ($byCode !== null) {
            return $byCode;
        }

        return self::uniquePersonFromNameAmong($people, $personName);
    }

    /**
     * @param  Collection<int, HikvisionPerson>  $people
     */
    public static function uniquePersonFromCodeAmong(Collection $people, string $personCode): ?HikvisionPerson
    {
        $personCode = trim($personCode);

        if ($personCode === '') {
            return null;
        }

        $matches = $people
            ->filter(fn (HikvisionPerson $person): bool => trim((string) ($person->person_code ?? '')) === $personCode)
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * @param  Collection<int, HikvisionPerson>  $people
     */
    public static function uniquePersonFromNameAmong(Collection $people, string $personName): ?HikvisionPerson
    {
        $normalized = mb_strtolower(trim($personName));

        if ($normalized === '') {
            return null;
        }

        $exact = $people
            ->filter(fn (HikvisionPerson $person): bool => mb_strtolower(trim((string) ($person->full_name ?? ''))) === $normalized)
            ->values();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        if ($exact->count() > 1) {
            return null;
        }

        $aliased = $people
            ->filter(function (HikvisionPerson $person) use ($normalized): bool {
                $aliases = array_map(
                    mb_strtolower(...),
                    HikvisionPersonNameAliases::forPerson($person),
                );

                return in_array($normalized, $aliases, true);
            })
            ->values();

        if ($aliased->count() !== 1) {
            return null;
        }

        return $aliased->first();
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
            ->get();

        return self::uniquePersonFromNameAmong($candidates, $personName);
    }
}
