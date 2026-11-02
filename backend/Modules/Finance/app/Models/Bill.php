<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Company;
use App\Models\Location;
use App\Models\SupplierMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\PaymentStatus;

/**
 * A supplier bill for an expense or service.
 *
 * $fillable is deliberately narrow. bill_no, status, payment_status,
 * paid_amount, journal numbers and every confirmed_* / voided_* / created_by
 * column are set by BillService alone -- a request that sends them is ignored,
 * not trusted.
 *
 * supplier(), company() and location() point at CORE models over soft links,
 * so the bill works with Inventory switched off.
 */
class Bill extends Model
{
    use SoftDeletes;

    public const AUDIT_TYPE = 'bill';

    protected $table = 'fin_bills';

    protected $fillable = [
        'supplier_id',
        'transaction_date',
        'document_no',
        'document_date',
        'due_date',
        'company_id',
        'location_id',
        'description',
    ];

    protected $casts = [
        'supplier_id'      => 'integer',
        'company_id'       => 'integer',
        'location_id'      => 'integer',
        'transaction_date' => 'date',
        'document_date'    => 'date',
        'due_date'         => 'date',
        'reversal_date'    => 'date',
        'exchange_rate'    => 'decimal:6',
        'total_amount'     => 'decimal:2',
        'tax_amount'       => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'status'           => BillStatus::class,
        'payment_status'   => PaymentStatus::class,
        'confirmed_at'     => 'datetime',
        'voided_at'        => 'datetime',
        'confirmed_by'     => 'integer',
        'voided_by'        => 'integer',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id')->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'attachable_id')
            ->where('attachable_type', self::AUDIT_TYPE)
            ->orderBy('id');
    }

    /** Every allocation from a bill payment, any status. */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class, 'bill_id');
    }

    /** What is still owed, worked in integer cents so no float drift creeps in. */
    public function balance(): string
    {
        return self::fromCents(self::toCents($this->total_amount) - self::toCents($this->paid_amount));
    }

    /** "1234.5" -> 123450. Money is summed as integer cents, never floats. */
    public static function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function isOverdue(): bool
    {
        return $this->status === BillStatus::Confirmed
            && $this->payment_status !== PaymentStatus::Paid
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }

    /** Confirmed, not fully paid, past due. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', BillStatus::Confirmed->value)
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->whereDate('due_date', '<', today());
    }

    /** "  dlg-5521 " and "DLG 5521" are the same supplier invoice. */
    public static function normalizeDocumentNo(string $documentNo): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\-\/_.]+/u', '', trim($documentNo)));
    }
}
