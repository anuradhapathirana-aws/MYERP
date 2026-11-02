<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one audit row, stamped with who did it and from where.
 *
 * Called explicitly by services at each state change rather than from model
 * events: "confirmed" and "voided" are business events, not column updates,
 * and an explicit call inside the same transaction means the history rolls
 * back together with a change that failed.
 */
class AuditLogger
{
    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(string $type, int $id, string $event, ?array $old = null, ?array $new = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'auditable_type' => $type,
            'auditable_id'   => $id,
            'event'          => $event,
            'old_values'     => $old === [] ? null : $old,
            'new_values'     => $new === [] ? null : $new,
            'user_id'        => Auth::id(),
            'ip_address'     => $request?->ip(),
            'user_agent'     => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Only the fields that actually changed, as [old, new].
     *
     * @param  array<string, mixed> $before
     * @param  array<string, mixed> $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ((string) json_encode($previous) !== (string) json_encode($value)) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }
}
