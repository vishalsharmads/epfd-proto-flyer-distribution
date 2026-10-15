<?php

declare(strict_types=1);

namespace App\Domain\Gnaf;

use RuntimeException;

/**
 * Inspects a G-NAF release folder instead of assuming its layout.
 *
 * Finds the "Standard" PSV folder (files named {STATE}_{TABLE}_psv.psv), the "Authority Code"
 * folder, the release's table-creation SQL, and reads each PSV header to derive column names.
 * Works on the release root, the "G-NAF {MONTH YEAR}" folder or the Standard folder itself.
 */
final class GnafDiscovery
{
    /** Tables the importer needs, in load order. Required ones must exist for every state. */
    public const TABLES = [
        'STATE' => true,
        'LOCALITY' => true,
        'STREET_LOCALITY' => true,
        'ADDRESS_DETAIL' => true,
        'ADDRESS_DEFAULT_GEOCODE' => true,
        'ADDRESS_SITE' => false,
        'PRIMARY_SECONDARY' => false,
    ];

    /** @var array<string, array<string, array{path:string,size:int,columns:list<string>}>> state => table => file */
    public array $files = [];

    /** @var array<string, array{path:string,columns:list<string>}> */
    public array $authorityCodes = [];

    public ?string $standardDir = null;

    public ?string $authorityDir = null;

    public ?string $createTablesSql = null;

    /** @var array<string, list<string>> table => columns declared in the release SQL */
    public array $sqlColumns = [];

    /** @var list<string> */
    public array $documents = [];

    public ?string $releaseName = null;

    public function __construct(public readonly string $root) {}

    public static function scan(string $root): self
    {
        if (! is_dir($root)) {
            throw new RuntimeException("G-NAF path not found: {$root}");
        }
        $d = new self(rtrim($root, '/'));
        $d->locate();

        return $d;
    }

    /** @return list<string> */
    public function states(): array
    {
        return array_keys($this->files);
    }

    /**
     * @param  list<string>  $states
     * @return list<string> problems (empty = OK)
     */
    public function validate(array $states): array
    {
        $problems = [];
        foreach ($states as $s) {
            if (! isset($this->files[$s])) {
                $problems[] = "No PSV files found for state {$s}";

                continue;
            }
            foreach (self::TABLES as $table => $required) {
                if ($required && ! isset($this->files[$s][$table])) {
                    $problems[] = "{$s}: missing required table {$table}";
                }
            }
            $ad = $this->files[$s]['ADDRESS_DETAIL']['columns'] ?? [];
            foreach (['ADDRESS_DETAIL_PID', 'ALIAS_PRINCIPAL', 'LOCALITY_PID', 'STREET_LOCALITY_PID', 'NUMBER_FIRST', 'FLAT_NUMBER'] as $col) {
                if ($ad !== [] && ! in_array($col, $ad, true)) {
                    $problems[] = "{$s}: ADDRESS_DETAIL lacks column {$col}";
                }
            }
        }

        return $problems;
    }

    public function hasColumn(string $state, string $table, string $column): bool
    {
        return in_array($column, $this->files[$state][$table]['columns'] ?? [], true);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $states = [];
        foreach ($this->files as $s => $tables) {
            $states[$s] = [
                'tables' => count($tables),
                'address_detail_mb' => isset($tables['ADDRESS_DETAIL']) ? round($tables['ADDRESS_DETAIL']['size'] / 1048576, 1) : null,
                'has_primary_secondary' => isset($tables['PRIMARY_SECONDARY']),
                'has_address_site' => isset($tables['ADDRESS_SITE']),
            ];
        }

        return [
            'release' => $this->releaseName,
            'standard_dir' => $this->standardDir,
            'authority_dir' => $this->authorityDir,
            'create_tables_sql' => $this->createTablesSql,
            'documents' => $this->documents,
            'authority_codes' => array_keys($this->authorityCodes),
            'states' => $states,
            'address_detail_columns' => $this->files[array_key_first($this->files) ?? '']['ADDRESS_DETAIL']['columns'] ?? [],
            'sql_vs_psv_mismatches' => $this->sqlMismatches(),
        ];
    }

    private function locate(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $it->setMaxDepth(4);

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $name = $file->getFilename();
            $path = $file->getPathname();

            if ($file->isDir()) {
                if (preg_match('/^G-NAF\s+([A-Z]+\s+\d{4})$/i', $name, $m)) {
                    $this->releaseName = strtoupper($m[1]);
                }

                continue;
            }

            if (preg_match('/^([A-Z]{2,3})_([A-Z0-9_]+)_psv\.psv(?:\.gz)?$/i', $name, $m) && ! str_starts_with($name, 'Authority_Code')) {
                $state = strtoupper($m[1]);
                $table = strtoupper($m[2]);
                $this->standardDir ??= dirname($path);
                $this->files[$state][$table] = [
                    'path' => $path,
                    'size' => (int) $file->getSize(),
                    'columns' => self::header($path),
                ];
            } elseif (preg_match('/^Authority_Code_([A-Z0-9_]+)_psv\.psv$/i', $name, $m)) {
                $this->authorityDir ??= dirname($path);
                $this->authorityCodes[strtoupper($m[1])] = ['path' => $path, 'columns' => self::header($path)];
            } elseif (preg_match('/create_tables_ansi\.sql$/i', $name)) {
                $this->createTablesSql = $path;
                $this->sqlColumns = self::parseCreateTables((string) file_get_contents($path));
            } elseif (preg_match('/\.(pdf|txt|docx?)$/i', $name)) {
                $this->documents[] = substr($path, strlen($this->root) + 1);
            }
        }
        ksort($this->files);
    }

    /** @return list<string> */
    private static function header(string $path): array
    {
        $gz = str_ends_with($path, '.gz');
        $fh = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $line = (string) ($gz ? gzgets($fh) : fgets($fh));
        $gz ? gzclose($fh) : fclose($fh);
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line)) ?? '';

        return array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode('|', $line)), fn ($c) => $c !== ''));
    }

    /** @return array<string, list<string>> */
    private static function parseCreateTables(string $sql): array
    {
        $out = [];
        if (preg_match_all('/CREATE\s+TABLE\s+([A-Z0-9_]+)\s*\((.*?)\)\s*;/is', $sql, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $cols = [];
                foreach (preg_split('/,\s*\n/', $m[2]) ?: [] as $def) {
                    if (preg_match('/^\s*([A-Z0-9_]+)\s+[a-z]/i', $def, $c) && ! preg_match('/^\s*(CONSTRAINT|PRIMARY|FOREIGN)/i', $def)) {
                        $cols[] = strtoupper($c[1]);
                    }
                }
                $out[strtoupper($m[1])] = $cols;
            }
        }

        return $out;
    }

    /** @return array<string, array{only_in_psv:list<string>,only_in_sql:list<string>}> */
    private function sqlMismatches(): array
    {
        $first = $this->files[array_key_first($this->files) ?? ''] ?? [];
        $out = [];
        foreach ($first as $table => $f) {
            if (! isset($this->sqlColumns[$table])) {
                continue;
            }
            $a = array_values(array_diff($f['columns'], $this->sqlColumns[$table]));
            $b = array_values(array_diff($this->sqlColumns[$table], $f['columns']));
            if ($a || $b) {
                $out[$table] = ['only_in_psv' => $a, 'only_in_sql' => $b];
            }
        }

        return $out;
    }
}
