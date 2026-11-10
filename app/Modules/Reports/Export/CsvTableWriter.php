<?php

declare(strict_types=1);

namespace App\Modules\Reports\Export;

use RuntimeException;

/**
 * UTF-8 CSV with a byte-order mark (so spreadsheet apps detect the encoding).
 * Text that a spreadsheet would run as a formula (=, +, -, @, tab, CR) is
 * prefixed with an apostrophe: names and codes come from user input.
 */
final class CsvTableWriter extends TableWriter
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the export file.');
        }
        $this->handle = $handle;
        fwrite($this->handle, "\xEF\xBB\xBF");
    }

    public function header(array $columns): void
    {
        $this->row($columns);
    }

    public function row(array $values): void
    {
        fputcsv($this->handle, array_map(self::cell(...), $values), ',', '"', '');
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    public static function cell(string|int|float|null $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
