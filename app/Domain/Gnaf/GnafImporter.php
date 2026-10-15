<?php

declare(strict_types=1);

namespace App\Domain\Gnaf;

use App\Models\GnafRelease;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Bulk G-NAF loader.
 *
 *  1. COPY each state's PSV files into per-state staging tables (schema gnaf_stg), all TEXT columns
 *     named after the PSV header. Streaming is done by Postgres/psql — no PHP row loops.
 *     Progress is recorded in gnaf_stg._progress (path, size, mtime, rows) so a re-run skips
 *     files already loaded (resumable) and re-loads changed ones (idempotent).
 *  2. Transform per state with one set-based INSERT … SELECT into `addresses`
 *     (rules from AddressClassifier), after deleting that state's previous rows.
 *  3. Flag primaries with units (PRIMARY_SECONDARY → ADDRESS_SITE → street-number fallback).
 *  4. Rebuild indexes, ANALYZE, derive suburb boundaries + stats, record gnaf_releases.
 */
final class GnafImporter
{
    private const STG = 'gnaf_stg';

    /** @var Closure(string):void */
    private Closure $log;

    public function __construct(
        private readonly AddressClassifier $classifier,
        private readonly SuburbBuilder $suburbs,
    ) {
        $this->log = static fn (string $m) => null;
    }

    /** @param Closure(string):void $log */
    public function onLog(Closure $log): self
    {
        $this->log = $log;

        return $this;
    }

