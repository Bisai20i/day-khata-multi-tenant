<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `permissions` is the JSON list of permission keys this role grants; it is
 * null for roles not yet backfilled. `is_system` marks roles the app depends
 * on (e.g. admin) that must not be deleted or renamed by users.
 */
#[Fillable(['name', 'slug', 'permissions', 'is_system'])]
class Role extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /**
     * The legacy permission rows granted to this role via the permission_role
     * pivot. Renamed from permissions() because that name now belongs to the
     * JSON attribute. Kept only until P16 drops the legacy tables; do not use
     * it for authorization.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function legacyPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * The users assigned to this role.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
