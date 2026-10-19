<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Payments\PaymentActionRequired;
use App\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

trait HandlesProblems
{
    /** Turn a DomainProblem into a JSON error the wizard's api() helper understands. */
    protected function problemJson(DomainProblem $e): JsonResponse
    {
        return response()->json([
            'title' => $e->title,
            'detail' => $e->getMessage(),
            'code' => $e->problemCode,
            'status' => $e->status,
            'errors' => $e->extra['errors'] ?? null,
        ], $e->status);
    }

    /**
     * Back to the checkout with errors, or — when the card needs 3-D Secure — with what the browser
     * needs to authenticate and then confirm the payment.
     */
    protected function paymentProblem(DomainProblem $e): RedirectResponse
    {
        if ($e instanceof PaymentActionRequired) {
            return back()->with('payment_action', [
                'client_secret' => $e->extra['client_secret'],
                'confirm_url' => $e->extra['web_confirm_url'],
                'gateway' => $e->extra['gateway'],
            ]);
        }

        return back()->withErrors($this->problemErrors($e));
    }

    /** @return array<string, string> flat field => message for Inertia back()->withErrors() */
    protected function problemErrors(DomainProblem $e, string $fallbackKey = 'payment'): array
    {
        $errors = [];
        foreach ((array) ($e->extra['errors'] ?? []) as $k => $v) {
            $errors[$k] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $errors[$fallbackKey] = $e->getMessage();

        return $errors;
    }
}
