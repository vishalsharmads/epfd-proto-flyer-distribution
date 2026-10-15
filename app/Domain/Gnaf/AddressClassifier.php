<?php

declare(strict_types=1);

namespace App\Domain\Gnaf;

/**
 * The single source of truth for how a G-NAF ADDRESS_DETAIL row becomes a letterbox.
 *
 * Every rule is driven by a flag in config('epfd.gnaf.rules') with the default noted below.
 * The class has two faces that MUST stay in sync:
 *   - classify()/countsAsLetterbox(): row-level PHP logic used by unit tests and small jobs.
 *   - the *Sql() methods: the same rules as SQL fragments used by the bulk importer and estimator,
 *     so tens of millions of rows never pass through PHP.
 *
 * Rules
 *  1. Principal only (principal_only = true): ALIAS_PRINCIPAL must be 'P'. Aliases are skipped.
 *     Retired rows (skip_retired = true): DATE_RETIRED set → skipped.
 *  2. Unit vs house: a row is a UNIT when it has a FLAT_NUMBER or FLAT_TYPE_CODE, or
 *     (secondary_is_unit = true) PRIMARY_SECONDARY = 'S'. Otherwise it is a HOUSE.
 *  3. Anti double-count (exclude_primary_with_units = true): a building's primary address that has
 *     secondary children is flagged is_primary_with_units and excluded from counts, because its
 *     letterboxes are the units. Source of truth: PRIMARY_SECONDARY table if present, else
 *     ADDRESS_SITE grouping, else (locality, street, number_first) grouping.
 *     Alternative (include_unit_buildings_as_single_letterbox = false): when true, a unit building
 *     counts as ONE letterbox (the primary is kept, its units are dropped).
 *  4. Geometry from ADDRESS_DEFAULT_GEOCODE (lon/lat → SRID 7844). require_geocode = true: rows
 *     with no default geocode are skipped and counted in gnaf_releases.skipped_counts.
 *  5. exclude_commercial_when_available = true: flat types in commercial_flat_types
 *     (SHOP, OFFC, WHSE…) are flagged is_commercial and excluded from counts.
 */
final class AddressClassifier
{
    public const KIND_HOUSE = 'house';

    public const KIND_UNIT = 'unit';

    /** @param array<string, mixed> $rules */
    public function __construct(private readonly array $rules) {}

    public static function fromConfig(): self
    {
        return new self((array) config('epfd.gnaf.rules'));
    }

    public function rule(string $key): mixed
    {
        return $this->rules[$key] ?? null;
    }

    /**
     * Rule 1 + 4: should this raw row be imported at all?
     *
     * @param  array<string, mixed>  $row  ADDRESS_DETAIL columns (upper-case keys) + optional LONGITUDE/LATITUDE
     */
    public function shouldImport(array $row): bool
    {
        if (($this->rules['principal_only'] ?? true) && strtoupper((string) ($row['ALIAS_PRINCIPAL'] ?? '')) !== 'P') {
            return false;
        }
        if (($this->rules['skip_retired'] ?? true) && ! self::blank($row['DATE_RETIRED'] ?? null)) {
            return false;
        }
        if (($this->rules['require_geocode'] ?? true) && (self::blank($row['LONGITUDE'] ?? null) || self::blank($row['LATITUDE'] ?? null))) {
            return false;
        }

        return true;
    }

    /** Rule 2. */
    public function kind(array $row): string
    {
        $hasFlat = ! self::blank($row['FLAT_NUMBER'] ?? null) || ! self::blank($row['FLAT_TYPE_CODE'] ?? null);
        $isSecondary = ($this->rules['secondary_is_unit'] ?? true) && strtoupper((string) ($row['PRIMARY_SECONDARY'] ?? '')) === 'S';

        return ($hasFlat || $isSecondary) ? self::KIND_UNIT : self::KIND_HOUSE;
    }

    /** Rule 5. */
    public function isCommercial(array $row): bool
    {
        $type = strtoupper((string) ($row['FLAT_TYPE_CODE'] ?? ''));

        return $type !== '' && in_array($type, $this->commercialTypes(), true);
    }

