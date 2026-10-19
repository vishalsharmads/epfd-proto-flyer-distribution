<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Payments\CheckoutService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\HandlesProblems;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DesignOrder;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    use HandlesProblems;

    /** Finish a checkout after the customer completed 3-D Secure in the browser. */
    public function confirm(Payment $payment, CheckoutService $checkout): RedirectResponse
    {
        $this->authorize('view', $payment);
        try {
            $checkout->confirm($payment);
        } catch (DomainProblem $e) {
            return $this->paymentProblem($e);
        }

        $payable = $payment->payable;

        return match (true) {
            $payable instanceof Campaign => redirect()->route('app.jobs.campaign', $payable)->with('success', 'Payment received — your campaign has been submitted.'),
            $payable instanceof DesignOrder => redirect()->route('app.jobs.design', $payable)->with('success', 'Payment received — your design brief has been submitted to our studio.'),
            default => redirect()->route('app.jobs.print', $payable)->with('success', 'Payment received — your print order has been submitted.'),
        };
    }
}
