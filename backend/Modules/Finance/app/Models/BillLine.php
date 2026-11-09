<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Finance\Enums\ChargeTreatment;

/**
 * One line of a bill. Written only by BillService.
 *
 *   amount           before tax
 *   tax_amount       tax on the line
 *   line_total       amount + tax -- what the supplier is owed for it
 *   recoverable_tax  the claimable part, posted to Input Tax Recoverable
 *
 * The line's account takes line_total - recoverable_tax: the goods or service
 * plus any tax that cannot be claimed.
 */
class BillLine extends Model
{
    /** reference_type of a line linked to a GRN (soft link into Inventory). */
    public const REFERENCE_GRN = 'grn';

    protected $table = 'fin_bill_lines';

    protected $fillable = [
        'line_no',
        'charge_type_id',
        'treatment',
        'ledger_account_id',
        'description',
        'amount',
        'amount_incl',
        'tax_code_id',
        'tax_amount',
        'line_total',
        'recoverable_tax',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'bill_id'           => 'integer',
        'line_no'           => 'integer',
        'charge_type_id'    => 'integer',
        'treatment'         => ChargeTreatment::class,
        'ledger_account_id' => 'integer',
        'amount'            => 'decimal:2',
        'amount_incl'       => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'tax_code_id'       => 'integer',
        'line_total'        => 'decimal:2',
        'recoverable_tax'   => 'decimal:2',
        'reference_id'      => 'integer',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class, 'charge_type_id');
    }

    /** Core master, so a plain belongsTo is fine (no module boundary). */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(BillLineTax::class, 'bill_line_id');
    }

    /** The GRN this charge belongs to, or null. */
    public function grnId(): ?int
    {
        return $this->reference_type === self::REFERENCE_GRN ? $this->reference_id : null;
    }

    /** What the line's own account is charged: everything but the claimable tax. */
    public function costAmount(): string
    {
        return Bill::fromCents(Bill::toCents($this->line_total) - Bill::toCents($this->recoverable_tax));
    }
}
