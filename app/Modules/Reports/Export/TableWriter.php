<?php

declare(strict_types=1);

namespace App\Modules\Reports\Export;

use App\Modules\Reports\Enums\ExportFormat;

/**
 * Streams a table to a file row by row, so an export never holds the whole
 * report in memory.
 */
abstract class TableWriter
{
    public static function open(ExportFormat $format, string $path): self
    {
        return match ($format) {
            ExportFormat::Csv => new CsvTableWriter($path),
            ExportFormat::Xlsx => new XlsxTableWriter($path),
        };
    }

    /**
     * @param  list<string>  $columns
     */
    abstract public function header(array $columns): void;

    /**
     * @param  list<string|int|float|null>  $values
     */
    abstract public function row(array $values): void;

    abstract public function close(): void;
}
