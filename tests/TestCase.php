<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests run against PostgreSQL + PostGIS (`epfd_test`). The real G-NAF SAMPLE (database/gnaf-sample)
 * and base seed data are loaded ONCE per schema version; each test then runs inside a transaction
 * (DatabaseTransactions) so it is isolated and fast.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$prepared) {
            $this->prepareDatabase();
            self::$prepared = true;
        }
    }

    private function prepareDatabase(): void
    {
        $fingerprint = $this->schemaFingerprint();
        $current = Schema::hasTable('test_fixture_state')
            ? DB::table('test_fixture_state')->value('fingerprint')
            : null;

        if ($current === $fingerprint) {
            return;
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('gnaf:import', ['--sample' => true]);
        Artisan::call('db:seed', ['--force' => true]);
        DB::statement('CREATE TABLE IF NOT EXISTS test_fixture_state (fingerprint text)');
        DB::table('test_fixture_state')->delete();
        DB::table('test_fixture_state')->insert(['fingerprint' => $fingerprint]);
    }

    /** Changes whenever a migration, seeder or the sample data changes. */
    private function schemaFingerprint(): string
    {
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(database_path('seeders/*.php')) ?: [],
            glob(database_path('gnaf-sample/Standard/*')) ?: [],
        );
        sort($files);

        return md5(implode('|', array_map(fn ($f) => basename($f).':'.filemtime($f), $files)));
    }
}
