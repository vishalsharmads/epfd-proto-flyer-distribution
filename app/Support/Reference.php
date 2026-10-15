<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Human-friendly unique references: CMP-7K3Q9X, PRT-…, RUN-…, invoices EPFD-2026-000123. */
final class Reference
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function make(string $prefix, string $table, string $column = 'reference'): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $ref = $prefix.'-'.$code;
        } while (DB::table($table)->where($column, $ref)->exists());

        return $ref;
    }

    public static function invoiceNumber(): string
    {
        $year = now(config('epfd.timezone'))->year;
        $last = DB::table('invoices')->where('number', 'like', "EPFD-{$year}-%")->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr((string) $last, -6)) + 1 : 1;

        return sprintf('EPFD-%d-%06d', $year, $seq);
    }
}
