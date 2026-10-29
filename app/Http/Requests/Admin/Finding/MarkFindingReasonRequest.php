<?php

namespace App\Http\Requests\Admin\Finding;

use App\Http\Requests\Admin\AdminFormRequest;

class MarkFindingReasonRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required.',
            'reason.min' => 'The reason must be at least 10 characters.',
        ];
    }
}
