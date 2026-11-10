<?php

declare(strict_types=1);

namespace App\Modules\Reports\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX through openspout (streams to disk). Strings are written as string
 * cells, never as formulas, so no escaping is needed.
 */
final class XlsxTableWriter extends TableWriter
{
    private Writer $writer;

    public function __construct(string $path)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($path);
    }

    public function header(array $columns): void
    {
        $this->writer->addRow(Row::fromValuesWithStyle($columns, new Style(fontBold: true)));
    }

    public function row(array $values): void
    {
        $this->writer->addRow(Row::fromValues($values));
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
