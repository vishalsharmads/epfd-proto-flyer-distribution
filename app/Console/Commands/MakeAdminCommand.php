<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Create (or promote) a staff user. Used to make the first admin in production, where no demo accounts are seeded.
 *   epfd:make-admin                                   → prompts for everything
 *   epfd:make-admin ops@epfd.com.au --role=operations → prompts for name and password
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'epfd:make-admin {email? : Login email} {--name= : Display name} {--role= : super_admin, operations, finance or support}';

    protected $description = 'Create or promote a staff user with an admin role';

    public function handle(): int
    {
        $roles = array_keys(RolesSeeder::ROLES);
        $email = (string) ($this->argument('email') ?: text('Email', required: true));
        $role = (string) ($this->option('role') ?: select('Role', $roles, default: 'super_admin'));
        $existing = User::where('email', $email)->first();
        $name = (string) ($this->option('name') ?: ($existing->name ?? text('Name', required: true)));
        $plain = $existing ? null : password('Password (at least 12 characters)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'role' => $role, 'name' => $name, 'password' => $plain],
            [
                'email' => ['required', 'email'],
                'role' => ['required', 'in:'.implode(',', $roles)],
                'name' => ['required', 'string', 'max:120'],
                'password' => $existing ? ['nullable'] : ['required', Password::min(12)->mixedCase()->numbers()],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        if ($existing?->business_id) {
            $this->error('That email belongs to a business user. Use a separate email for staff.');

            return self::FAILURE;
        }

        (new RolesSeeder)->run();
        $user = $existing ?? User::create(['email' => $email, 'name' => $name, 'password' => $plain, 'email_verified_at' => now()]);
        $user->syncRoles([$role]);

        $this->info(($existing ? 'Promoted ' : 'Created ').$email." as {$role}.");

        return self::SUCCESS;
    }
}
