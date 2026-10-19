<?php

declare(strict_types=1);

use App\Mail\OtpCodeMail;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(fn () => Mail::fake());

function registration(array $overrides = []): array
{
    return array_replace([
        'business_name' => 'Harbour Coffee', 'contact_name' => 'Hana Lee', 'email' => uniqid().'@harbour.test',
        'mobile' => '0412 345 678', 'address' => '257 Victoria Road, Rydalmere NSW 2116', 'industry' => 'Hospitality',
        'password' => 'coffee1234', 'password_confirmation' => 'coffee1234', 'terms' => true,
    ], $overrides);
}

describe('login', function () {
    it('signs in with any letter case in the email', function () {
        $user = User::factory()->create(['email' => 'owner@harbour.test']);

        $this->post('/login', ['email' => 'Owner@Harbour.TEST', 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    });

    it('rejects a wrong password without saying whether the email exists', function () {
        User::factory()->create(['email' => 'owner@harbour.test']);

        $this->post('/login', ['email' => 'owner@harbour.test', 'password' => 'nope'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->post('/login', ['email' => 'nobody@harbour.test', 'password' => 'nope'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    });

    it('turns away deactivated accounts', function () {
        User::factory()->create(['email' => 'gone@harbour.test', 'preferences' => ['deactivated_at' => now()->toIso8601String()]]);

        $this->post('/login', ['email' => 'gone@harbour.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    it('sends staff to the admin dashboard and businesses to the portal', function () {
        $this->post('/login', ['email' => staffUser('support')->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        auth()->logout();
        $this->post('/login', ['email' => businessUser()->email, 'password' => 'password'])->assertRedirect(route('app.dashboard'));
    });
});

describe('registration', function () {
    it('creates a pending business, signs in and emails a code', function () {
        $this->post('/register', registration(['email' => 'New@Harbour.test']))->assertRedirect(route('verify.otp'));

        $user = User::where('email', 'new@harbour.test')->firstOrFail();
        expect($user->business->status)->toBe('pending')->and($user->email_verified_at)->toBeNull();
        $this->assertAuthenticatedAs($user);
        Mail::assertSent(OtpCodeMail::class, fn ($m) => $m->hasTo('new@harbour.test'));
    });

    it('rejects a weak password, a non-Australian mobile and a taken email', function () {
        $taken = businessUser()->email;

        $this->post('/register', registration(['password' => 'password', 'password_confirmation' => 'password', 'mobile' => '0212345678', 'email' => strtoupper($taken)]))
            ->assertSessionHasErrors(['password', 'mobile', 'email']);
        $this->assertGuest();
    });

    it('still finishes registration when the code email cannot be sent', function () {
        Mail::shouldReceive('to')->andThrow(new TransportException('SMTP down'));

        $this->post('/register', registration(['email' => 'mailfail@harbour.test']))
            ->assertRedirect(route('verify.otp'))->assertSessionHas('warning');

        expect(User::where('email', 'mailfail@harbour.test')->exists())->toBeTrue();
    });
});

describe('email code (OTP)', function () {
    function unverified(): User
    {
        $user = businessUser();
        $user->forceFill(['email_verified_at' => null, 'otp_hash' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0])->save();

        return $user->fresh();
    }

    it('keeps unverified users out of the portal until they enter the code', function () {
        $user = unverified();

        $this->actingAs($user)->get('/app')->assertRedirect(route('verify.otp'));
        $this->post('/verify', ['code' => '123456'])->assertRedirect(route('app.dashboard'));

        expect($user->fresh()->email_verified_at)->not->toBeNull()->and($user->fresh()->otp_hash)->toBeNull();
    });

    it('locks the code after five wrong attempts', function () {
        $user = unverified();
        $this->actingAs($user)->post('/verify', ['code' => '000000'])->assertSessionHasErrors(['code' => "That code isn't right. 4 attempts left."]);
        $user->forceFill(['otp_attempts' => 5])->save();

        $this->post('/verify', ['code' => '123456'])->assertSessionHasErrors(['code' => 'Too many incorrect attempts. Send a new code.']);
        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    it('rejects an expired code', function () {
        $user = unverified();
        $user->forceFill(['otp_expires_at' => now()->subMinute()])->save();

        $this->actingAs($user)->post('/verify', ['code' => '123456'])->assertSessionHasErrors(['code' => 'This code has expired. Send a new code.']);
    });

    it('resends at most once a minute', function () {
        $user = unverified();
        RateLimiter::clear('otp-resend:'.$user->id);
        $this->actingAs($user);

        $this->post('/verify/resend')->assertSessionHas('success');
        $this->post('/verify/resend')->assertSessionHasErrors('code');
        Mail::assertSent(OtpCodeMail::class, 1);
    });
});

describe('password reset', function () {
    it('sends a reset link without revealing whether the account exists', function () {
        $user = businessUser();

        $this->post('/forgot-password', ['email' => strtoupper($user->email)])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@harbour.test'])->assertSessionHas('status');

        Mail::assertSent(ResetPasswordMail::class, 1);
        Mail::assertSent(ResetPasswordMail::class, fn ($m) => $m->hasTo($user->email));
    });

    it('resets the password and signs out every other device', function () {
        $user = businessUser();
        $user->createToken('phone');
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('login'));

        expect(Hash::check('newpass123', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('refuses a bad token', function () {
        $user = businessUser();

        $this->post('/reset-password', ['token' => 'forged', 'email' => $user->email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertSessionHasErrors('email');
        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    });
});

describe('API auth matches the web rules', function () {
    it('rejects a weak password on API registration', function () {
        $this->postJson('/api/v1/auth/register', [
            'business_name' => 'B', 'contact_name' => 'C', 'email' => uniqid().'@x.test', 'mobile' => '0412345678',
            'address' => '1 Test St, Sydney NSW 2000', 'password' => 'password', 'accept_terms' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('rate limits code resends on the API too', function () {
        $user = unverified();
        RateLimiter::clear('otp-resend:'.$user->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/otp/resend')->assertOk();
        $this->postJson('/api/v1/auth/otp/resend')->assertStatus(429);
        Mail::assertSent(OtpCodeMail::class, 1);
    });

    it('sends API password resets through the throttled broker', function () {
        $user = businessUser();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertSent(ResetPasswordMail::class, 1);
    });
});

describe('sessions', function () {
    it('signs out other browser sessions once the password changes', function () {
        $user = businessUser();
        $this->actingAs($user)->get('/app')->assertOk(); // the session remembers the password it signed in with

        $user->forceFill(['password' => 'changed-elsewhere1'])->save();

        $this->get('/app')->assertRedirect(route('login'));
        $this->assertGuest();
    });
});
