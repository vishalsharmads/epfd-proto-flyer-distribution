<?php

declare(strict_types=1);

namespace App\Mail;

use App\Http\Controllers\App\Support\Documents;
use App\Models\Invoice;
use Illuminate\Mail\Mailables\Attachment;

/** "Payment received" email with the GST tax invoice attached as a PDF. */
final class InvoiceMail extends BrandedMail
{
    public function __construct(public readonly Invoice $invoice, public readonly string $name, public readonly string $jobName, public readonly string $jobUrl) {}

    protected function subjectLine(): string
    {
        return "Tax invoice {$this->invoice->number} — payment received";
    }

    protected function viewName(): string
    {
        return 'emails.invoice';
    }

    protected function viewData(): array
    {
        return ['invoice' => $this->invoice, 'name' => $this->name, 'jobName' => $this->jobName, 'jobUrl' => $this->jobUrl];
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $invoice = $this->invoice;

        return [Attachment::fromData(fn () => Documents::invoicePdf($invoice)->output(), "{$invoice->number}.pdf")->withMime('application/pdf')];
    }
}
