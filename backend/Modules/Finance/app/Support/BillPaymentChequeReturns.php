<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Contracts\ChequeReturnSource;
use App\Support\Banking\ChequeReturnRequest;
use App\Support\Journal\PostingRole;
use Carbon\CarbonImmutable;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Services\BillPaymentService;

/**
 * Bank Reconciliation's way back to a bill payment whose cheque the bank
 * returned. One payment is one cheque, so the bank line names the payment.
 */
final class BillPaymentChequeReturns implements ChequeReturnSource
{
    public function __construct(private readonly BillPaymentService $payments) {}

    public function sourceTypes(): array
    {
        return [PostingRole::SOURCE_BILL_PAYMENT];
    }

    public function blockedReasons(string $sourceType, array $sourceIds): array
    {
        $payments = BillPayment::whereKey($sourceIds)->get()->keyBy('id');
        $reasons  = [];

        foreach ($sourceIds as $id) {
            $reasons[$id] = $this->payments->returnBlockedReason($payments->get($id));
        }

        return $reasons;
    }

    public function returnCheque(ChequeReturnRequest $request): ?string
    {
        return $this->payments->returnCheque(
            $request->sourceId,
            CarbonImmutable::instance($request->returnDate),
            $request->reference,
            $request->bankAccountId,
        );
    }
}
