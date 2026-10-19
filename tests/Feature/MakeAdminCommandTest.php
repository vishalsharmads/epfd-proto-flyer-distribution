<?php

declare(strict_types=1);

use App\Models\User;

it('creates a staff user with the chosen role', function () {
    $email = uniqid().'@staff.test';

    $this->artisan('epfd:make-admin', ['email' => $email, '--name' => 'Ada Admin', '--role' => 'finance'])
        ->expectsQuestion('Password (at least 12 characters)', 'Sup3rSecretPass')
        ->assertSuccessful();

    $user = User::where('email', $email)->firstOrFail();
    expect($user->hasRole('finance'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('rejects a weak password', function () {
    $email = uniqid().'@staff.test';

    $this->artisan('epfd:make-admin', ['email' => $email, '--name' => 'Ada', '--role' => 'support'])
        ->expectsQuestion('Password (at least 12 characters)', 'password')
        ->assertFailed();

    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('promotes an existing staff user without changing their password', function () {
    $user = staffUser('support');

    $this->artisan('epfd:make-admin', ['email' => $user->email, '--role' => 'operations'])->assertSuccessful();

    expect($user->fresh()->hasRole('operations'))->toBeTrue()
        ->and($user->fresh()->hasRole('support'))->toBeFalse();
});

it('refuses to turn a business user into staff', function () {
    $this->artisan('epfd:make-admin', ['email' => businessUser()->email, '--role' => 'super_admin'])->assertFailed();
});
