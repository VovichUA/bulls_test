<?php

namespace Tests\Unit\GoogleSheets;

use App\Exceptions\GoogleSheetsException;
use App\Services\GoogleSheets\HeaderNormalizer;
use App\Services\GoogleSheets\SheetRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SheetRowMapperTest extends TestCase
{
    public function test_normalization_handles_unicode_spaces_bom_and_case(): void
    {
        $normalizer = new HeaderNormalizer;
        $this->assertSame('product name', $normalizer->normalize("\u{00A0}\u{200B}  PRODUCT\u{00A0}\t NAME\u{FEFF}"));
        $this->assertSame('ціна товару', $normalizer->normalize('  ЦІНА   ТОВАРУ  '));
    }

    public function test_rows_keep_absolute_positions_and_pad_missing_values(): void
    {
        $rows = $this->mapper()->rows([
            ['Report title'], ['Notes'], [' Price ', 'ACTIVE', 'Product  Name'],
            [0, false], [], [2.5, true, 'Tea'], [null, '', ''], [7],
        ], 3);
        $this->assertSame([
            4 => ['price' => 0, 'active' => false, 'product name' => null],
            6 => ['price' => 2.5, 'active' => true, 'product name' => 'Tea'],
            8 => ['price' => 7, 'active' => null, 'product name' => null],
        ], $rows);
    }

    public function test_header_only_sheet_has_no_data_rows(): void
    {
        $this->assertSame([], $this->mapper()->rows([['name']]));
    }

    public function test_empty_trailing_cells_outside_header_are_ignored(): void
    {
        $this->assertSame([2 => ['name' => 'Tea']], $this->mapper()->rows([['name'], ['Tea', '', null]]));
    }

    public function test_unmapped_blank_duplicate_and_extra_columns_do_not_reject_rows(): void
    {
        $mapper = $this->mapper();
        $headers = ['Name', 'Total', ' ', " total\u{00A0}", null];
        $this->assertSame(['name', 'total', null, 'total', null], $mapper->headers($headers));
        $this->assertSame(['total'], $mapper->ambiguous($mapper->headers($headers)));
        $this->assertSame([2 => ['name' => 'Tea']], $mapper->rows([
            $headers, ['Tea', 10, 'Notes', 20, false, 0],
        ]));
    }

    public function test_initial_empty_rows_keep_absolute_source_rows(): void
    {
        $this->assertSame([4 => ['name' => 'Tea']], $this->mapper()->rows([[], [], ['Name'], ['Tea']], 3));
    }

    #[DataProvider('invalidSheets')]
    public function test_invalid_data_is_rejected(array $values, int $headerRow): void
    {
        $this->expectException(GoogleSheetsException::class);
        $this->mapper()->rows($values, $headerRow);
    }

    /** @return array<string, array{array, int}> */
    public static function invalidSheets(): array
    {
        return [
            'empty sheet' => [[], 1],
            'missing header row' => [[['Title']], 3],
            'zero header row' => [[['name']], 0],
            'nested cell' => [[['name'], [['invalid']]], 1],
            'non-list row' => [[['name'], ['named' => 'Tea']], 1],
            'non-list matrix' => [['named' => ['name']], 1],
        ];
    }

    private function mapper(): SheetRowMapper
    {
        return new SheetRowMapper(new HeaderNormalizer);
    }
}
