<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\DomainProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/** Shared helpers for admin controllers: domain errors become a flash toast instead of a 500. */
abstract class AdminController extends Controller
{
    /** @param callable(): mixed $fn */
    protected function attempt(callable $fn, string $success): RedirectResponse
    {
        try {
            $fn();
        } catch (DomainProblem $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** @return array{0:?string,1:?string} [from, to] Y-m-d validated */
    protected function range(?string $from, ?string $to): array
    {
        $ok = fn ($d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;

        return [$ok($from), $ok($to)];
    }
}
