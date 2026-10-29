<?php

namespace App\Http\Requests\Admin\Review;

use App\Enums\ChangeClass;
use App\Http\Requests\Admin\AdminFormRequest;
use App\Models\Review;
use Illuminate\Validation\Validator;

class OverrideReviewRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'documentation_url' => ['nullable', 'string', 'url', 'max:2048'],
            'documentation_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'An override reason is required.',
            'reason.min' => 'The override reason must be at least 10 characters.',
            'documentation_url.url' => 'Documentation URL must be a valid URL.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Review|null $review */
            $review = $this->route('review');
            if (! $review instanceof Review) {
                return;
            }

            $classes = collect($review->change_classes ?? [])
                ->map(fn ($value) => ChangeClass::tryFrom((string) $value))
                ->filter();

            $needsDocs = $classes->contains(fn (ChangeClass $c) => $c->requiresDocumentation());
            if (! $needsDocs) {
                return;
            }

            $url = trim((string) $this->input('documentation_url', ''));
            $notes = trim((string) $this->input('documentation_notes', ''));
            if ($url === '' && $notes === '') {
                $validator->errors()->add(
                    'documentation_url',
                    'API contract changes require a documentation URL or notes (CHG-003).'
                );
            }
        });
    }
}
