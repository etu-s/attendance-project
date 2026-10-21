<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use Carbon\CarbonImmutable;

/**
 * "Today" as the tenant sees it. A date-only business rule (is this
 * assignment current?) must use the tenant's calendar day, not the UTC day:
 * at 01:00 in Asia/Karachi it is still yesterday in UTC.
 */
final class TenantClock
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function timezone(): string
    {
        return (string) $this->settings->get('general.timezone');
    }

    /**
     * Today's date in the tenant timezone, as a date-only value (00:00 UTC).
     */
    public function today(): CarbonImmutable
    {
        return LocalDate::parse(CarbonImmutable::now($this->timezone())->format(LocalDate::FORMAT));
    }
}
