<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentModeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMode extends Model
{
    use SoftDeletes;

    /**
     * Codes the seeder ships and Inventory's logic reads. Their type is fixed:
     * changing it would make Finance and Inventory disagree about the mode.
     */
    public const BUILT_IN_CODES = ['cash', 'cheque', 'card', 'setoff', 'online_transfer', 'petty_cash'];

    protected $table = 'inv_payment_modes';

    protected $fillable = [
        'payment_mode_name',
        'code',
        'type',
        'requires_bank_details',
        'requires_reference_no',
        'requires_date',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'type'                  => PaymentModeType::class,
        'requires_bank_details' => 'boolean',
        'requires_reference_no' => 'boolean',
        'requires_date'         => 'boolean',
        'sort_order'            => 'integer',
        'is_active'             => 'boolean',
    ];

    /** The stored type, or what the code implies for a row saved without one. */
    public function effectiveType(): PaymentModeType
    {
        return $this->type ?? PaymentModeType::fromLegacyCode($this->code);
    }

    /** Judged on the saved code, so editing the code in the same request cannot unlock it. */
    public function isBuiltIn(): bool
    {
        return in_array(strtolower((string) $this->getOriginal('code')), self::BUILT_IN_CODES, true);
    }
}
