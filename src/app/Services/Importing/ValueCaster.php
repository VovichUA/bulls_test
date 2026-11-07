<?php

namespace App\Services\Importing;

use App\Enums\ColumnDataType;
use App\Exceptions\SheetImportException;
use App\Services\GoogleSheets\HeaderNormalizer;
use DateTimeImmutable;
use DateTimeZone;

class ValueCaster
{
    /** @param array<string, mixed> $options */
    public function cast(mixed $value, ColumnDataType $type, array $options = [], ?string $format = null): string|int|bool|null
    {
        $this->validateOptions($type, $options, $format);
        if ($value === null || (is_string($value) && $this->trim($value) === '')) {
            return null;
        }
        if (! is_scalar($value) || (is_float($value) && ! is_finite($value))) {
            throw new SheetImportException('Unsupported cell value.');
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : $this->trim(is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value);

        return match ($type) {
            ColumnDataType::String => $this->string($text, $options['length'] ?? 255),
            ColumnDataType::Text => strlen($text) <= 65535 ? $text : throw new SheetImportException('TEXT value exceeds its byte limit.'),
            ColumnDataType::Integer => $this->integer($this->number($value, $options)),
            ColumnDataType::Decimal => $this->decimal($this->number($value, $options), $options['precision'] ?? 15, $options['scale'] ?? 4),
            ColumnDataType::Boolean => $this->boolean($value, $options),
            ColumnDataType::Date => $this->date($text, $format ?? 'Y-m-d', false),
            ColumnDataType::Datetime => $this->date($text, $format ?? 'Y-m-d H:i:s', true, $options['timezone'] ?? 'UTC'),
        };
    }

    /** @param array<string, mixed> $options */
    public function validateOptions(ColumnDataType $type, array $options = [], ?string $format = null): void
    {
        if ($options !== [] && array_is_list($options)) {
            throw new SheetImportException('type_options must be an object.');
        }
        if ($type === ColumnDataType::String) {
            $this->option($options, 'length', 255, 1, 255);
        }
        if ($type === ColumnDataType::Decimal) {
            $precision = $this->option($options, 'precision', 15, 1, 65);
            $scale = $this->option($options, 'scale', 4, 0, 30);
            if ($scale > $precision) {
                throw new SheetImportException('Decimal scale exceeds precision.');
            }
        }
        if (in_array($type, [ColumnDataType::Decimal, ColumnDataType::Integer], true)) {
            $decimal = $options['decimal_separator'] ?? '.';
            $thousands = $options['thousands_separator'] ?? null;
            foreach ([$decimal, $thousands] as $separator) {
                if ($separator !== null && (! is_string($separator) || mb_strlen($separator) !== 1 || preg_match('/[0-9+\-eE]/u', $separator))) {
                    throw new SheetImportException('Invalid numeric separator option.');
                }
            }
            if ($decimal === $thousands || preg_match('/\s/u', $decimal)) {
                throw new SheetImportException('Numeric separators must be distinct and the decimal separator cannot be whitespace.');
            }
        }
        if ($type === ColumnDataType::Boolean) {
            [$true, $false] = $this->booleanLists($options);
            if (array_intersect($true, $false) !== []) {
                throw new SheetImportException('Boolean true/false values overlap.');
            }
        }
        if ($type === ColumnDataType::Datetime) {
            $timezone = array_key_exists('timezone', $options) ? $options['timezone'] : 'UTC';
            if (! is_string($timezone) || $timezone === '') {
                throw new SheetImportException('Datetime timezone must be a non-empty timezone name.');
            }
            try {
                new DateTimeZone($timezone);
            } catch (\Throwable) {
                throw new SheetImportException('Invalid datetime timezone.');
            }
        }
        if (in_array($type, [ColumnDataType::Date, ColumnDataType::Datetime], true) && $format === '') {
            throw new SheetImportException('Date format cannot be empty.');
        }
    }

    private function trim(string $value): string
    {
        $value = preg_replace('/^[\s\x{200B}\x{FEFF}]+|[\s\x{200B}\x{FEFF}]+$/u', '', $value);
        if ($value === null) {
            throw new SheetImportException('Invalid UTF-8 cell.');
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private function option(array $options, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = array_key_exists($key, $options) ? $options[$key] : $default;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SheetImportException('Invalid numeric type option: '.$key.'.');
        }

        return $value;
    }

    private function string(string $value, int $length): string
    {
        if (mb_strlen($value, 'UTF-8') > $length) {
            throw new SheetImportException('String value exceeds configured character length.');
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private function number(mixed $value, array $options): string
    {
        if (is_bool($value)) {
            throw new SheetImportException('Boolean is not a numeric value.');
        }
        $text = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $this->trim((string) $value);
        if (is_string($value)) {
            $separator = $options['decimal_separator'] ?? '.';
            $group = $options['thousands_separator'] ?? null;
            if ($group === ' ') {
                $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
            }
            $whole = '[0-9]+';
            if ($group !== null) {
                $whole = '(?:[0-9]+|[0-9]{1,3}(?:'.preg_quote($group, '/').'[0-9]{3})+)';
            }
            if (! preg_match('/^[+-]?'.$whole.'(?:'.preg_quote($separator, '/').'[0-9]+)?$/D', $text)) {
                throw new SheetImportException('Invalid numeric text or grouping.');
            }
            if ($group !== null) {
                $text = str_replace($group, '', $text);
            }
            $text = str_replace($separator, '.', $text);
        }
        if (! preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $text, $parts)) {
            throw new SheetImportException('Invalid numeric value.');
        }
        $exponent = (int) ($parts[4] ?? 0);
        if (abs($exponent) > 400) {
            throw new SheetImportException('Numeric exponent is out of range.');
        }
        $digits = $parts[2].($parts[3] ?? '');
        $point = strlen($parts[2]) + $exponent;
        if ($point <= 0) {
            $whole = '0';
            $fraction = str_repeat('0', -$point).$digits;
        } else {
            $digits = str_pad($digits, $point, '0');
            $whole = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $sign = $parts[1] === '-' && ($whole !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private function integer(string $value): int
    {
        $absolute = ltrim($value, '-');
        $bound = str_starts_with($value, '-') ? '9223372036854775808' : '9223372036854775807';
        if (str_contains($value, '.') || strlen($absolute) > 19 || (strlen($absolute) === 19 && strcmp($absolute, $bound) > 0)) {
            throw new SheetImportException('Value is not an exact signed BIGINT.');
        }

        return (int) $value;
    }

    private function decimal(string $value, int $precision, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        if (strlen($fraction) > $scale || strlen(ltrim($whole, '0')) > $precision - $scale) {
            throw new SheetImportException('Decimal value exceeds precision or would lose fractional digits.');
        }

        return ($negative ? '-' : '').$whole.($scale > 0 ? '.'.str_pad($fraction, $scale, '0') : '');
    }

    /** @param array<string, mixed> $options
     * @return array{list<string>, list<string>}
     */
    private function booleanLists(array $options): array
    {
        $lists = [];
        foreach (['true_values' => ['1', 'true', 'yes', 'так'], 'false_values' => ['0', 'false', 'no', 'ні']] as $key => $default) {
            $values = array_key_exists($key, $options) ? $options[$key] : $default;
            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                throw new SheetImportException('Boolean values must be non-empty lists of strings.');
            }
            $list = [];
            foreach ($values as $value) {
                if (! is_string($value) || ($value = (new HeaderNormalizer)->normalize($value)) === '') {
                    throw new SheetImportException('Boolean labels must be non-empty strings.');
                }
                $list[] = $value;
            }
            $lists[] = $list;
        }

        return $lists;
    }

    private function boolean(mixed $value, array $options): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        [$true, $false] = $this->booleanLists($options);
        $text = (new HeaderNormalizer)->normalize(is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value);
        if (in_array($text, $true, true)) {
            return true;
        }
        if (in_array($text, $false, true)) {
            return false;
        }
        throw new SheetImportException('Unknown boolean value.');
    }

    private function date(string $value, string $format, bool $datetime, string $timezone = 'UTC'): string
    {
        try {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone($timezone));
        } catch (\ValueError) {
            throw new SheetImportException('Invalid date format or value.');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format($format) !== $value) {
            throw new SheetImportException('Invalid date/time value for the configured format.');
        }
        $date = $datetime ? $date->setTimezone(new DateTimeZone('UTC')) : $date;
        $year = (int) $date->format('Y');
        if ($year < 1000 || $year > 9999) {
            throw new SheetImportException('Date is outside the MySQL date range.');
        }

        return $date->format($datetime ? 'Y-m-d H:i:s' : 'Y-m-d');
    }
}
