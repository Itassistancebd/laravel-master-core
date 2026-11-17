<?php

namespace MasterCore\Settings\Models;

use Illuminate\Database\Eloquent\Model;

final class Setting extends Model
{
    protected $table = 'master_core_settings';

    protected $fillable = [
        'group_name',
        'setting_key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
