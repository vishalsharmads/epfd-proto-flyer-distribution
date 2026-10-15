<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;

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
