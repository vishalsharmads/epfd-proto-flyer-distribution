<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail for staff and money actions. Never pass PII (card numbers, passwords,
 * OTPs) in $changes — keys matching the redact list are masked defensively.
 */
final class Audit
{
    private const REDACT = ['password', 'otp', 'card', 'number', 'cvc', 'token', 'secret'];

    /** @param array<string, mixed> $changes */
    public static function log(string $action, ?Model $subject = null, array $changes = []): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => self::redact($changes),
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            foreach (self::REDACT as $needle) {
                if (str_contains(strtolower((string) $k), $needle)) {
                    $data[$k] = '[redacted]';

                    continue 2;
                }
            }
            if (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }

        return $data;
    }
}
