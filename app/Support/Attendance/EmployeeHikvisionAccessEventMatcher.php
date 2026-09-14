<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Support\Hikvision\HikvisionPersonNameAliases;
use App\Support\Hikvision\ResolveHikvisionPersonFromAcsEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Company-scoped matching of trusted Hikvision access events to a linked employee.
 *
 * Prefer person_hikvision_id. Fallback matching for legacy unlinked ACS/mobile rows uses the
 * same unique identity rules as ACS import (unique person_code, then unique exact/safe name alias).
 * Ambiguous unlinked identities stay unresolved and are never guessed.
 */
final class EmployeeHikvisionAccessEventMatcher
{
    /**
     * @param  Builder<HikvisionAccessEvent>  $query
     * @return Builder<HikvisionAccessEvent>
     */
    public static function scopeForEmployee(Builder $query, Employee $employee): Builder
    {
        $personId = trim((string) ($employee->hikvisionPerson?->person_id ?? ''));
        $companyPeople = self::companyPeople((int) $employee->company_id);
        [$aliases, $personCodes] = self::uniqueUnlinkedIdentityKeys($employee, $companyPeople);

        return $query->where(function (Builder $match) use ($personId, $aliases, $personCodes): void {
            $hasConstraint = false;

            if ($personId !== '') {
                $match->where('person_hikvision_id', $personId);
                $hasConstraint = true;
            }

            if ($aliases === [] && $personCodes === []) {
                if (! $hasConstraint) {
                    $match->whereRaw('1 = 0');
                }

                return;
            }

            $unlinked = function (Builder $query) use ($aliases, $personCodes): void {
                $query
                    ->where(function (Builder $unlinkedIdentity): void {
                        $unlinkedIdentity
                            ->whereNull('person_hikvision_id')
                            ->orWhere('person_hikvision_id', '');
                    })
                    ->where(function (Builder $identityMatch) use ($aliases, $personCodes): void {
                        self::applyUnlinkedIdentityConstraints($identityMatch, $aliases, $personCodes);
                    });
            };

            if ($hasConstraint) {
                $match->orWhere($unlinked);
            } else {
                $match->where($unlinked);
            }
        });
    }

    /**
     * @param  Collection<string, Collection<int, HikvisionAccessEvent>>  $eventsByPersonId
     * @param  Collection<int, Collection<int, HikvisionAccessEvent>>  $unlinkedEventsByResolvedPersonId
     * @return Collection<int, HikvisionAccessEvent>
     */
    public static function resolveFromLoadedEvents(
        Employee $employee,
        Collection $eventsByPersonId,
        Collection $unlinkedEventsByResolvedPersonId,
    ): Collection {
        $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');
        $events = $personId !== ''
            ? $eventsByPersonId->get($personId, collect())
            : collect();

        $resolvedPersonKey = (int) ($employee->hikvision_person_id ?? 0);
        $unlinkedMatches = $resolvedPersonKey > 0
            ? $unlinkedEventsByResolvedPersonId->get($resolvedPersonKey, collect())
            : collect();

        return $events
            ->merge($unlinkedMatches)
            ->unique('id')
            ->values();
    }

    /**
     * Resolve each unlinked event at most once against company-scoped Hikvision people.
     *
     * @param  Collection<int, HikvisionAccessEvent>  $companyEvents
     * @param  Collection<int, HikvisionPerson>  $companyPeople
     * @return Collection<int, Collection<int, HikvisionAccessEvent>>
     */
    public static function indexUnlinkedEventsByResolvedPersonId(
        Collection $companyEvents,
        Collection $companyPeople,
    ): Collection {
        /** @var array<int, list<HikvisionAccessEvent>> $grouped */
        $grouped = [];

        foreach ($companyEvents as $event) {
            if (filled($event->person_hikvision_id)) {
                continue;
            }

            $resolved = ResolveHikvisionPersonFromAcsEvent::uniquePersonFromUnlinkedIdentity(
                $companyPeople,
                trim((string) $event->person_name),
                self::unlinkedPersonCode($event),
            );

            if ($resolved === null) {
                continue;
            }

            $grouped[(int) $resolved->id][] = $event;
        }

        return collect($grouped)->map(
            fn (array $events): Collection => collect($events),
        );
    }

