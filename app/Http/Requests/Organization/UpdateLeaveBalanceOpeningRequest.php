<?php

namespace App\Http\Requests\Organization;

use App\Models\LeaveBalance;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeaveBalanceOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('reports.leave_balance.view') ?? false)
            && ($this->user()?->can('reports.leave_balance.update_opening') ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->assertRouteBalanceBelongsToCurrentCompany();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_used_days' => ['required', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'opening_balance_as_of' => ['nullable', 'date_format:Y-m-d'],
            'opening_balance_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $openingUsed = round((float) $this->input('opening_used_days'), 2);
            $balance = $this->routeBalance();
            $companyId = (int) $this->attributes->get('current_company_id');
            $timezone = CompanyTimezone::forCompanyId($companyId);
            $today = now($timezone)->toDateString();
            $asOf = $this->input('opening_balance_as_of');

            if ($openingUsed > 0) {
                if (! filled($asOf)) {
                    $validator->errors()->add(
                        'opening_balance_as_of',
                        'An as-of date is required when previous used days are greater than zero.',
                    );

                    return;
                }

                $asOfDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $asOf);

                if ((int) $asOfDate->year !== (int) $balance->year) {
                    $validator->errors()->add(
                        'opening_balance_as_of',
                        'The as-of date must fall within the balance year.',
                    );
                }

                if ($asOfDate->toDateString() > $today) {
                    $validator->errors()->add(
                        'opening_balance_as_of',
                        'The as-of date cannot be later than today.',
                    );
                }
            }
        });
    }

    /**
     * @return array{
     *     opening_used_days: float,
     *     opening_balance_as_of: string|null,
     *     opening_balance_note: string|null,
     * }
     */
    public function openingAttributes(): array
    {
        return [
            'opening_used_days' => round((float) $this->validated('opening_used_days'), 2),
            'opening_balance_as_of' => $this->validated('opening_balance_as_of'),
            'opening_balance_note' => $this->validated('opening_balance_note'),
        ];
    }

    private function assertRouteBalanceBelongsToCurrentCompany(): void
    {
        $balance = $this->route('leaveBalance');
        $companyId = (int) $this->attributes->get('current_company_id');

        if (! $balance instanceof LeaveBalance) {
            abort(404);
        }

        if ((int) $balance->company_id !== $companyId) {
            abort(404);
        }
    }

    private function routeBalance(): LeaveBalance
    {
        $balance = $this->route('leaveBalance');

        if (! $balance instanceof LeaveBalance) {
            abort(404);
        }

        return $balance;
    }
}
