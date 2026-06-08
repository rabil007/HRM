<?php

namespace App\Http\Requests\Hikvision;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class FetchHikvisionAccessEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hikvision.events.fetch');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return [
            'date' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:'.now($timezone)->format('Y-m-d'),
            ],
        ];
    }

    public function resolvedDate(): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $date = $this->string('date')->toString();

        if ($date === '') {
            return Carbon::parse(now($timezone)->toDateString(), $timezone)->startOfDay();
        }

        return Carbon::parse($date, $timezone)->startOfDay();
    }
}
