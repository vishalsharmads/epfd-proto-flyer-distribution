<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
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

    /**
     * actingAs() only swaps the user, while a real login (SessionGuard::login) also records the new user's
     * password hash in the session. Without this, switching users mid-test trips AuthenticateSession.
     */
    public function be(Authenticatable $user, $guard = null): static
    {
        $this->app['session.store']->forget('password_hash_'.($guard ?? config('auth.defaults.guard')));

        return parent::be($user, $guard);
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

        // Run in a separate process: this test's DatabaseTransactions transaction is already open, and the
        // importer's COPY uses a second connection (psql) that must see committed tables.
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        foreach (['migrate:fresh --force', 'gnaf:import --sample', 'db:seed --force'] as $cmd) {
            Process::timeout(600)->env($env)->path(base_path())->run('php artisan '.$cmd)->throw();
        }
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
