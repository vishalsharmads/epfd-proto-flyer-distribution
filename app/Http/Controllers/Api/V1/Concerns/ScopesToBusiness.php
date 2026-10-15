<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Business;
use Illuminate\Http\Request;

/**
 * Ownership helpers for business-facing API controllers: every query is scoped to the caller's
 * business, so foreign ids resolve to 404 (not 403) and never leak existence.
 */
trait ScopesToBusiness
{
    protected function business(Request $request): Business
    {
        $business = $request->user()?->business;
        abort_if($business === null, 403, 'This endpoint requires a business account.');

        return $business;
    }

    /** `?per_page` clamped to 1..100 (default 20). */
    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->query('per_page', (string) $default)));
    }
}
