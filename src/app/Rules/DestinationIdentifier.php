<?php

namespace App\Rules;

use App\Exceptions\SchemaManagerException;
use App\Services\SchemaManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DestinationIdentifier implements ValidationRule
{
    public function __construct(private readonly bool $table = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value)) {
                $fail('Назва має бути текстом.');

                return;
            }
            $schema = app(SchemaManager::class);
            if ($this->table) {
                $schema->validateTableName($value);
            } else {
                $schema->validateColumnName($value);
            }
        } catch (SchemaManagerException) {
            $fail('Дозволено a–z, 0–9 та _, від 1 до 64 символів, перший — літера. Службові назви заборонені.');
        }
    }
}
