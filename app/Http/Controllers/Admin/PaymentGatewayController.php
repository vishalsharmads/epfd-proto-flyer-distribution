<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\PaymentGatewayManager;
use App\Models\PaymentGatewayConfig;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Payment gateway credentials. Secrets are write-only: the page only shows whether each one is set.
 * The active gateway is chosen under Settings, which refuses a gateway whose credentials do not work.
 */
class PaymentGatewayController extends AdminController
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function index(): Response
    {
        $available = $this->gateways->available();
        $active = $this->gateways->getDefaultDriver();

        return Inertia::render('admin/settings/payment-gateways', [
            'gateways' => collect(PaymentGatewayManager::DRIVERS)->only(array_keys($available))->map(function (array $d, string $driver) use ($active) {
                $credentials = $this->gateways->credentials($driver);

                return [
                    'driver' => $driver,
                    'label' => $d['label'],
                    'active' => $driver === $active,
                    'mode' => $credentials['mode'],
                    'webhook_url' => route('api.webhooks', $driver),
                    'fields' => collect($d['fields'])->map(fn (array $f, string $key) => [
                        'key' => $key,
                        'label' => $f['label'],
                        'secret' => $f['secret'],
                        'set' => filled($credentials[$key] ?? null),
                        'value' => $f['secret'] ? null : ($credentials[$key] ?? null),
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    public function update(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, $this->gateways->available()), 404);
        $fields = PaymentGatewayManager::DRIVERS[$driver]['fields'];
        $data = $request->validate([
            'mode' => ['required', Rule::in(['test', 'live'])],
            ...collect($fields)->mapWithKeys(fn ($f, $key) => ["credentials.{$key}" => ['nullable', 'string', 'max:255']])->all(),
        ]);

        $config = PaymentGatewayConfig::findOrNew($driver);
        $existing = (array) $config->credentials;
        $credentials = $existing;
        foreach (array_keys($fields) as $key) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            // A blank secret keeps the saved value: the form never receives secrets, so it cannot echo them back.
            if ($value !== '' || ! $fields[$key]['secret']) {
                $credentials[$key] = $value === '' ? null : $value;
            }
        }
        $config->forceFill(['driver' => $driver, 'mode' => $data['mode'], 'credentials' => $credentials, 'updated_by' => $request->user()->id])->save();
        $this->gateways->refresh();

        Audit::log('payment_gateway.saved', null, [
            'driver' => $driver,
            'mode' => $data['mode'],
            'changed' => array_keys(array_diff_assoc(array_map('strval', $credentials), array_map('strval', $existing))),
        ]);

        return back()->with('success', PaymentGatewayManager::DRIVERS[$driver]['label'].' credentials saved.');
    }

    public function test(string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, $this->gateways->available()), 404);
        $error = self::verify($this->gateways, $driver);

        return back()->with($error ? 'error' : 'success', $error ?? 'Connected — the credentials work.');
    }

    /** Null when the driver resolves to itself (not a fallback) and its credentials work; otherwise why not. */
    public static function verify(PaymentGatewayManager $gateways, string $driver): ?string
    {
        try {
            $gateway = $gateways->driver($driver);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        if ($gateway->name() !== $driver) {
            return PaymentGatewayManager::DRIVERS[$driver]['label'].' is missing credentials. Enter them under Payment gateways first.';
        }

        return $gateway->verifyCredentials();
    }
}