    /**
     * @param  list<string>  $states
     */
    public function import(GnafDiscovery $d, array $states, string $label, bool $isSample = false, bool $fresh = false): GnafRelease
    {
        $problems = $d->validate($states);
        if ($problems !== []) {
            throw new RuntimeException("G-NAF release is not importable:\n - ".implode("\n - ", $problems));
        }

        $release = GnafRelease::create([
            'label' => $label,
            'crs' => 'GDA2020',
            'srid' => 7844,
            'states' => $states,
            'is_sample' => $isSample,
            'status' => 'running',
            'source_path' => $isSample ? 'database/gnaf-sample' : $d->root,
            'discovery' => $d->summary(),
            'started_at' => now(),
        ]);

        try {
            DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::STG);
            DB::statement('CREATE TABLE IF NOT EXISTS '.self::STG.'._progress (state text, tbl text, path text, size bigint, mtime bigint, rows bigint, loaded_at timestamptz, PRIMARY KEY (state, tbl))');
            if ($fresh) {
                DB::table(self::STG.'._progress')->whereIn('state', $states)->delete();
            }

            $rowCounts = [];
            foreach ($states as $state) {
                foreach (GnafDiscovery::TABLES as $table => $required) {
                    if (! isset($d->files[$state][$table])) {
                        continue;
                    }
                    $rowCounts[$state][$table] = $this->stage($state, $table, $d->files[$state][$table]);
                }
            }

            $this->dropAddressIndexes();
            $skipped = [];
            foreach ($states as $state) {
                $skipped[$state] = $this->transform($d, $state);
                $this->flagPrimaries($d, $state);
            }
            $this->createAddressIndexes();

            $this->log('Building suburb boundaries (ST_ConcaveHull over address points)…');
            $built = $this->suburbs->rebuildDerived($states);
            $this->log("  {$built} derived suburb boundaries");
            $this->suburbs->refreshStats($states);

            $kindCounts = $this->kindCounts($states);
            $release->update([
                'status' => 'completed',
                'row_counts' => $rowCounts,
                'skipped_counts' => $skipped,
                'kind_counts' => $kindCounts,
                'imported_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $release->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }

        return $release->fresh() ?? $release;
    }

    /**
     * @param  array{path:string,size:int,columns:list<string>}  $file
     */
    private function stage(string $state, string $table, array $file): int
    {
        $stg = $this->stgTable($state, $table);
        $mtime = (int) filemtime($file['path']);
        $done = DB::table(self::STG.'._progress')->where(['state' => $state, 'tbl' => $table])->first();
        $exists = DB::selectOne('SELECT to_regclass(?) AS t', [$stg])->t !== null;

        if ($done && $exists && (int) $done->size === $file['size'] && (int) $done->mtime === $mtime) {
            $this->log(sprintf('  %-4s %-24s already staged (%s rows) — skipping', $state, $table, number_format((int) $done->rows)));

            return (int) $done->rows;
        }

        $cols = array_map(fn ($c) => strtolower($c), $file['columns']);
        DB::statement("DROP TABLE IF EXISTS {$stg}");
        DB::statement("CREATE UNLOGGED TABLE {$stg} (".implode(', ', array_map(fn ($c) => "\"{$c}\" text", $cols)).')');

        $started = microtime(true);
        $this->copy($stg, $cols, $file['path']);
        // Fresh staging tables have no statistics; without this the planner picks nested loops.
        DB::statement("ANALYZE {$stg}");
        $rows = (int) DB::selectOne("SELECT count(*) AS n FROM {$stg}")->n;

        DB::table(self::STG.'._progress')->upsert([[
            'state' => $state, 'tbl' => $table, 'path' => $file['path'], 'size' => $file['size'],
            'mtime' => $mtime, 'rows' => $rows, 'loaded_at' => now(),
        ]], ['state', 'tbl'], ['path', 'size', 'mtime', 'rows', 'loaded_at']);

        $this->log(sprintf('  %-4s %-24s %12s rows  %5.1fs', $state, $table, number_format($rows), microtime(true) - $started));

        return $rows;
    }

    /** @param list<string> $cols */
    private function copy(string $stg, array $cols, string $path): void
    {
        $colList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $cols));
        // QUOTE E'\x01' disables CSV quoting (PSV fields may contain stray quotes); empty → NULL.
        $opts = "FORMAT csv, DELIMITER '|', HEADER true, QUOTE E'\\x01', NULL ''";
        $quotedPath = "'".str_replace("'", "''", $path)."'";
        // Gzipped PSVs (the bundled sample) are streamed through gzip.
        $source = str_ends_with($path, '.gz')
            ? "PROGRAM 'gzip -dc ".str_replace("'", "''", escapeshellarg($path))."'"
            : $quotedPath;

        $psql = (new ExecutableFinder)->find('psql', null, ['/opt/homebrew/opt/postgresql@17/bin', '/opt/homebrew/bin', '/usr/bin', '/usr/local/bin']);
        if ($psql !== null) {
            $c = config('database.connections.'.config('database.default'));
            $process = new Process(
                [$psql, '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-h', (string) $c['host'], '-p', (string) $c['port'], '-U', (string) $c['username'], '-d', (string) $c['database'],
                    '-c', "\\copy {$stg} ({$colList}) FROM {$source} WITH ({$opts})"],
                null,
                ['PGPASSWORD' => (string) ($c['password'] ?? '')],
                null,
                null,
            );
            $process->mustRun();

            return;
        }

        // Fallback: server-side COPY (requires the DB server to see the file and pg_read_server_files).
        DB::statement("COPY {$stg} ({$colList}) FROM {$source} WITH ({$opts})");
    }

