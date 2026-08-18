<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\SupplierPaymentData;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CreditNoteType;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Enums\SetoffType;
use Modules\Inventory\Enums\SupplierPaymentStatus;
use Modules\Inventory\Models\PaymentMode;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierPayment;
use Modules\Inventory\Models\SupplierPaymentAllocation;
use Modules\Inventory\Models\SupplierPaymentSetoff;
use Modules\Inventory\Models\SupplierPaymentSettlement;

class SupplierPaymentService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierPayment::orderByDesc('payment_date')->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('payment_no', 'like', $term)
                  ->orWhere('reference_no', 'like', $term);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        $paginator = $query->paginate($perPage);
        $this->attachSupplierSnapshots($paginator->items());

        return $paginator;
    }

    public function find(int $id): SupplierPayment
    {
        $payment = SupplierPayment::with(['allocations', 'setoffs.creditNote', 'settlements'])->findOrFail($id);
        $this->attachSupplierSnapshots([$payment]);
        $this->attachGrnNumbers($payment);

        return $payment;
    }

    /**
     * Outstanding confirmed GRNs for a supplier — data source for the payment form's GRN table.
     * @return array<array<string, mixed>>
     */
    public function getOutstandingGrnsForSupplier(int $supplierId): array
    {
        $supplier     = DB::table('inv_supplier_masters')->where('id', $supplierId)->first(['credit_period']);
        $creditPeriod = (int) ($supplier->credit_period ?? 0);

        $grns = DB::table('inv_goods_received_notes as g')
            ->leftJoin('inv_purchase_orders as po', 'po.id', '=', 'g.po_id')
            ->where('g.supplier_id', $supplierId)
            ->where('g.status', GrnStatus::Confirmed->value)
            ->whereNull('g.deleted_at')
            ->orderBy('g.grn_date')
            ->select('g.id', 'g.grn_no', 'g.grn_date', 'g.reference_no', 'g.total_amount', 'po.po_no')
            ->get();

        if ($grns->isEmpty()) {
            return [];
        }

        $paidByGrn = DB::table('inv_supplier_payment_allocations as a')
            ->join('inv_supplier_payments as p', 'p.id', '=', 'a.payment_id')
            ->whereIn('a.reference_id', $grns->pluck('id')->all())
            ->where('a.reference_type', 'grn')
            ->where('p.status', SupplierPaymentStatus::Confirmed->value)
            ->groupBy('a.reference_id')
            // payment_amount + discount: a discount is a permanent write-off, so it must
            // reduce outstanding exactly like a cash payment does.
            ->select('a.reference_id', DB::raw('SUM(a.payment_amount + a.discount) as paid'))
            ->get()
            ->pluck('paid', 'reference_id');

        $result = [];

        foreach ($grns as $grn) {
            $paid        = (float) ($paidByGrn[$grn->id] ?? 0);
            $outstanding = (float) $grn->total_amount - $paid;

            if ($outstanding <= 0) {
                continue;
            }

            $result[] = [
                'grn_id'       => $grn->id,
                'grn_no'       => $grn->grn_no,
                'grn_date'     => $grn->grn_date,
                'po_no'        => $grn->po_no,
                'reference_no' => $grn->reference_no,
                'due_date'     => $grn->grn_date
                    ? Carbon::parse($grn->grn_date)->addDays($creditPeriod)->toDateString()
                    : null,
                'amount'       => (float) $grn->total_amount,
                'outstanding'  => $outstanding,
            ];
        }

        return $result;
    }

    /** @return \Illuminate\Support\Collection<int, SupplierCreditNote> */
    public function getOpenCreditNotesForSupplier(int $supplierId, ?string $type = null)
    {
        $query = SupplierCreditNote::where('supplier_id', $supplierId)
            ->where('status', CreditNoteStatus::Open)
            ->where('remaining_balance', '>', 0);

        if ($type) {
            $query->where('credit_type', $type);
        }

        return $query->orderByDesc('id')->get();
    }

    public function create(SupplierPaymentData $data): SupplierPayment
    {
        return DB::transaction(function () use ($data): SupplierPayment {
            $payment = SupplierPayment::create([
                'payment_no'       => $this->generatePaymentNo(),
                'payment_date'     => $data->paymentDate,
                'transaction_date' => $data->transactionDate,
                'reference_no'     => $data->referenceNo,
                'supplier_type'    => $data->supplierType,
                'supplier_id'      => $data->supplierId,
                'payment_remark'   => $data->paymentRemark,
                'is_advance'       => $data->isAdvance,
                'advance_amount'   => $data->isAdvance ? $data->advanceAmount : 0,
                'status'           => SupplierPaymentStatus::Draft,
                'created_by'       => auth()->id(),
            ]);

            $this->finalizeAmounts($payment, $data);

            $payment->load(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachSupplierSnapshots([$payment]);
            $this->attachGrnNumbers($payment);

            return $payment;
        });
    }

    public function update(SupplierPayment $payment, SupplierPaymentData $data): SupplierPayment
    {
        if ($payment->status !== SupplierPaymentStatus::Draft) {
            abort(422, 'Only draft payments can be edited.');
        }

        return DB::transaction(function () use ($payment, $data): SupplierPayment {
            $payment->update([
                'payment_date'     => $data->paymentDate,
                'transaction_date' => $data->transactionDate,
                'reference_no'     => $data->referenceNo,
                'supplier_type'    => $data->supplierType,
                'supplier_id'      => $data->supplierId,
                'payment_remark'   => $data->paymentRemark,
                'is_advance'       => $data->isAdvance,
                'advance_amount'   => $data->isAdvance ? $data->advanceAmount : 0,
            ]);

            $this->finalizeAmounts($payment, $data);

            $payment->load(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachSupplierSnapshots([$payment]);
            $this->attachGrnNumbers($payment);

            return $payment;
        });
    }

    /**
     * Sync allocations/setoffs/settlements, then validate and persist the header totals.
     * Total Payable must be fully covered by setoffs + settlement lines before saving
     * (Remaining ≤ 0) — underfunding is blocked, but overfunding is allowed: the excess
     * becomes an over_payment credit note when the payment is confirmed (see confirm()).
     */
    private function finalizeAmounts(SupplierPayment $payment, SupplierPaymentData $data): void
    {
        [$grossAmount, $discountAmount] = $this->syncAllocations($payment, $data->allocations);
        $setoffAmount     = $this->syncSetoffs($payment, $data->setoffs);
        $settlementAmount = $this->syncSettlements($payment, $data->settlements);

        $target = $data->isAdvance ? (float) $data->advanceAmount : $grossAmount;

        if ($target - $setoffAmount - $settlementAmount > 0.01) {
            abort(422, 'Payment is underfunded — cover the full Total Payable with Setoffs and/or Payment Details before saving.');
        }

        $payment->update([
            'gross_amount'    => $grossAmount,
            'discount_amount' => $discountAmount,
            'setoff_amount'   => $setoffAmount,
            'net_amount'      => $settlementAmount,
        ]);
    }

    /**
     * Confirm a draft payment: locks affected GRNs and credit notes, recomputes outstanding
     * authoritatively, converts overpayment into a credit note, consumes setoff credit notes,
     * and posts a standalone advance's credit note.
     */
    public function confirm(SupplierPayment $payment): SupplierPayment
    {
        if ($payment->status !== SupplierPaymentStatus::Draft) {
            abort(422, 'Only draft payments can be confirmed.');
        }

        return DB::transaction(function () use ($payment): SupplierPayment {
            $payment->load(['allocations', 'setoffs', 'settlements']);

            $grnIds = $payment->allocations
                ->where('reference_type', 'grn')
                ->pluck('reference_id')
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (!empty($grnIds)) {
                // Lock in a consistent id order to avoid deadlocks against concurrent confirms
                DB::table('inv_goods_received_notes')
                    ->whereIn('id', $grnIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            // GRN-row overpayment: a row's payment_amount exceeding what that specific GRN
            // still owes (outstanding minus its own discount).
            $overpaymentExcess = 0.0;

            foreach ($payment->allocations as $allocation) {
                if ($allocation->reference_type !== 'grn') {
                    continue;
                }

                $outstanding   = $this->computeOutstanding((int) $allocation->reference_id);
                $discount      = (float) $allocation->discount;
                $maxPayable    = max(0.0, $outstanding - $discount);
                $paymentAmount = (float) $allocation->payment_amount;

                // Compare against maxPayable (outstanding minus this row's own discount), not
                // raw outstanding — otherwise the discounted portion is silently excluded from
                // the credit note instead of being available for setoff. Rounded to cents with
                // a 1c tolerance so float noise from GRN totals stored at 4-decimal precision
                // (e.g. 21131.3179) never spawns a phantom overpayment credit note.
                $rowExcess = round($paymentAmount - $maxPayable, 2);
                if ($rowExcess > 0.01) {
                    $overpaymentExcess += $rowExcess;
                }
            }

            $setoffAmountTotal = 0.0;

            foreach ($payment->setoffs as $setoff) {
                $amount = (float) $setoff->amount;
                $setoffAmountTotal += $amount;

                if ($setoff->setoff_type === SetoffType::CustomerReturn) {
                    $creditNote = SupplierCreditNote::create([
                        'credit_note_no'    => $this->generateCreditNoteNo(),
                        'supplier_id'       => $payment->supplier_id,
                        'credit_type'       => CreditNoteType::CustomerReturn,
                        'amount'            => $amount,
                        'remaining_balance' => 0,
                        'remark'            => $setoff->remark,
                        'status'            => CreditNoteStatus::Exhausted,
                        'source_payment_id' => null,
                        'created_by'        => auth()->id(),
                    ]);

                    $setoff->update(['credit_note_id' => $creditNote->id]);
                    continue;
                }

                // over_payment / advance setoffs consume an existing credit note's balance
                $creditNote = SupplierCreditNote::whereKey($setoff->credit_note_id)
                    ->lockForUpdate()
                    ->first();

                if (!$creditNote) {
                    abort(422, 'Referenced credit note no longer exists.');
                }

                if ((float) $creditNote->remaining_balance < $amount) {
                    abort(422, "Insufficient credit note balance for a setoff of {$amount}.");
                }

                $newBalance = (float) $creditNote->remaining_balance - $amount;
                $creditNote->update([
                    'remaining_balance' => $newBalance,
                    'status'            => $newBalance <= 0.0001 ? CreditNoteStatus::Exhausted : CreditNoteStatus::Open,
                ]);
            }

            if ($payment->is_advance) {
                SupplierCreditNote::create([
                    'credit_note_no'    => $this->generateCreditNoteNo(),
                    'supplier_id'       => $payment->supplier_id,
                    'credit_type'       => CreditNoteType::Advance,
                    'amount'            => (float) $payment->advance_amount,
                    'remaining_balance' => (float) $payment->advance_amount,
                    'remark'            => "Advance payment {$payment->payment_no}.",
                    'status'            => CreditNoteStatus::Open,
                    'source_payment_id' => $payment->id,
                    'created_by'        => auth()->id(),
                ]);
            }

            $grossAmount    = (float) $payment->allocations->sum('payment_amount');
            $discountAmount = (float) $payment->allocations->sum('discount');
            $netAmount      = (float) $payment->settlements->sum('amount');

            // Payment-level overfunding: Setoffs + Payment Details together exceed what was
            // actually owed (Total Payable / advance amount) — not tied to any specific GRN
            // row, e.g. a Payment Details line bigger than the Remaining figure. Merged into
            // the same aggregate over_payment credit note as any GRN-row overpayment above.
            $target = $payment->is_advance ? (float) $payment->advance_amount : $grossAmount;
            $overpaymentExcess += max(0.0, round($setoffAmountTotal + $netAmount - $target, 2));
            $overpaymentExcess = round($overpaymentExcess, 2);

            if ($overpaymentExcess > 0.01) {
                SupplierCreditNote::create([
                    'credit_note_no'    => $this->generateCreditNoteNo(),
                    'supplier_id'       => $payment->supplier_id,
                    'credit_type'       => CreditNoteType::OverPayment,
                    'amount'            => $overpaymentExcess,
                    'remaining_balance' => $overpaymentExcess,
                    'remark'            => "Auto-generated from overpayment on {$payment->payment_no}.",
                    'status'            => CreditNoteStatus::Open,
                    'source_payment_id' => $payment->id,
                    'created_by'        => auth()->id(),
                ]);
            }

            $payment->update([
                'gross_amount'    => $grossAmount,
                'discount_amount' => $discountAmount,
                'setoff_amount'   => $setoffAmountTotal,
                'net_amount'      => $netAmount,
                'status'          => SupplierPaymentStatus::Confirmed,
                'confirmed_at'    => now(),
            ]);

            $fresh = $payment->fresh(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachSupplierSnapshots([$fresh]);

            return $fresh;
        });
    }

    public function delete(SupplierPayment $payment): void
    {
        if ($payment->status !== SupplierPaymentStatus::Draft) {
            abort(422, 'Only draft payments can be deleted.');
        }

        $payment->delete();
    }

    /** Preview the next payment number (non-locking, for display only) */
    public function nextPaymentNo(): string
    {
        $prefix = 'PAY-';

        $last = SupplierPayment::withTrashed()
            ->where('payment_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('payment_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next payment number (must be called inside a DB transaction) */
    private function generatePaymentNo(): string
    {
        $prefix = 'PAY-';

        $last = SupplierPayment::withTrashed()
            ->where('payment_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('payment_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next credit note number (must be called inside a DB transaction) */
    private function generateCreditNoteNo(): string
    {
        $prefix = 'CN-';

        $last = SupplierCreditNote::where('credit_note_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('credit_note_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Outstanding for a single GRN, based only on confirmed payments — reused by the list endpoint and confirm(). */
    private function computeOutstanding(int $grnId): float
    {
        $grn = DB::table('inv_goods_received_notes')->where('id', $grnId)->first(['total_amount']);

        if (!$grn) {
            return 0.0;
        }

        // payment_amount + discount: a discount is a permanent write-off, so it must
        // reduce outstanding exactly like a cash payment does.
        $paid = DB::table('inv_supplier_payment_allocations as a')
            ->join('inv_supplier_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.reference_type', 'grn')
            ->where('a.reference_id', $grnId)
            ->where('p.status', SupplierPaymentStatus::Confirmed->value)
            ->selectRaw('COALESCE(SUM(a.payment_amount + a.discount), 0) as total')
            ->value('total');

        return (float) $grn->total_amount - (float) $paid;
    }

    /**
     * Validates discount against live outstanding server-side (never trusts a client-sent
     * discount blindly) and persists the allocation rows. payment_amount defaults to paying
     * the GRN in full (outstanding − discount) but can be a smaller client-supplied amount —
     * a genuine partial payment — leaving the remainder outstanding for a future payment.
     *
     * @param array<array{reference_type:string, reference_id:int, due_date:?string, discount:?float, payment_amount:?float, line_remark:?string}> $allocations
     * @return array{0: float, 1: float} [grossAmount, discountAmount]
     */
    private function syncAllocations(SupplierPayment $payment, array $allocations): array
    {
        $payment->allocations()->delete();

        $grnIds = collect($allocations)->pluck('reference_id')->filter()->unique()->values()->all();

        $grns = empty($grnIds) ? collect() : DB::table('inv_goods_received_notes as g')
            ->leftJoin('inv_purchase_orders as po', 'po.id', '=', 'g.po_id')
            ->whereIn('g.id', $grnIds)
            ->select('g.id', 'g.grn_date', 'g.reference_no', 'g.total_amount', 'po.po_no')
            ->get()
            ->keyBy('id');

        $rows          = [];
        $grossAmount   = 0.0;
        $discountTotal = 0.0;

        foreach ($allocations as $row) {
            $grnId = (int) ($row['reference_id'] ?? 0);
            if ($grnId <= 0) {
                continue;
            }

            $outstanding = $this->computeOutstanding($grnId);
            $discount    = (float) ($row['discount'] ?? 0);

            if ($discount > $outstanding) {
                abort(422, "Discount cannot exceed the outstanding amount for GRN #{$grnId}.");
            }

            $maxPayable = $outstanding - $discount;

            $paymentAmount = array_key_exists('payment_amount', $row) && $row['payment_amount'] !== null
                ? (float) $row['payment_amount']
                : $maxPayable;

            // No upper bound here — paying more than maxPayable is a deliberate overpayment.
            // confirm() converts the excess into an over_payment credit note, available for
            // setoff on a future payment, rather than rejecting it.
            if ($paymentAmount < 0) {
                abort(422, "Payment amount for GRN #{$grnId} cannot be negative.");
            }

            if ($paymentAmount <= 0 && $discount <= 0) {
                continue;
            }

            $grn = $grns[$grnId] ?? null;

            $rows[] = [
                'payment_id'         => $payment->id,
                'reference_type'     => $row['reference_type'] ?? 'grn',
                'reference_id'       => $grnId,
                'grn_date'           => $grn->grn_date ?? null,
                'po_no'              => $grn->po_no ?? null,
                'reference_no'       => $grn->reference_no ?? null,
                'grn_amount'         => $grn->total_amount ?? 0,
                'due_date'           => $row['due_date'] ?? null,
                'outstanding_before' => $outstanding,
                'discount'           => $discount,
                'payment_amount'     => $paymentAmount,
                'line_remark'        => $row['line_remark'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];

            $grossAmount   += $paymentAmount;
            $discountTotal += $discount;
        }

        if (!empty($rows)) {
            SupplierPaymentAllocation::insert($rows);
        }

        return [$grossAmount, $discountTotal];
    }

    /**
     * @param array<array{setoff_type:string, credit_note_id:?int, amount:float, remark:?string}> $setoffs
     * @return float total setoff amount
     */
    private function syncSetoffs(SupplierPayment $payment, array $setoffs): float
    {
        $payment->setoffs()->delete();

        $rows  = [];
        $total = 0.0;

        foreach ($setoffs as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $rows[] = [
                'payment_id'     => $payment->id,
                'setoff_type'    => $row['setoff_type'],
                'credit_note_id' => !empty($row['credit_note_id']) ? (int) $row['credit_note_id'] : null,
                'amount'         => $amount,
                'remark'         => $row['remark'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            $total += $amount;
        }

        if (!empty($rows)) {
            SupplierPaymentSetoff::insert($rows);
        }

        return $total;
    }

    /**
     * @param array<array{payment_mode_id:int, amount:float, bank_name:?string, bank_account_no:?string, reference_no:?string, instrument_date:?string, is_thirdparty:?bool, remark:?string}> $settlements
     * @return float total settlement amount
     */
    private function syncSettlements(SupplierPayment $payment, array $settlements): float
    {
        $payment->settlements()->delete();

        $modeIds = collect($settlements)->pluck('payment_mode_id')->filter()->unique()->values()->all();
        $modes   = empty($modeIds) ? collect() : PaymentMode::whereIn('id', $modeIds)->get()->keyBy('id');

        $rows  = [];
        $total = 0.0;

        foreach ($settlements as $row) {
            $modeId = (int) ($row['payment_mode_id'] ?? 0);
            $amount = (float) ($row['amount'] ?? 0);
            if ($modeId <= 0 || $amount <= 0) {
                continue;
            }

            $mode = $modes[$modeId] ?? null;

            $rows[] = [
                'payment_id'        => $payment->id,
                'payment_mode_id'   => $modeId,
                'payment_mode_code' => $mode->code               ?? '',
                'payment_mode_name' => $mode->payment_mode_name  ?? '',
                'amount'            => $amount,
                'bank_name'         => $row['bank_name']         ?? null,
                'bank_account_no'   => $row['bank_account_no']   ?? null,
                'reference_no'      => $row['reference_no']      ?? null,
                'instrument_date'   => $row['instrument_date']   ?? null,
                'is_thirdparty'     => (bool) ($row['is_thirdparty'] ?? false),
                'remark'            => $row['remark']            ?? null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];

            $total += $amount;
        }

        if (!empty($rows)) {
            SupplierPaymentSettlement::insert($rows);
        }

        return $total;
    }

    /**
     * Attach the live grn_no to loaded allocation rows (display only — the allocation
     * snapshot doesn't persist it). Allocations are never saved after this runs.
     */
    private function attachGrnNumbers(SupplierPayment $payment): void
    {
        $grnIds = $payment->allocations
            ->where('reference_type', 'grn')
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        if (empty($grnIds)) {
            return;
        }

        $grnNos = DB::table('inv_goods_received_notes')
            ->whereIn('id', $grnIds)
            ->pluck('grn_no', 'id');

        foreach ($payment->allocations as $allocation) {
            if ($allocation->reference_type === 'grn') {
                $allocation->setAttribute('grn_no', $grnNos[$allocation->reference_id] ?? null);
            }
        }
    }

    /** @param array<SupplierPayment> $payments */
    private function attachSupplierSnapshots(array $payments): void
    {
        $supplierIds = collect($payments)->pluck('supplier_id')->filter()->unique()->values()->all();
        if (empty($supplierIds)) {
            return;
        }

        $suppliers = DB::table('inv_supplier_masters')
            ->whereIn('id', $supplierIds)
            ->get(['id', 'supplier_name', 'supplier_code'])
            ->keyBy('id');

        foreach ($payments as $payment) {
            $s = $suppliers[$payment->supplier_id] ?? null;
            // setRelation (not setAttribute) — keeps this out of $attributes so a later
            // save()/update() on this same instance never tries to persist it as a column.
            $payment->setRelation('supplier', $s ? [
                'id'            => $s->id,
                'name'          => $s->supplier_name,
                'supplier_code' => $s->supplier_code,
            ] : null);
        }
    }
}
