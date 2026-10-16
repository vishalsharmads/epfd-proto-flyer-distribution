<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin settings: saved values override config/epfd.php at boot, so existing config() reads pick them up.
 */
final class Settings
{
    private const CACHE_KEY = 'settings:v1';

    /** Push saved values into config. Safe before migrations run. */
    public static function apply(): void
    {
        foreach (self::saved() as $key => $value) {
            $definition = SettingsRegistry::find($key);
            if ($definition) {
                config([$definition['config'] => self::cast($definition['cast'], $value)]);
            }
        }
    }

    /**
     * Current value of every setting (saved or default), with its definition, for the admin page.
     *
     * @return list<array<string, mixed>>
     */
    public static function forAdmin(): array
    {
        $saved = self::saved();

        return array_map(fn (array $d) => [
            ...$d,
            'value' => config($d['config']),
            'customised' => array_key_exists($d['key'], $saved),
        ], SettingsRegistry::all());
    }

    /**
     * Save changed values; keys not in the registry are ignored.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{before: mixed, after: mixed}> what changed
     */
    public static function save(array $values, ?User $by): array
    {
        $changes = [];
        DB::transaction(function () use ($values, $by, &$changes) {
            foreach ($values as $key => $value) {
                $definition = SettingsRegistry::find($key);
                if (! $definition) {
                    continue;
                }
                $value = self::cast($definition['cast'], $value);
                $before = config($definition['config']);
                if ($before === $value) {
                    continue;
                }
                Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
                config([$definition['config'] => $value]);
                $changes[$key] = ['before' => $before, 'after' => $value];
            }
        });
        Cache::forget(self::CACHE_KEY);

        return $changes;
    }

    /** @return array<string, mixed> */
    private static function saved(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('settings') ? Setting::pluck('value', 'key')->all() : []);
        } catch (Throwable) {
            return [];
        }
    }

    private static function cast(string $cast, mixed $value): mixed
    {
        return match ($cast) {
            'int' => (int) $value,
            'float' => (float) $value,
            default => (string) $value,
        };
    }
}
