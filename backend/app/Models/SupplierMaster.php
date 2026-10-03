<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierMaster extends Model
{
    use HasFactory;

    protected $table = 'inv_supplier_masters';

    protected $fillable = [
        'supplier_code',
        'reference_no',
        'supplier_group_id',
        'supplier_name',
        'check_writer_name',
        'mobile',
        'land_line',
        'email',
        'wechat',
        'whatsapp',
        'fax',
        'website',
        'bil_address_line_1',
        'bil_address_line_2',
        'bil_address_line_3',
        'bil_city',
        'bil_postal_code',
        'bil_country',
        'bil_state_province',
        'tax_type',
        'tax_no',
        'tax_regis_no',
        'credit_limit',
        'credit_period',
        'privileges_discount',
        'bank_name',
        'bank_branch',
        'bank_acc_holder_name',
        'bank_acc_no',
        'contact_person_name',
        'contact_person_designation',
        'contact_person_mobile',
        'contact_person_email',
        'contact_person_fax',
    ];

    protected $casts = [
        'credit_limit'        => 'decimal:2',
        'credit_period'       => 'integer',
        'privileges_discount' => 'decimal:2',
        'supplier_group_id'   => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SupplierGroup::class, 'supplier_group_id');
    }

    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(SupplierAttachment::class, 'supplier_master_id');
    }
}
