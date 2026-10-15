<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure rendered as RFC 9457 problem+json.
 * `code` is a stable machine-readable slug listed in the API error-code table.
 */
class DomainProblem extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $problemCode,
        string $detail,
        public readonly int $status = 422,
        public readonly string $title = 'Request could not be processed',
        public readonly array $extra = [],
    ) {
        parent::__construct($detail);
    }
}
