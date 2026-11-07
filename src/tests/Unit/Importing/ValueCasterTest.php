<?php

namespace Tests\Unit\Importing;

use App\Enums\ColumnDataType;
use App\Exceptions\SheetImportException;
use App\Services\Importing\ValueCaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueCasterTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_converts_valid_values_without_silent_precision_loss(mixed $value, string $type, array $options, ?string $format, mixed $expected): void
    {
        $this->assertSame($expected, (new ValueCaster)->cast($value, ColumnDataType::from($type), $options, $format));
    }

    public static function validValues(): array
    {
        return [
            [null, 'integer', [], null, null], [" \u{00A0}", 'decimal', [], null, null],
            [' Tea  time ', 'string', [], null, 'Tea  time'], [1234567890.12345, 'string', [], null, '1234567890.12345'], [false, 'string', [], null, 'false'],
            ['-9223372036854775808', 'integer', [], null, PHP_INT_MIN], ['9223372036854775807', 'integer', [], null, PHP_INT_MAX],
            ['0012', 'integer', [], null, 12], [0, 'integer', [], null, 0],
            ['1 234,50', 'decimal', ['decimal_separator' => ',', 'thousands_separator' => ' '], null, '1234.5000'],
            ["1\u{00A0}234,50", 'decimal', ['decimal_separator' => ',', 'thousands_separator' => ' '], null, '1234.5000'],
            [12.5, 'decimal', ['decimal_separator' => ','], null, '12.5000'], [0.15, 'decimal', [], null, '0.1500'],
            ['-0.0000', 'decimal', [], null, '0.0000'], ['12.50000', 'decimal', [], null, '12.5000'],
            [false, 'boolean', [], null, false], [' ТАК ', 'boolean', [], null, true], [0, 'boolean', [], null, false],
            ['enabled', 'boolean', ['true_values' => ['enabled'], 'false_values' => ['disabled']], null, true],
            ['29.02.2024', 'date', [], 'd.m.Y', '2024-02-29'],
            ['2026-11-07 15:30:00', 'datetime', [], null, '2026-11-07 15:30:00'],
            ['2024-01-02 00:30:00', 'datetime', ['timezone' => 'Europe/Kyiv'], null, '2024-01-01 22:30:00'],
            ['2024-07-02 00:30:00', 'datetime', ['timezone' => 'Europe/Kyiv'], null, '2024-07-01 21:30:00'],
            ['2024-01-02', 'date', ['timezone' => 'Europe/Kyiv'], null, '2024-01-02'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_values_and_options(mixed $value, string $type, array $options = [], ?string $format = null): void
    {
        $this->expectException(SheetImportException::class);
        (new ValueCaster)->cast($value, ColumnDataType::from($type), $options, $format);
    }

    public static function invalidValues(): array
    {
        return [
            ['9223372036854775808', 'integer'], ['-9223372036854775809', 'integer'], ['1.5', 'integer'],
            ['1,23,4', 'decimal', ['decimal_separator' => ',']], ['12 34,5', 'decimal', ['decimal_separator' => ',', 'thousands_separator' => ' ']],
            ['0.00001', 'decimal'], ['100000000000', 'decimal'], ['abc', 'decimal'],
            ['2024-01-02 12:00:00', 'datetime', ['timezone' => 'Invalid/Zone']], ['2024-03-31 03:30:00', 'datetime', ['timezone' => 'Europe/Kyiv']],
            ['31.02.2024', 'date', [], 'd.m.Y'], ['2024-02-30', 'date'],
            ['2026-11-07 25:00:00', 'datetime'], ['perhaps', 'boolean'], [1.00000000000001, 'boolean'], [2, 'boolean'],
            ['yes', 'boolean', ['true_values' => ['YES'], 'false_values' => [' yes ']]],
            ['hello', 'string', ['length' => 3]], [[], 'text'],
            ['1', 'decimal', ['precision' => 2, 'scale' => 3]], ['1', 'decimal', ['decimal_separator' => '.', 'thousands_separator' => '.']],
        ];
    }
}
