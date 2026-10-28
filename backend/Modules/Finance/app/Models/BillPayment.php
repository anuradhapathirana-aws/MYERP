<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\SupplierMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\BillPaymentStatus;

/**
 * A payment to a supplier that settles one or more of their bills.
 *
 * $fillable is deliberately narrow, as on Bill. payment_no, status,
 * total_amount, the payment-mode name/code snapshot, journal numbers and every
 * confirmed_* / voided_* / created_by column are set by BillPaymentService alone.
 */
class BillPayment extends Model
{
    use SoftDeletes;

    public const AUDIT_TYPE = 'bill_payment';

    protected $table = 'fin_bill_payments';

    protected $fillable = [
        'supplier_id',
        'company_id',
        'payment_date',
        'reference_no',
        'pay_from_account_id',
        'payment_mode_id',
        'cheque_no',
        'cheque_date',
        'description',
    ];

    protected $casts = [
        'supplier_id'         => 'integer',
        'company_id'          => 'integer',
        'pay_from_account_id' => 'integer',
        'payment_date'        => 'date',
        'cheque_date'         => 'date',
        'reversal_date'       => 'date',
        'exchange_rate'       => 'decimal:6',
        'total_amount'        => 'decimal:2',
        'payment_mode_id'     => 'integer',
        'status'              => BillPaymentStatus::class,
        'confirmed_at'        => 'datetime',
        'voided_at'           => 'datetime',
        'confirmed_by'        => 'integer',
        'voided_by'           => 'integer',
        'created_by'          => 'integer',
        'updated_by'          => 'integer',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class, 'bill_payment_id')->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function payFromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'pay_from_account_id');
    }

    /** Soft link to the shared core master; read-only lookup, never a cascade. */
    public function paymentMode(): BelongsTo
    {
        return $this->belongsTo(PaymentMode::class, 'payment_mode_id')->withTrashed();
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

    /** " 001234 " and "001234" are the same cheque. Leading zeros are kept: they are printed on the leaf. */
    public static function normalizeChequeNo(?string $chequeNo): ?string
    {
        if ($chequeNo === null) {
            return null;
        }

        $clean = mb_strtoupper(trim($chequeNo));

        return $clean === '' ? null : $clean;
    }
}
