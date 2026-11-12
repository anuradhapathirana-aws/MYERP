<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Contracts\LandedChargeSource;
use App\DTOs\LandedCharge;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\ChargeTreatment;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillLine;

/**
 * Finance's answer to "which bills add to this shipment's cost": confirmed
 * bill lines linked to a GRN whose treatment is a landed cost -- or Import VAT
 * that could not be claimed, which is cost like duty.
 *
 * Only the cost part travels (line total less claimable tax), so claimable
 * VAT never reaches a product's cost or selling price.
 */
final class BillLandedChargeSource implements LandedChargeSource
{
    public function __construct(private readonly SettingsService $settings) {}

    public function isAvailable(): bool
    {
        return $this->settings->isEnabled('module.finance');
    }

    public function chargesForGrns(array $grnIds): array
    {
        if ($grnIds === [] || ! $this->isAvailable()) {
            return [];
        }

        return $this->charges(
            $this->landedLines()->whereIn('reference_id', array_map('intval', $grnIds)),
        );
    }

    public function chargesByIds(array $sourceIds): array
    {
        if ($sourceIds === [] || ! $this->isAvailable()) {
            return [];
        }

        return $this->charges(
            $this->landedLines()->whereKey(array_map('intval', $sourceIds)),
        );
    }

    /** @return Builder<BillLine> lines of confirmed bills that belong to a GRN and may carry cost */
    private function landedLines(): Builder
    {
        return BillLine::query()
            ->where('reference_type', BillLine::REFERENCE_GRN)
            ->whereIn('treatment', [ChargeTreatment::LandedCost->value, ChargeTreatment::RecoverableTax->value])
            ->whereHas('bill', fn (Builder $b) => $b->where('status', BillStatus::Confirmed->value));
    }

    /**
     * @param  Builder<BillLine> $query
     * @return list<LandedCharge>
     */
    private function charges(Builder $query): array
    {
        return $query
            ->with([
                'bill:id,bill_no,transaction_date,supplier_id',
                'bill.supplier:id,supplier_name',
                'chargeType:id,name,fob_expense_type_id,cif_expense_type_id',
            ])
            ->orderBy('bill_id')
            ->orderBy('line_no')
            ->get()
            ->filter(fn (BillLine $line): bool => Bill::toCents($line->costAmount()) > 0)
            ->map(fn (BillLine $line): LandedCharge => new LandedCharge(
                sourceId:         (int) $line->id,
                billId:           (int) $line->bill_id,
                billNo:           $line->bill->bill_no,
                billDate:         $line->bill->transaction_date?->toDateString(),
                partyName:        $line->bill->supplier?->supplier_name,
                chargeName:       $line->chargeType?->name ?? ($line->description ?? 'Charge'),
                grnId:            (int) $line->reference_id,
                amount:           (float) $line->costAmount(),
                fobExpenseTypeId: $line->chargeType?->fob_expense_type_id,
                cifExpenseTypeId: $line->chargeType?->cif_expense_type_id,
            ))
            ->values()
            ->all();
    }
}
