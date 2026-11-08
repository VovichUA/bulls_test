<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SheetSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->approved_at !== null;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('spreadsheet_id');
        if (is_string($value) && str_contains($value, '://')) {
            $url = parse_url($value);
            if (is_array($url) && strtolower($url['scheme'] ?? '') === 'https' && strtolower($url['host'] ?? '') === 'docs.google.com'
                && ! isset($url['user']) && ! isset($url['pass']) && (! isset($url['port']) || $url['port'] === 443)
                && preg_match('~\A/spreadsheets/d/([a-zA-Z0-9_-]+)(?:/|\z)~', $url['path'] ?? '', $match)) {
                $this->merge(['spreadsheet_id' => $match[1]]);
            }
        }
        $this->merge(['is_active' => $this->input('is_active', false)]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'spreadsheet_id' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_-]+\z/', Rule::unique('sheet_sources', 'spreadsheet_id')->ignore($this->route('source')?->id)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['spreadsheet_id.regex' => 'Укажіть Google Sheets ID або посилання https://docs.google.com/spreadsheets/d/...', 'spreadsheet_id.unique' => 'Цей файл уже налаштований.'];
    }
}
