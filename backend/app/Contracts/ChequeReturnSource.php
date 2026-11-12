<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Banking\ChequeReturnRequest;

/**
 * A document type that can take back a cheque it wrote: a customer receipt,
 * a supplier payment, a bill payment.
 *
 * Each module tags its own implementations with TAG in its service provider;
 * ChequeReturnRouter (the bound ChequeReturnHandler) sends every cheque to the
 * source that names its sourceType. A disabled or absent module simply
 * registers nothing, and its cheques are refused with a reason.
 */
interface ChequeReturnSource
{
    public const TAG = 'cheque_return_sources';

    /** @return list<string> the bank-line source types this document answers for */
    public function sourceTypes(): array;

    /**
     * Why each cheque cannot be returned, or null when it can.
     *
     * @param  list<int>             $sourceIds
     * @return array<int, ?string>   sourceId => reason
     */
    public function blockedReasons(string $sourceType, array $sourceIds): array;

    /**
     * Post the bounce and reopen what the cheque had settled. Runs inside the
     * caller's transaction; refuses with a ValidationException.
     *
     * @return string|null the journal number of the bounce entry
     */
    public function returnCheque(ChequeReturnRequest $request): ?string;
}
