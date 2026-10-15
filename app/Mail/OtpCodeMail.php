<?php

declare(strict_types=1);

namespace App\Mail;

final class OtpCodeMail extends BrandedMail
{
    public function __construct(public readonly string $name, public readonly string $code) {}

    protected function subjectLine(): string
    {
        return "Your EPFD verification code: {$this->code}";
    }

    protected function viewName(): string
    {
        return 'emails.otp';
    }

    protected function viewData(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'minutes' => 10];
    }
}
