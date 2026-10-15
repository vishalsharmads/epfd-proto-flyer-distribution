<?php

declare(strict_types=1);

namespace App\Services;

use App\Rules\Abn;

/**
 * Stub for the ABR (Australian Business Register) lookup. Returns a deterministic fake entity for any
 * checksum-valid ABN so the UI can show "Registered to …". Swap for the ABR JSON web service in production.
 */
final class AbnLookupService
{
    /** @return array{abn:string, entity_name:string, status:string, state:string}|null */
    public function lookup(?string $abn): ?array
    {
        if (! Abn::valid($abn)) {
            return null;
        }
        $d = (string) Abn::normalise($abn);
        $names = ['Harbour Holdings', 'Parramatta Trading', 'Southern Cross Ventures', 'Blue Gum Enterprises', 'Wattle & Co', 'Coastal Services'];
        $states = ['NSW', 'VIC', 'QLD', 'NSW', 'WA', 'SA'];
        $i = ((int) substr($d, -3)) % count($names);

        return [
            'abn' => substr($d, 0, 2).' '.substr($d, 2, 3).' '.substr($d, 5, 3).' '.substr($d, 8, 3),
            'entity_name' => $names[$i].' Pty Ltd',
            'status' => 'Active',
            'state' => $states[$i],
        ];
    }
}
