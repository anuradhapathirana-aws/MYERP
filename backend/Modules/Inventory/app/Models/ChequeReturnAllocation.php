<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one returned cheque had settled and now reopens.
 *
 *   invoice / grn                 money the cheque paid, owed again. With
 *                                 via_document_id: paid by the cheque's
 *                                 advance, spent on that other receipt /
 *                                 payment (credit_note_id names the advance).
 *   invoice_discount / grn_discount
 *                                 the settlement discount the cheque's own
 *                                 document gave on that invoice / GRN, taken
 *                                 back -- it was given for paying, and the
 *                                 payment failed.
 *   customer_credit_note / supplier_credit_note
 *                                 the unspent part of the advance the cheque
 *                                 created, cancelled.
 */
class ChequeReturnAllocation extends Model
{
    public const INVOICE              = 'invoice';
    public const GRN                  = 'grn';
    public const INVOICE_DISCOUNT     = 'invoice_discount';
    public const GRN_DISCOUNT         = 'grn_discount';
    public const CUSTOMER_CREDIT_NOTE = 'customer_credit_note';
    public const SUPPLIER_CREDIT_NOTE = 'supplier_credit_note';

    /** The types that make an invoice owed again (InvoiceBalanceService). */
    public const REOPENS_INVOICE = [self::INVOICE, self::INVOICE_DISCOUNT];

    /** The types that make a GRN owed again (GrnBalanceService). */
    public const REOPENS_GRN = [self::GRN, self::GRN_DISCOUNT];

    protected $table = 'inv_cheque_return_allocations';

    protected $fillable = [
        'cheque_return_id',
        'reference_type',
        'reference_id',
        'via_document_id',
        'credit_note_id',
        'amount',
    ];

    protected $casts = [
        'cheque_return_id' => 'integer',
        'reference_id'     => 'integer',
        'via_document_id'  => 'integer',
        'credit_note_id'   => 'integer',
        'amount'           => 'decimal:4',
    ];

    public function chequeReturn(): BelongsTo
    {
        return $this->belongsTo(ChequeReturn::class, 'cheque_return_id');
    }
}
