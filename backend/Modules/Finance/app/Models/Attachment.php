<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file attached to a Finance document.
 *
 * stored_path is hidden: the client only ever sees an id and downloads through
 * the controller, which checks permission and company access first.
 */
class Attachment extends Model
{
    protected $table = 'fin_attachments';

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'original_name',
        'stored_path',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $hidden = ['stored_path'];

    protected $casts = [
        'attachable_id' => 'integer',
        'size'          => 'integer',
        'uploaded_by'   => 'integer',
    ];
}
