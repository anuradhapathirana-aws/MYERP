<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMaster extends Model
{
    use HasFactory;

    protected $table = 'inv_customer_masters';

    protected $fillable = [
        'customer_code',
        'reference_no',
        'title',
        'customer_type',
        'customer_name',
        'nic_passport_driving_licence',
        'attachments',
        'br_no',
        'customer_tin',
        'customer_mobile',
        'customer_land_line',
        'customer_email',
        'customer_fax',
        'billing_address_line1',
        'billing_address_line2',
        'billing_address_line3',
        'billing_city',
        'billing_zip_postal',
        'billing_state_province',
        'billing_country',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_address_line3',
        'shipping_city',
        'shipping_zip_postal',
        'shipping_state_province',
        'shipping_country',
        'sale_manager',
        'sales_executive',
        'sales_person',
    ];

    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(CustomerAttachment::class, 'customer_master_id');
    }

    /**
     * Sri Lanka IRD: a VAT registration number is the 9-digit TIN followed by the
     * VAT tax-type code 7000 (e.g. 123456789-7000). Only such a purchaser is billed
     * a Tax invoice by default; a plain TIN, another tax type, or no TIN is Non Tax.
     * Spaces and dashes are ignored; the check is strict (exactly 9 + 4 digits).
     */
    public static function isVatRegisteredTin(?string $tin): bool
    {
        $digits = preg_replace('/[\s\-]/', '', (string) $tin);

        return (bool) preg_match('/^\d{9}7000$/', $digits);
    }

    public function isVatRegistered(): bool
    {
        return self::isVatRegisteredTin($this->customer_tin);
    }
}
