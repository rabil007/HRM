<?php

namespace App\Support\CrewMovements;

use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignmentSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CrewAssignmentNumberGenerator
{
    public function next(int $companyId, ?int $year = null): string
    {
        $year ??= (int) now($this->companyTimezone($companyId))->year;

        return DB::transaction(function () use ($companyId, $year): string {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $sequence = CrewAssignmentSequence::query()
                        ->where('company_id', $companyId)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();

                    if ($sequence === null) {
                        CrewAssignmentSequence::query()->create([
                            'company_id' => $companyId,
                            'year' => $year,
                            'last_number' => 0,
                        ]);

                        $sequence = CrewAssignmentSequence::query()
                            ->where('company_id', $companyId)
                            ->where('year', $year)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $sequence->last_number = ((int) $sequence->last_number) + 1;
                    $sequence->save();

                    return sprintf('CA-%d-%06d', $year, $sequence->last_number);
                } catch (QueryException) {
                    continue;
                }
            }

            throw CrewMovementException::make(
                'Unable to allocate a unique assignment number.',
                'assignment_number_race',
            );
        });
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }
}
