<?php

declare(strict_types=1);

namespace App\Services;

use App\Rules\Abn;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ABN lookup against the Australian Business Register JSON web service (needs ABR_GUID, free from
 * abr.business.gov.au/Tools/WebServices). Results are cached for a day. Without a GUID, local and test
 * environments get a deterministic fake entity so the "Registered to …" hint still works.
 */
final class AbnLookupService
{
    private const ENDPOINT = 'https://abr.business.gov.au/json/AbnDetails.aspx';

    /**
     * The registered entity, or null when the ABN is invalid, not registered, or the register is unavailable.
     *
     * @return array{abn:string, entity_name:string, status:string, state:string}|null
     */
    public function lookup(?string $abn): ?array
    {
        if (! Abn::valid($abn)) {
            return null;
        }
        $digits = (string) Abn::normalise($abn);
        $guid = (string) config('services.abr.guid');
        if ($guid === '') {
            return app()->isProduction() ? null : self::fake($digits);
        }

        $cached = Cache::get("abr:{$digits}");
        if ($cached !== null) {
            return $cached ?: null;
        }
        $hit = $this->fetch($digits, $guid);
        if ($hit !== false) {
            // Remember "not registered" too, but for less time in case it was just registered.
            Cache::put("abr:{$digits}", $hit ?? [], $hit ? now()->addDay() : now()->addHour());
        }

        return $hit ?: null;
    }

    /** @return array{abn:string, entity_name:string, status:string, state:string}|null|false false when the register could not be reached */
    private function fetch(string $digits, string $guid): array|null|false
    {
        try {
            $res = Http::timeout(5)->get(self::ENDPOINT, ['abn' => $digits, 'guid' => $guid, 'callback' => 'r']);
        } catch (ConnectionException $e) {
            Log::warning('ABR lookup failed', ['error' => $e->getMessage()]);

            return false;
        }
        // The service answers JSONP: r({...})
        $json = json_decode((string) preg_replace('/^\s*r\((.*)\)\s*;?\s*$/s', '$1', $res->body()), true);
        if (! $res->successful() || ! is_array($json)) {
            Log::warning('ABR lookup returned an unexpected response', ['status' => $res->status()]);

            return false;
        }
        if (($json['Message'] ?? '') !== '' && empty($json['Abn'])) {
            if (str_contains(strtolower((string) $json['Message']), 'guid')) {
                Log::error('ABR rejected the ABR_GUID', ['message' => $json['Message']]);

                return false;
            }

            return null;
        }
        $name = trim((string) ($json['EntityName'] ?? '')) ?: trim((string) (($json['BusinessName'] ?? [])[0] ?? ''));

        return [
            'abn' => self::format($digits),
            'entity_name' => $name,
            'status' => ($json['AbnStatus'] ?? '') === 'Active' ? 'Active' : 'Cancelled',
            'state' => (string) ($json['AddressState'] ?? ''),
        ];
    }

    /** @return array{abn:string, entity_name:string, status:string, state:string} */
    private static function fake(string $digits): array
    {
        $names = ['Harbour Holdings', 'Parramatta Trading', 'Southern Cross Ventures', 'Blue Gum Enterprises', 'Wattle & Co', 'Coastal Services'];
        $states = ['NSW', 'VIC', 'QLD', 'NSW', 'WA', 'SA'];
        $i = ((int) substr($digits, -3)) % count($names);

        return ['abn' => self::format($digits), 'entity_name' => $names[$i].' Pty Ltd', 'status' => 'Active', 'state' => $states[$i]];
    }

    private static function format(string $digits): string
    {
        return substr($digits, 0, 2).' '.substr($digits, 2, 3).' '.substr($digits, 5, 3).' '.substr($digits, 8, 3);
    }
}
