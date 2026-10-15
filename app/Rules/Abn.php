<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Australian Business Number checksum (ATO algorithm): subtract 1 from the first digit, weight the
 * 11 digits by 10,1,3,5,7,9,11,13,15,17,19 and the sum must be divisible by 89. Spaces are ignored.
 * ABN is optional — combine with `nullable`.
 */
final class Abn implements ValidationRule
{
    private const WEIGHTS = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

    public static function normalise(?string $abn): ?string
    {
        $d = preg_replace('/\D/', '', (string) $abn);

        return $d === '' ? null : $d;
    }

    public static function valid(?string $abn): bool
    {
        $d = self::normalise($abn);
        if ($d === null || strlen($d) !== 11) {
            return false;
        }
        $sum = 0;
        foreach (str_split($d) as $i => $c) {
            $n = (int) $c - ($i === 0 ? 1 : 0);
            $sum += $n * self::WEIGHTS[$i];
        }

        return $sum % 89 === 0;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        if (! self::valid((string) $value)) {
            $fail('Enter a valid 11-digit ABN (or leave it blank).');
        }
    }
}
