<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $kind `key` (single object) or `prefix` (every object under a directory)
 * @property string $target
 * @property string $status pending|done
 */
final class StorageDeletionTask extends Model
{
    public const KIND_KEY = 'key';

    public const KIND_PREFIX = 'prefix';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
