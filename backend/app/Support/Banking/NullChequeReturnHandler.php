<?php

declare(strict_types=1);

namespace App\Support\Banking;

use App\Contracts\ChequeReturnHandler;
use Illuminate\Validation\ValidationException;

/**
 * Refuses every return with a reason. What ChequeReturnRouter answers for a
 * cheque no tagged source owns -- a hand-posted line, or a document whose
 * module is not installed.
 */
final class NullChequeReturnHandler implements ChequeReturnHandler
{
    public const REASON = 'Returning a cheque is not available yet. Void the payment, receipt or deposit instead.';

    public function blockedReasons(array $cheques): array
    {
        $reasons = [];

        foreach ($cheques as $cheque) {
            $reasons[self::key($cheque['source_type'] ?? null, $cheque['source_id'] ?? null)] = self::REASON;
        }

        return $reasons;
    }

    public function returnCheque(ChequeReturnRequest $request): ?string
    {
        throw ValidationException::withMessages(['lines' => self::REASON]);
    }

    public static function key(?string $sourceType, ?int $sourceId): string
    {
        return ($sourceType ?? '') . ':' . ($sourceId ?? 0);
    }
}
