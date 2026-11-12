<?php

declare(strict_types=1);

namespace App\Support\Banking;

use App\Contracts\ChequeReturnHandler;
use App\Contracts\ChequeReturnSource;

/**
 * Sends each returned cheque to the document that wrote it.
 *
 * Every module tags its ChequeReturnSource implementations with
 * ChequeReturnSource::TAG; the source naming a cheque's source_type answers
 * for it. A cheque nobody answers for is refused with the null reason.
 */
final class ChequeReturnRouter implements ChequeReturnHandler
{
    public function blockedReasons(array $cheques): array
    {
        $reasons = [];
        $byType  = [];

        foreach ($cheques as $cheque) {
            $type = $cheque['source_type'] ?? null;
            $id   = $cheque['source_id'] ?? null;

            $reasons[self::key($type, $id)] = NullChequeReturnHandler::REASON;

            if ($type !== null && $id !== null) {
                $byType[$type][] = (int) $id;
            }
        }

        foreach ($byType as $type => $ids) {
            $source = $this->sourceFor($type);

            if ($source === null) {
                continue;
            }

            foreach ($source->blockedReasons($type, array_values(array_unique($ids))) as $id => $reason) {
                $reasons[self::key($type, (int) $id)] = $reason;
            }
        }

        return $reasons;
    }

    public function returnCheque(ChequeReturnRequest $request): ?string
    {
        $source = $this->sourceFor($request->sourceType);

        if ($source === null) {
            return (new NullChequeReturnHandler())->returnCheque($request);
        }

        return $source->returnCheque($request);
    }

    public static function key(?string $sourceType, ?int $sourceId): string
    {
        return NullChequeReturnHandler::key($sourceType, $sourceId);
    }

    private function sourceFor(string $sourceType): ?ChequeReturnSource
    {
        foreach (app()->tagged(ChequeReturnSource::TAG) as $source) {
            if (in_array($sourceType, $source->sourceTypes(), true)) {
                return $source;
            }
        }

        return null;
    }
}
