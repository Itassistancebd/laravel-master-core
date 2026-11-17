<?php

namespace MasterCore\RolesPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Permission extends Model
{
    protected $table = 'master_core_permissions';

    protected $fillable = ['group_name', 'description'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'master_core_role_has_permissions',
            'permission_id',
            'role_id',
        );
    }
}
