<?php

namespace App\Http\Requests\Admin\Project;

use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->route('project');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')->ignore($projectId),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('projects', 'slug')->ignore($projectId),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A project with this name already exists.',
            'slug.unique' => 'This slug already exists. Please enter a unique slug.',
            'slug.alpha_dash' => 'The slug may only contain letters, numbers, dashes, and underscores.',
        ];
    }
}
