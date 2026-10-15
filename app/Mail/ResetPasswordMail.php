<?php

declare(strict_types=1);

namespace App\Mail;

final class ResetPasswordMail extends BrandedMail
{
    public function __construct(public readonly string $name, public readonly string $url) {}

    protected function subjectLine(): string
    {
        return 'Reset your EPFD password';
    }

    protected function viewName(): string
    {
        return 'emails.reset-password';
    }

    protected function viewData(): array
    {
        return ['name' => $this->name, 'url' => $this->url, 'minutes' => (int) config('auth.passwords.users.expire', 60)];
    }
}
