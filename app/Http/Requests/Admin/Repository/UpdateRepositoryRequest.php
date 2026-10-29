<?php

namespace App\Http\Requests\Admin\Repository;

use App\Enums\RepositoryStatus;
use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateRepositoryRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_paused' => $this->boolean('is_paused'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $repositoryId = $this->route('repository');

        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'full_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('repositories', 'full_name')->ignore($repositoryId),
            ],
            'github_repo_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('repositories', 'github_repo_id')->ignore($repositoryId),
            ],
            'status' => ['required', Rule::enum(RepositoryStatus::class)],
            'is_paused' => ['boolean'],
            'default_branch' => ['required', 'string', 'max:255'],
            'branches' => ['nullable', 'string'],
            'trello_board_id' => ['nullable', 'string', 'max:255'],
            'trello_list_id' => ['nullable', 'string', 'max:255'],
            'trusted_authors' => ['nullable', 'string', 'max:2000'],
            'review_rules_json' => ['nullable', 'json'],
            'notification_settings_json' => ['nullable', 'json'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'github_repo_id.min' => 'GitHub Repository ID must be a positive number.',
        ];
    }
}
