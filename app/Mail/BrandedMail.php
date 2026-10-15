<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Base for EPFD transactional emails rendered with resources/views/emails/layout.blade.php. */
abstract class BrandedMail extends Mailable
{
    use Queueable, SerializesModels;

    abstract protected function subjectLine(): string;

    abstract protected function viewName(): string;

    /** @return array<string, mixed> */
    abstract protected function viewData(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: $this->viewName(), with: [
            ...$this->viewData(),
            'brand' => config('epfd.brand'),
            'logoUrl' => rtrim((string) config('app.url'), '/').'/'.ltrim((string) config('epfd.brand.logo'), '/'),
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