    /**
     * Rule 3: given a set of imported rows, flag primaries that have units.
     * Rows are matched by PRIMARY_SECONDARY links when provided, else by site, else by street number.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{0:string,1:string}>  $links  [primary_pid, secondary_pid]
     * @return array<string, bool> gnaf_pid => is_primary_with_units
     */
    public function primariesWithUnits(array $rows, array $links = []): array
    {
        $flags = [];
        foreach ($rows as $r) {
            $flags[(string) $r['ADDRESS_DETAIL_PID']] = false;
        }

        if ($links !== []) {
            foreach ($links as [$primary]) {
                if (array_key_exists($primary, $flags)) {
                    $flags[$primary] = true;
                }
            }

            return $flags;
        }

        $groups = [];
        foreach ($rows as $r) {
            $key = ! self::blank($r['ADDRESS_SITE_PID'] ?? null)
                ? 'site:'.$r['ADDRESS_SITE_PID']
                : 'num:'.($r['LOCALITY_PID'] ?? '').'|'.($r['STREET_LOCALITY_PID'] ?? '').'|'.($r['NUMBER_FIRST'] ?? '');
            $groups[$key][] = $r;
        }
        foreach ($groups as $members) {
            $hasUnit = false;
            foreach ($members as $m) {
                $hasUnit = $hasUnit || $this->kind($m) === self::KIND_UNIT;
            }
            if (! $hasUnit) {
                continue;
            }
            foreach ($members as $m) {
                if ($this->kind($m) === self::KIND_HOUSE) {
                    $flags[(string) $m['ADDRESS_DETAIL_PID']] = true;
                }
            }
        }

        return $flags;
    }

    /**
     * Does an imported address count towards letterbox totals?
     *
     * @param  array{address_kind:string,is_primary_with_units:bool,is_commercial:bool}  $a
     */
    public function countsAsLetterbox(array $a): bool
    {
        if (($this->rules['exclude_commercial_when_available'] ?? true) && $a['is_commercial']) {
            return false;
        }
        if ($this->rule('include_unit_buildings_as_single_letterbox')) {
            // Building counted once via its primary; individual units are not counted.
            return $a['address_kind'] === self::KIND_HOUSE;
        }
        if (($this->rules['exclude_primary_with_units'] ?? true) && $a['is_primary_with_units']) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------- SQL faces

    /** WHERE fragment over a staging ADDRESS_DETAIL alias (rule 1). */
    public function importFilterSql(string $ad = 'ad'): string
    {
        $parts = ['TRUE'];
        if ($this->rules['principal_only'] ?? true) {
            $parts[] = "{$ad}.alias_principal = 'P'";
        }
        if ($this->rules['skip_retired'] ?? true) {
            $parts[] = "{$ad}.date_retired IS NULL";
        }

        return implode(' AND ', $parts);
    }

    /** CASE expression producing 'house'|'unit' (rule 2). */
    public function kindSql(string $ad = 'ad', bool $hasPrimarySecondaryColumn = true): string
    {
        $cond = "{$ad}.flat_number IS NOT NULL OR {$ad}.flat_type_code IS NOT NULL";
        if (($this->rules['secondary_is_unit'] ?? true) && $hasPrimarySecondaryColumn) {
            $cond .= " OR {$ad}.primary_secondary = 'S'";
        }

        return "CASE WHEN {$cond} THEN 'unit' ELSE 'house' END";
    }

    /** Boolean expression for rule 5. */
    public function commercialSql(string $ad = 'ad'): string
    {
        $list = implode(',', array_map(fn ($t) => "'".preg_replace('/[^A-Z]/', '', $t)."'", $this->commercialTypes()));

        return "COALESCE({$ad}.flat_type_code IN ({$list}), false)";
    }

    /** WHERE fragment over the `addresses` table for counting letterboxes (rules 3 + 5). */
    public function countableSql(string $a = 'a'): string
    {
        $parts = ['TRUE'];
        if ($this->rules['exclude_commercial_when_available'] ?? true) {
            $parts[] = "NOT {$a}.is_commercial";
        }
        if ($this->rule('include_unit_buildings_as_single_letterbox')) {
            $parts[] = "{$a}.address_kind = 'house'";
        } elseif ($this->rules['exclude_primary_with_units'] ?? true) {
            $parts[] = "NOT {$a}.is_primary_with_units";
        }

        return implode(' AND ', $parts);
    }

    /**
     * In single-letterbox mode a unit-building primary is counted as a "unit" letterbox
     * so the houses/units split stays meaningful.
     */
    public function kindForCountSql(string $a = 'a'): string
    {
        if ($this->rule('include_unit_buildings_as_single_letterbox')) {
            return "CASE WHEN {$a}.is_primary_with_units THEN 'unit' ELSE {$a}.address_kind END";
        }

        return "{$a}.address_kind";
    }

    /** @return list<string> */
    private function commercialTypes(): array
    {
        return array_values(array_map('strtoupper', (array) ($this->rules['commercial_flat_types'] ?? [])));
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }
}
