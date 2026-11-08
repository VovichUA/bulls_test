<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SheetTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->approved_at !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->input('is_active', false)]);
    }

    public function rules(): array
    {
        return [
            'tab_name' => ['required', 'string', 'max:255', Rule::unique('sheet_tabs')->where('source_id', $this->route('source')->id)->ignore($this->route('tab')?->id)],
            'header_row' => ['required', 'integer', 'min:1', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