    /** @return array<string, int> skipped counts */
    private function transform(GnafDiscovery $d, string $state): array
    {
        $started = microtime(true);
        $ad = $this->stgTable($state, 'ADDRESS_DETAIL');
        $geo = $this->stgTable($state, 'ADDRESS_DEFAULT_GEOCODE');
        $sl = $this->stgTable($state, 'STREET_LOCALITY');
        $loc = $this->stgTable($state, 'LOCALITY');
        $hasPs = $d->hasColumn($state, 'ADDRESS_DETAIL', 'PRIMARY_SECONDARY');
        $hasSite = $d->hasColumn($state, 'ADDRESS_DETAIL', 'ADDRESS_SITE_PID');
        $filter = $this->classifier->importFilterSql('ad');
        $kind = $this->classifier->kindSql('ad', $hasPs);
        $commercial = $this->classifier->commercialSql('ad');
        $s = DB::getPdo()->quote($state);

        $counts = (array) DB::selectOne("
            SELECT count(*) AS total,
                   count(*) FILTER (WHERE alias_principal IS DISTINCT FROM 'P') AS alias,
                   count(*) FILTER (WHERE date_retired IS NOT NULL) AS retired
            FROM {$ad}");

        DB::table('addresses')->where('state', $state)->delete();

        $fullAddress = <<<'SQL'
            concat_ws(' ',
              CASE WHEN ad.flat_number IS NOT NULL THEN coalesce(ad.flat_type_code || ' ', '') || coalesce(ad.flat_number_prefix,'') || ad.flat_number || coalesce(ad.flat_number_suffix,'') || '/' END
              || coalesce(ad.number_first_prefix,'') || coalesce(ad.number_first, ad.lot_number, '') || coalesce(ad.number_first_suffix,'')
              || CASE WHEN ad.number_last IS NOT NULL THEN '-' || ad.number_last ELSE '' END,
              sl.street_name, sl.street_type_code, sl.street_suffix_code) || ', ' || l.locality_name || ' ' || :state || ' ' || coalesce(ad.postcode,'')
        SQL;
        $fullAddress = str_replace(':state', $s, $fullAddress);

        $inserted = DB::affectingStatement("
            INSERT INTO addresses (gnaf_pid, state, locality, postcode, street_name, street_type, street_suffix, street_locality_pid,
                number_first, number_last, flat_type, flat_number, level_number, building_name, lot_number,
                address_kind, is_primary_with_units, is_commercial, address_site_pid, geocode_type, confidence,
                full_address, search_text, geom)
            SELECT ad.address_detail_pid, {$s}, l.locality_name, ad.postcode, sl.street_name, sl.street_type_code, sl.street_suffix_code, ad.street_locality_pid,
                CASE WHEN ad.number_first ~ '^\\d+$' THEN ad.number_first::int END,
                CASE WHEN ad.number_last ~ '^\\d+$' THEN ad.number_last::int END,
                ad.flat_type_code, coalesce(ad.flat_number_prefix,'') || coalesce(ad.flat_number,'') || coalesce(ad.flat_number_suffix,''),
                coalesce(ad.level_type_code || ' ', '') || coalesce(ad.level_number,''),
                ad.building_name, ad.lot_number,
                {$kind}, false, {$commercial}, ".($hasSite ? 'ad.address_site_pid' : 'NULL').", g.geocode_type_code,
                CASE WHEN ad.confidence ~ '^-?\\d+$' THEN ad.confidence::smallint END,
                {$fullAddress},
                lower({$fullAddress}),
                ST_SetSRID(ST_MakePoint(g.longitude::float8, g.latitude::float8), 7844)
            FROM {$ad} ad
            JOIN (SELECT DISTINCT ON (address_detail_pid) * FROM {$geo} WHERE date_retired IS NULL ORDER BY address_detail_pid, date_created DESC) g
              ON g.address_detail_pid = ad.address_detail_pid
            JOIN {$loc} l ON l.locality_pid = ad.locality_pid
            LEFT JOIN {$sl} sl ON sl.street_locality_pid = ad.street_locality_pid
            WHERE {$filter}
              AND g.longitude IS NOT NULL AND g.latitude IS NOT NULL
        ");

        $eligible = (int) DB::selectOne("SELECT count(*) AS n FROM {$ad} ad WHERE {$filter}")->n;
        $skipped = [
            'source_rows' => (int) $counts['total'],
            'aliases' => (int) $counts['alias'],
            'retired' => (int) $counts['retired'],
            'no_geocode' => max(0, $eligible - $inserted),
            'imported' => $inserted,
        ];
        $this->log(sprintf('  %-4s → addresses %12s rows (%s aliases, %s retired, %s without geocode skipped) %5.1fs',
            $state, number_format($inserted), number_format($skipped['aliases']), number_format($skipped['retired']),
            number_format($skipped['no_geocode']), microtime(true) - $started));

        return $skipped;
    }

    private function flagPrimaries(GnafDiscovery $d, string $state): void
    {
        $s = DB::getPdo()->quote($state);
        if (isset($d->files[$state]['PRIMARY_SECONDARY'])) {
            $ps = $this->stgTable($state, 'PRIMARY_SECONDARY');
            $n = DB::affectingStatement("
                UPDATE addresses a SET is_primary_with_units = true
                FROM (SELECT DISTINCT primary_pid FROM {$ps} WHERE date_retired IS NULL) p
                WHERE a.gnaf_pid = p.primary_pid AND a.state = {$s}");
            DB::statement("
                UPDATE addresses a SET primary_pid = p.primary_pid
                FROM (SELECT DISTINCT ON (secondary_pid) secondary_pid, primary_pid FROM {$ps} WHERE date_retired IS NULL) p
                WHERE a.gnaf_pid = p.secondary_pid AND a.state = {$s}");
            $this->log("  {$state}: {$n} primaries flagged via PRIMARY_SECONDARY");
        }

        // Fallback for buildings not covered by PRIMARY_SECONDARY: same site, else same street number.
        $n = DB::affectingStatement("
            UPDATE addresses h SET is_primary_with_units = true
            FROM (
                SELECT DISTINCT coalesce(address_site_pid, street_locality_pid || '#' || number_first) AS k
                FROM addresses WHERE state = {$s} AND address_kind = 'unit' AND primary_pid IS NULL
                  AND (address_site_pid IS NOT NULL OR number_first IS NOT NULL)
            ) u
            WHERE h.state = {$s} AND h.address_kind = 'house' AND NOT h.is_primary_with_units
              AND coalesce(h.address_site_pid, h.street_locality_pid || '#' || h.number_first) = u.k");
        $this->log("  {$state}: {$n} primaries flagged via site/street-number grouping");
    }

    private function dropAddressIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS addresses_geom_gist');
        DB::statement('DROP INDEX IF EXISTS addresses_search_trgm');
        DB::statement('DROP INDEX IF EXISTS addresses_state_locality_postcode_index');
    }

    private function createAddressIndexes(): void
    {
        $this->log('Building indexes (GIST geom, btree state/locality/postcode, GIN trigram search) + ANALYZE…');
        $t = microtime(true);
        DB::statement('SET maintenance_work_mem = \'1GB\'');
        DB::statement('CREATE INDEX IF NOT EXISTS addresses_geom_gist ON addresses USING GIST (geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS addresses_state_locality_postcode_index ON addresses (state, locality, postcode)');
        DB::statement('CREATE INDEX IF NOT EXISTS addresses_search_trgm ON addresses USING GIN (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS addresses_street_idx ON addresses (street_locality_pid)');
        DB::statement('ANALYZE addresses');
        $this->log(sprintf('  done in %.1fs', microtime(true) - $t));
    }

    /**
     * @param  list<string>  $states
     * @return array<string, array<string, int>>
     */
    private function kindCounts(array $states): array
    {
        $countable = $this->classifier->countableSql('a');
        $rows = DB::select("
            SELECT state,
                   count(*) FILTER (WHERE address_kind='house') AS houses,
                   count(*) FILTER (WHERE address_kind='unit') AS units,
                   count(*) FILTER (WHERE is_primary_with_units) AS primary_with_units,
                   count(*) FILTER (WHERE is_commercial) AS commercial,
                   count(*) FILTER (WHERE {$countable}) AS letterboxes,
                   count(*) AS total
            FROM addresses a WHERE state = ANY(?) GROUP BY state ORDER BY state", ['{'.implode(',', $states).'}']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->state] = array_map('intval', array_diff_key((array) $r, ['state' => 1]));
        }

        return $out;
    }

    private function stgTable(string $state, string $table): string
    {
        return self::STG.'.'.strtolower($state.'_'.$table);
    }

    private function log(string $m): void
    {
        ($this->log)($m);
    }
}
