<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnAttachment extends Model
{
    protected $table = 'inv_grn_attachments';

    protected $fillable = [
        'grn_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }
}
