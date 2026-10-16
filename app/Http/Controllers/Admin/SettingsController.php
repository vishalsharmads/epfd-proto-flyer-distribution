<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends AdminController
{
    public function show(): Response
    {
        return Inertia::render('admin/settings/index', [
            'settings' => Settings::forAdmin(),
            'google_key_set' => (bool) config('epfd.map.google_key'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (SettingsRegistry::all() as $definition) {
            $rules['values.'.$definition['key']] = ['sometimes', ...$definition['rules']];
        }
        $values = $request->validate(['values' => ['required', 'array'], ...$rules])['values'];

        $changes = Settings::save($this->flatten($values), $request->user());
        if ($changes) {
            Audit::log('settings.saved', null, $changes);
        }

        return back()->with('success', $changes ? 'Settings saved.' : 'No changes to save.');
    }

    /**
     * Validated input arrives nested ("map" => ["stack" => …]); registry keys are dotted.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
