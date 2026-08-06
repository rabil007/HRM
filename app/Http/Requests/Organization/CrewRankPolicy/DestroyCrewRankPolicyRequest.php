<?php

namespace App\Http\Requests\Organization\CrewRankPolicy;

use Illuminate\Foundation\Http\FormRequest;

class DestroyCrewRankPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.rank_policies.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
