<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainProblem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders errors as RFC 9457 "application/problem+json".
 * `type` points at the error-code table in the API docs; `code` is a stable machine slug.
 */
final class ProblemJson
{
    public const TYPE_BASE = '/docs#errors-';

    /** Error-code table (also rendered in /docs). */
    public const CODES = [
        'validation_failed' => [422, 'The request body failed validation. See `errors`.'],
        'unauthenticated' => [401, 'Missing or invalid Bearer token / session.'],
        'forbidden' => [403, 'Authenticated but not allowed (role, ownership or business status).'],
        'not_found' => [404, 'Resource does not exist or is not visible to you.'],
        'rate_limited' => [429, 'Too many requests. Retry after the `Retry-After` header.'],
        'conflict' => [409, 'State conflict, e.g. paying an already-paid campaign.'],
        'business_not_approved' => [403, 'The business is pending approval or suspended; payment is blocked.'],
        'polygon_invalid_geojson' => [422, 'Area is not a GeoJSON Polygon of [lon, lat] pairs.'],
        'polygon_too_few_vertices' => [422, 'Fewer than 3 distinct vertices.'],
        'polygon_too_many_vertices' => [422, 'More than the maximum vertices (500).'],
        'polygon_self_intersection' => [422, 'Edges cross each other.'],
        'polygon_invalid' => [422, 'Geometry invalid for another reason (see `reason`).'],
        'polygon_has_holes' => [422, 'Polygons with interior rings are not accepted.'],
        'polygon_outside_australia' => [422, 'A vertex lies outside Australia.'],
        'polygon_too_small' => [422, 'Area below the minimum size.'],
        'polygon_too_large' => [422, 'Area above the maximum size (60 km²).'],
        'area_outside_pickup_radius' => [422, 'Area is not even partly within 20 km of the pickup point.'],
        'no_letterboxes' => [422, 'The area contains no letterboxes; fees are unavailable.'],
        'pricing_unavailable_combination' => [422, 'Print product option combination is not offered.'],
        'promo_invalid' => [422, 'Promo code unknown, expired or not applicable.'],
        'payment_declined' => [402, 'The card was declined by the payment gateway.'],
        'letterbox_source_unavailable' => [503, 'The configured letterbox data source is unavailable.'],
        'invalid_state' => [409, 'The resource is not in a state that allows this action (e.g. run status transition).'],
        'pricing_unknown_addon' => [422, 'Unknown print add-on code.'],
        'webhook_signature_invalid' => [400, 'Webhook signature missing or invalid.'],
        'refund_failed' => [502, 'The payment gateway rejected the refund.'],
        'server_error' => [500, 'Unexpected error. The `instance` id is logged.'],
    ];

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title, $detail, $extra] = match (true) {
            $e instanceof DomainProblem => [$e->status, $e->problemCode, $e->title, $e->getMessage(), $e->extra],
            $e instanceof ValidationException => [422, 'validation_failed', 'Validation failed', $e->getMessage(), ['errors' => $e->errors()]],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Unauthenticated', 'Authentication is required.', []],
            $e instanceof AuthorizationException, $e instanceof UnauthorizedException => [403, 'forbidden', 'Forbidden', $e->getMessage() ?: 'You are not allowed to do that.', []],
            $e instanceof ModelNotFoundException => [404, 'not_found', 'Not found', 'The requested resource was not found.', []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests', 'Rate limit exceeded.', []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::codeForStatus($e->getStatusCode()), self::titleForStatus($e->getStatusCode()), $e->getMessage() ?: self::titleForStatus($e->getStatusCode()), []],
            default => [500, 'server_error', 'Server error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side.', []],
        };

        $body = [
            'type' => self::TYPE_BASE.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getRequestUri(),
            'code' => $code,
            ...$extra,
        ];

        $headers = ['Content-Type' => 'application/problem+json'];
        if ($e instanceof HttpExceptionInterface) {
            $headers = [...$e->getHeaders(), ...$headers];
        }

        return new JsonResponse($body, $status, $headers);
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'http_'.$status,
        };
    }

    private static function titleForStatus(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }
}
