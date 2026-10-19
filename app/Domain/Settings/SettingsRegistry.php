<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Payments\PaymentGatewayManager;

/**
 * Every admin-editable setting: where it lives in config/epfd.php, how it is edited and validated.
 * The config value (env or file) is the default; a saved setting overrides it at boot (see Settings::apply).
 *
 * @phpstan-type Definition array{
 *     key: string, config: string, group: string, label: string, help: string,
 *     type: 'number'|'select', unit?: string, options?: array<string, string>, rules: list<string>, cast: 'int'|'float'|'string'
 * }
 */
final class SettingsRegistry
{
    /** @return list<Definition> */
    public static function all(): array
    {
        return [
            [
                'key' => 'map.stack', 'config' => 'epfd.map.stack', 'group' => 'Maps', 'type' => 'select', 'cast' => 'string',
                'label' => 'Map and drawing tools',
                'help' => 'Used by every map in the app. Google Maps needs GOOGLE_MAPS_KEY in .env, otherwise MapLibre + OSM is used.',
                'options' => [
                    'terra-google' => 'Google Maps + Terra Draw',
                    'terra-maplibre' => 'MapLibre + OSM + Terra Draw',
                    'classic' => 'MapLibre + OSM + original drawing',
                ],
                'rules' => ['required', 'in:terra-google,terra-maplibre,classic'],
            ],
            [
                'key' => 'payments.gateway', 'config' => 'epfd.payments.gateway', 'group' => 'Payments', 'type' => 'select', 'cast' => 'string',
                'label' => 'Payment gateway',
                'help' => 'Takes every new card payment. Enter its keys under Payments → Gateways first. Refunds always go back through the gateway that took the payment.',
                'options' => $gateways = app(PaymentGatewayManager::class)->available(),
                'rules' => ['required', 'in:'.implode(',', array_keys($gateways))],
            ],
            [
                'key' => 'areas.max_walk_km', 'config' => 'epfd.areas.max_walk_km', 'group' => 'Areas and runs', 'type' => 'number', 'cast' => 'float', 'unit' => 'km',
                'label' => 'Walking limit per area',
                'help' => 'Each area goes to one distributor. Areas with a longer estimated walk must be split before they can be saved.',
                'rules' => ['required', 'numeric', 'min:1', 'max:200'],
            ],
            [
                'key' => 'areas.split_min_letterboxes', 'config' => 'epfd.areas.split_min_letterboxes', 'group' => 'Areas and runs', 'type' => 'number', 'cast' => 'int', 'unit' => 'letterboxes',
                'label' => 'Smallest split piece',
                'help' => 'When an area is split, pieces with fewer letterboxes than this are merged into a neighbouring piece.',
                'rules' => ['required', 'integer', 'min:0', 'max:5000'],
            ],
            [
                'key' => 'areas.pickup_radius_km', 'config' => 'epfd.areas.pickup_radius_km', 'group' => 'Areas and runs', 'type' => 'number', 'cast' => 'int', 'unit' => 'km',
                'label' => 'Pickup radius',
                'help' => 'Every area must be at least partly within this distance of the pickup point.',
                'rules' => ['required', 'integer', 'min:1', 'max:200'],
            ],
            [
                'key' => 'estimator.max_segment_gap_m', 'config' => 'epfd.estimator.max_segment_gap_m', 'group' => 'Walking distance estimate', 'type' => 'number', 'cast' => 'int', 'unit' => 'm',
                'label' => 'Longest gap walked on one street',
                'help' => 'Gaps between neighbouring addresses longer than this are treated as a break and not counted.',
                'rules' => ['required', 'integer', 'min:50', 'max:5000'],
            ],
            [
                'key' => 'estimator.connector_cap_m', 'config' => 'epfd.estimator.connector_cap_m', 'group' => 'Walking distance estimate', 'type' => 'number', 'cast' => 'int', 'unit' => 'm',
                'label' => 'Longest walk between streets',
                'help' => 'Each walk from one street to the next is capped at this distance.',
                'rules' => ['required', 'integer', 'min:10', 'max:5000'],
            ],
            [
                'key' => 'viewport.min_zoom', 'config' => 'epfd.viewport.min_zoom', 'group' => 'Address dots on the map', 'type' => 'number', 'cast' => 'int',
                'label' => 'Zoom level to show addresses',
                'help' => 'Whole-map addresses appear from this zoom level (16 is street level).',
                'rules' => ['required', 'integer', 'min:12', 'max:19'],
            ],
            [
                'key' => 'viewport.max_points', 'config' => 'epfd.viewport.max_points', 'group' => 'Address dots on the map', 'type' => 'number', 'cast' => 'int', 'unit' => 'addresses',
                'label' => 'Most addresses drawn at once',
                'help' => 'Caps the address dots per screen to keep the map fast.',
                'rules' => ['required', 'integer', 'min:100', 'max:20000'],
            ],
        ];
    }

    /** @return Definition|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }
}
