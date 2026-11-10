<?php

declare(strict_types=1);

namespace App\Modules\Core\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * `tap` for log channels (config/logging.php): adds MaskSensitiveData to
 * every handler, so whatever a handler writes is masked.
 */
final class MaskSensitiveDataTap
{
    public function __invoke(Logger $logger): void
    {
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new MaskSensitiveData);
            }
        }
    }
}
