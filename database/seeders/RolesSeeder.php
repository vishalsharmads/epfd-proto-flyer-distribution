<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC: Super admin (everything via Gate::before), Operations, Finance, Support.
 * Permission names are referenced by routes/web/admin.php, routes/api.php and the admin sidebar.
 */
class RolesSeeder extends Seeder
{
    public const PERMISSIONS = [
        'businesses.view', 'businesses.manage', 'credits.manage',
        'campaigns.view', 'campaigns.manage',
        'print_orders.view', 'print_orders.manage',
        'design_orders.view', 'design_orders.manage',
        'pickup_points.manage', 'service_areas.manage',
        'rate_card.manage',
        'payments.view', 'payments.refund',
        'reports.view',
        'support.view', 'support.reply',
        'content.manage',
        'gnaf.view',
        'users.manage',
        'audit.view',
    ];

    public const ROLES = [
        'super_admin' => ['*'],
        'operations' => [
            'businesses.view', 'businesses.manage', 'campaigns.view', 'campaigns.manage', 'print_orders.view', 'print_orders.manage',
            'design_orders.view', 'design_orders.manage',
            'pickup_points.manage', 'service_areas.manage', 'support.view', 'support.reply', 'gnaf.view', 'reports.view',
        ],
        'finance' => [
            'businesses.view', 'credits.manage', 'campaigns.view', 'print_orders.view', 'payments.view', 'payments.refund',
            'rate_card.manage', 'reports.view', 'audit.view',
        ],
        'support' => [
            'businesses.view', 'campaigns.view', 'print_orders.view', 'design_orders.view', 'support.view', 'support.reply', 'content.manage',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Single 'web' guard: Spatie resolves the User model's guard to 'web' for Sanctum tokens too.
        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (self::ROLES as $role => $perms) {
            Role::findOrCreate($role, 'web')->syncPermissions($perms === ['*'] ? self::PERMISSIONS : $perms);
        }
    }
}
