<?php

namespace App\Http\Requests;

use App\Enums\ColumnDataType;
use App\Exceptions\SheetImportException;
use App\Rules\DestinationIdentifier;
use App\Services\GoogleSheets\HeaderNormalizer;
use App\Services\Importing\ValueCaster;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;
use Throwable;

class SyncTargetRequest extends FormRequest
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
            'target_table' => ['required', 'string', new DestinationIdentifier(table: true), Rule::unique('sync_targets', 'target_table')->where('tab_id', $this->route('tab')->id)->ignore($this->route('target'))],
            'is_active' => ['required', 'boolean'],
            'config_fingerprint' => [$this->isMethod('POST') ? 'nullable' : 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'mappings' => ['required', 'array', 'min:1', 'max:100'],
            'mappings.*.source_column' => ['required', 'string', 'max:255'],
            'mappings.*.target_column' => ['required', 'string', 'distinct:strict', new DestinationIdentifier],
            'mappings.*.data_type' => ['required', Rule::enum(ColumnDataType::class)],
            'mappings.*.nullable' => ['sometimes', 'boolean'],
            'mappings.*.key' => ['sometimes', 'boolean'],
            'mappings.*.format' => ['nullable', 'string', 'max:255'],
            'mappings.*.type_options_json' => ['bail', 'nullable', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $this->decodeOptions($value);
                } catch (SheetImportException $exception) {
                    $fail($exception->getMessage());
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('mappings') as $index => $mapping) {
                try {
                    $source = app(HeaderNormalizer::class)->normalize($mapping['source_column']);
                    if ($source === '') {
                        $validator->errors()->add('mappings.'.$index.'.source_column', 'Заголовок не може бути порожнім.');
                    }
                } catch (Throwable) {
                    $validator->errors()->add('mappings.'.$index.'.source_column', 'Заголовок має бути коректним UTF-8 текстом.');
                }
                try {
                    $raw = $mapping['type_options_json'] ?? '{}';
                    if ($raw === '') {
                        $raw = '{}';
                    }
                    app(ValueCaster::class)->validateOptions(ColumnDataType::from($mapping['data_type']), $this->decodeOptions($raw), $mapping['format'] ?? null);
                } catch (SheetImportException $exception) {
                    $validator->errors()->add('mappings.'.$index.'.type_options_json', $exception->getMessage());
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function decodeOptions(string $raw): array
    {
        if (strlen($raw) > 10000) {
            throw new SheetImportException('Параметри типу не можуть перевищувати 10000 байтів.');
        }
        try {
            $object = json_decode($raw, false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SheetImportException('Параметри мають бути коректним JSON із глибиною до 4 рівнів.');
        }
        if (! is_object($object)) {
            throw new SheetImportException('Параметри мають бути JSON-об’єктом.');
        }
        $allowed = ['precision', 'scale', 'length', 'timezone', 'decimal_separator', 'thousands_separator', 'true_values', 'false_values'];
        $options = (array) $object;
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new SheetImportException('Параметри типу містять невідомі ключі.');
        }
        foreach ($options as $key => $value) {
            if (in_array($key, ['true_values', 'false_values'], true)) {
                if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn ($label) => ! is_string($label)) !== []) {
                    throw new SheetImportException('Булеві значення мають бути списками рядків без вкладених структур.');
                }
            } elseif (is_array($value) || is_object($value)) {
                throw new SheetImportException('Параметри типу не можуть містити вкладені об’єкти чи списки.');
            }
        }

        return $options;
    }

    public function mappingRecords(): array
    {
        return array_map(function (array $mapping): array {
            $options = $this->decodeOptions($mapping['type_options_json'] ?? '{}');

            return ['source_column' => app(HeaderNormalizer::class)->normalize($mapping['source_column']), 'target_column' => $mapping['target_column'], 'data_type' => $mapping['data_type'], 'nullable' => (bool) ($mapping['nullable'] ?? false), 'format' => $mapping['format'] ?? null, 'type_options' => $options ?: null];
        }, $this->validated('mappings'));
    }

    public function uniqueKeys(): ?array
    {
        $keys = [];
        foreach ($this->validated('mappings') as $mapping) {
            if ($mapping['key'] ?? false) {
                $keys[] = $mapping['target_column'];
            }
        }

        return $keys ?: null;
    }
}
