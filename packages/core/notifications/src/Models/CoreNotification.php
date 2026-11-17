<?php

namespace MasterCore\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/** Package-owned inbox row; content is created only by the closed event catalog. */
final class CoreNotification extends Model
{
    protected $table = 'master_core_notifications';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
