<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Support\Hikvision\HikvisionPersonNameAliases;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Company-scoped matching of trusted Hikvision access events to a linked employee.
 *
 * Prefer person_hikvision_id. Fallback matching for legacy unlinked ACS/mobile rows uses the
 * same safe rules as attendance sync (exact name aliases + mobile personCode).
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
        $aliases = array_map(
            mb_strtolower(...),
            HikvisionPersonNameAliases::forEmployee($employee),
        );
        $personCode = trim((string) ($employee->hikvisionPerson?->person_code ?? ''));
        $personCodes = $personCode !== '' ? [$personCode] : [];

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
     * @param  Collection<int, HikvisionAccessEvent>  $companyEvents
     * @return Collection<int, HikvisionAccessEvent>
     */
    public static function resolveFromLoadedEvents(
        Employee $employee,
        Collection $eventsByPersonId,
        Collection $companyEvents,
    ): Collection {
        $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');
        $events = $personId !== ''
            ? $eventsByPersonId->get($personId, collect())
            : collect();

        $aliases = array_map(
            mb_strtolower(...),
            HikvisionPersonNameAliases::forEmployee($employee),
        );
        $personCode = trim((string) ($employee->hikvisionPerson?->person_code ?? ''));

        if ($aliases === [] && $personCode === '') {
            return $events->values();
        }

        $unlinkedMatches = $companyEvents->filter(function (HikvisionAccessEvent $event) use ($aliases, $personCode): bool {
            if (filled($event->person_hikvision_id)) {
                return false;
            }

            $personName = mb_strtolower(trim((string) $event->person_name));

            if ($personName !== '' && in_array($personName, $aliases, true)) {
                return true;
            }

            if ($personCode === '' || $event->transaction_source !== HikvisionAccessEvent::TRANSACTION_MOBILE_APP) {
                return false;
            }

            $payload = is_array($event->raw_payload) ? $event->raw_payload : [];

            return trim((string) ($payload['personCode'] ?? '')) === $personCode;
        });

        return $events
            ->merge($unlinkedMatches)
            ->unique('id')
            ->values();
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
}
