<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of change history. Insert-only.
 *
 * update() and delete() throw rather than silently doing nothing, so a bug that
 * tries to rewrite history fails loudly in testing instead of passing quietly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'core_audit_logs';

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'auditable_id' => 'integer',
        'old_values'   => 'array',
        'new_values'   => 'array',
        'user_id'      => 'integer',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit log entries are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
