<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Staff + distributor accounts. Deactivation removes every role, rotates the password and revokes API
 * tokens (there is no is_active column; `preferences.deactivated_at` records it for the UI).
 */
class UserController extends AdminController
{
    public function index(Request $request): Response
    {
        $tab = $request->input('tab') === 'distributors' ? 'distributors' : 'staff';
        $q = trim((string) $request->input('q'));
        $query = $tab === 'staff'
            ? User::query()->where(fn ($w) => $w->whereHas('roles')->orWhere('preferences->deactivated_staff', true))
            : User::query()->where('is_distributor', true);
        $rows = $query->with('roles:id,name')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->when($tab === 'distributors', fn ($w) => $w->select('users.*')->selectRaw('(SELECT count(*) FROM runs WHERE runs.distributor_id = users.id) AS runs_count'))
            ->orderBy('name')->paginate(20)->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'mobile' => $u->mobile,
                'roles' => $u->roles->pluck('name'), 'last_login_at' => $u->last_login_at, 'created_at' => $u->created_at,
                'deactivated_at' => $u->preferences['deactivated_at'] ?? null,
                'runs_count' => $u->runs_count ?? null,
            ]);

        $roles = Role::with('permissions:id,name')->orderBy('id')->get();

        return Inertia::render('admin/users/index', [
            'tab' => $tab,
            'users' => $rows,
            'filters' => ['q' => $q ?: null],
            'roles' => $roles->pluck('name'),
            'permissions' => RolesSeeder::PERMISSIONS,
            'matrix' => $roles->mapWithKeys(fn ($r) => [$r->name => $r->name === 'super_admin' ? RolesSeeder::PERMISSIONS : $r->permissions->pluck('name')->all()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'kind' => ['required', 'in:staff,distributor'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^(\+?61|0)4\d{2}\s?\d{3}\s?\d{3}$/'],
            'role' => ['required_if:kind,staff', 'nullable', Rule::in(User::ADMIN_ROLES)],
            'send_invite' => ['boolean'],
        ], ['mobile.regex' => 'Enter an Australian mobile, e.g. 0412 345 678.']);
        $user = User::create([
            'name' => $v['name'], 'email' => strtolower($v['email']), 'mobile' => $v['mobile'] ?? null,
            'password' => Str::password(24), 'email_verified_at' => now(), 'is_distributor' => $v['kind'] === 'distributor',
        ]);
        if ($v['kind'] === 'staff') {
            $user->assignRole($v['role']);
        }
        $invited = false;
        if ($v['send_invite'] ?? true) {
            try {
                $invited = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        Audit::log('user.created', $user, ['kind' => $v['kind'], 'role' => $v['role'] ?? null, 'invited' => $invited]);

        return back()->with('success', "{$user->name} created".($invited ? ' — invite email sent.' : '.'));
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $v = $request->validate(['role' => ['required', Rule::in(User::ADMIN_ROLES)]]);
        if ($user->is($request->user()) && $v['role'] !== 'super_admin' && $user->hasRole('super_admin')) {
            return back()->with('error', "You can't remove your own super admin role.");
        }
        $before = $user->getRoleNames()->all();
        $user->syncRoles([$v['role']]);
        Audit::log('user.role_changed', $user, ['from' => $before, 'to' => [$v['role']]]);

        return back()->with('success', "{$user->name} is now ".str_replace('_', ' ', $v['role']).'.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', "You can't deactivate yourself.");
        }
        $roles = $user->getRoleNames()->all();
        $user->syncRoles([]);
        $user->tokens()->delete();
        $prefs = $user->preferences ?? [];
        $prefs['deactivated_at'] = now()->toIso8601String();
        $prefs['deactivated_staff'] = $roles !== [];
        $prefs['previous_roles'] = $roles;
        $user->forceFill(['password' => Str::password(32), 'remember_token' => Str::random(60), 'preferences' => $prefs])->save();
        Audit::log('user.deactivated', $user, ['roles' => $roles]);

        return back()->with('success', "{$user->name} deactivated.");
    }
}