    /**
     * @return Collection<int, HikvisionPerson>
     */
    public static function companyPeople(int $companyId): Collection
    {
        if ($companyId <= 0) {
            return collect();
        }

        return HikvisionPerson::query()
            ->forCompany($companyId)
            ->get(['id', 'person_id', 'full_name', 'person_code']);
    }

    /**
     * Unique unlinked fallback keys for one linked employee, using company-wide uniqueness.
     *
     * @param  Collection<int, HikvisionPerson>  $companyPeople
     * @return array{0: list<string>, 1: list<string>} Lowercased name aliases, person codes
     */
    public static function uniqueUnlinkedIdentityKeys(Employee $employee, Collection $companyPeople): array
    {
        $person = $employee->hikvisionPerson;

        if ($person === null) {
            return [[], []];
        }

        $aliases = [];

        foreach (HikvisionPersonNameAliases::forEmployee($employee) as $alias) {
            $match = ResolveHikvisionPersonFromAcsEvent::uniquePersonFromNameAmong($companyPeople, $alias);

            if ($match !== null && (int) $match->id === (int) $person->id) {
                $aliases[] = mb_strtolower($alias);
            }
        }

        $personCode = trim((string) ($person->person_code ?? ''));
        $personCodes = [];

        if ($personCode !== '') {
            $match = ResolveHikvisionPersonFromAcsEvent::uniquePersonFromCodeAmong($companyPeople, $personCode);

            if ($match !== null && (int) $match->id === (int) $person->id) {
                $personCodes[] = $personCode;
            }
        }

        return [array_values(array_unique($aliases)), $personCodes];
    }

    /**
     * @param  Builder<HikvisionAccessEvent>  $query
     * @param  Collection<int, string>  $nameAliases  Lowercased aliases
     * @param  Collection<int, string>  $personCodes
     */
    public static function applyUnlinkedEventScope(Builder $query, Collection $nameAliases, Collection $personCodes): void
    {
        self::applyUnlinkedIdentityConstraints(
            $query,
            $nameAliases->values()->all(),
            $personCodes->values()->all(),
        );
    }

    /**
     * @param  Builder<HikvisionAccessEvent>  $query
     * @param  list<string>  $lowercasedNameAliases
     * @param  list<string>  $personCodes
     */
    private static function applyUnlinkedIdentityConstraints(
        Builder $query,
        array $lowercasedNameAliases,
        array $personCodes,
    ): void {
        $hasConstraint = false;

        foreach ($lowercasedNameAliases as $alias) {
            $normalizedAlias = mb_strtolower(trim((string) $alias));

            if ($normalizedAlias === '') {
                continue;
            }

            if ($hasConstraint) {
                $query->orWhereRaw('LOWER(person_name) = ?', [$normalizedAlias]);
            } else {
                $query->whereRaw('LOWER(person_name) = ?', [$normalizedAlias]);
                $hasConstraint = true;
            }
        }

        foreach ($personCodes as $personCode) {
            $personCode = trim((string) $personCode);

            if ($personCode === '') {
                continue;
            }

            $personCodeConstraint = function (Builder $mobileQuery) use ($personCode): void {
                $mobileQuery
                    ->where('transaction_source', HikvisionAccessEvent::TRANSACTION_MOBILE_APP)
                    ->where('raw_payload->personCode', $personCode);
            };

            if ($hasConstraint) {
                $query->orWhere($personCodeConstraint);
            } else {
                $query->where($personCodeConstraint);
                $hasConstraint = true;
            }
        }

        if (! $hasConstraint) {
            $query->whereRaw('1 = 0');
        }
    }

    private static function unlinkedPersonCode(HikvisionAccessEvent $event): string
    {
        if ($event->transaction_source !== HikvisionAccessEvent::TRANSACTION_MOBILE_APP) {
            return '';
        }

        $payload = is_array($event->raw_payload) ? $event->raw_payload : [];

        return trim((string) ($payload['personCode'] ?? ''));
    }
}
