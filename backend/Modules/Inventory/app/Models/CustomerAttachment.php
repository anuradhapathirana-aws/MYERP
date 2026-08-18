<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAttachment extends Model
{
    protected $table = 'inv_customer_attachments';

    protected $fillable = [
        'customer_master_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_master_id');
    }
}
