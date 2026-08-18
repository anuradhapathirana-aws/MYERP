<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAttachment extends Model
{
    protected $table = 'inv_supplier_attachments';

    protected $fillable = [
        'supplier_master_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_master_id');
    }
}
