<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Support\Documents;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Invoice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    use AuthorizesRequests;

    public function invoice(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->business_id === $request->user()->business_id, 403);

        return Documents::invoicePdf($invoice)->download("{$invoice->number}.pdf");
    }

    public function proof(Campaign $campaign): Response
    {
        $this->authorize('view', $campaign);
        abort_if($campaign->isDraft(), 404);

        return Documents::proofPdf($campaign)->download("Proof-of-distribution-{$campaign->reference}.pdf");
    }
}
