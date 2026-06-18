<?php

namespace App\Http\Requests\Organization\VesselManning;

use App\Models\Rank;
use App\Models\Vessel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateVesselManningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requirements' => ['present', 'array'],
            'requirements.*.rank_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('ranks', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'requirements.*.required_count' => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Vessel|null $vessel */
            $vessel = $this->route('vessel');

            if (! $vessel instanceof Vessel) {
                return;
            }

            if (! $vessel->is_active) {
                $validator->errors()->add('vessel', 'Manning cannot be updated for an inactive vessel.');
            }

            $rankIds = collect($this->input('requirements', []))
                ->pluck('rank_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($rankIds === []) {
                return;
            }

            $activeRankCount = Rank::query()
                ->whereIn('id', $rankIds)
                ->where('is_active', true)
                ->count();

            if ($activeRankCount !== count(array_unique($rankIds))) {
                $validator->errors()->add('requirements', 'One or more selected ranks are inactive or invalid.');
            }
        });
    }
}
