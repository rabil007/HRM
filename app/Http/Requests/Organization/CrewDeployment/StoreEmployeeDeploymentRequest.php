<?php

namespace App\Http\Requests\Organization\CrewDeployment;

use App\Http\Requests\Organization\CrewDeployment\Concerns\EmployeeDeploymentRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeDeploymentRequest extends FormRequest
{
    use EmployeeDeploymentRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'require_disembarked_with_joined' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->deploymentFieldRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->deploymentFieldMessages();
    }
}
