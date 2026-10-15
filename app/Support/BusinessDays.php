<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/** Business-day arithmetic in Australia/Sydney (weekends skipped; public holidays not modelled). */
final class BusinessDays
{
    public static function add(int $days, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $d = ($from ?? CarbonImmutable::now(config('epfd.timezone')))->startOfDay();
        $days = max(1, $days);
        while ($days > 0) {
            $d = $d->addDay();
            if (! $d->isWeekend()) {
                $days--;
            }
        }

        return $d;
    }
}
