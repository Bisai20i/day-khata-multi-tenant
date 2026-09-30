<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permissions\EffectivePermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role_id', 'store_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Memoized EffectivePermissions::for() result for this instance. A plain
     * protected property, not an attribute: toArray()/toJson(), Inertia props
     * and SerializesModels never see it, so the permission set cannot leak
     * into a response or a queued payload by accident.
     *
     * @var array<string, true>|null
     */
    protected ?array $effectivePermissionsMemo = null;

    /**
     * The EffectivePermissions::fingerprint() the memo above was built from.
     */
    protected ?string $effectivePermissionsFingerprint = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_owner' => 'boolean',
        ];
    }

    /**
     * Whether this user is the tenant owner. is_owner is deliberately not
     * mass-assignable (see #[Fillable]): it is set only by tenant provisioning
     * and the ownership transfer action, never from request input.
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner;
    }

    /**
     * Whether this user may use the app. is_active is NOT NULL DEFAULT true in
     * the schema, so a null here only means "not loaded on this instance"
     * (e.g. a freshly created model before refresh()), which is active. Only
     * an explicit false denies.
     */
    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    /**
     * The user's effective permission keys in the current tenant as a
     * flipped set (key => true); see EffectivePermissions for the formula.
     *
     * Memoized on this instance: the auth guard, $user->can(), Gate::forUser()
     * and the `can:` middleware all share the same User instance for a
     * request, so the role is read at most once however many checks run.
     * The memo is keyed by a fingerprint of its inputs (tenant, its raw
     * enabled_modules, is_active, is_owner, role_id and the loaded role's raw
     * permissions), so changes made on these same instances invalidate it on
     * the next read without any extra query.
     *
     * @return array<string, true>
     */
    public function effectivePermissions(): array
    {
        $fingerprint = EffectivePermissions::fingerprint($this);

        if ($this->effectivePermissionsMemo === null || $this->effectivePermissionsFingerprint !== $fingerprint) {
            $this->effectivePermissionsMemo = EffectivePermissions::for($this);
            $this->effectivePermissionsFingerprint = $fingerprint;
        }

        return $this->effectivePermissionsMemo;
    }

    /**
     * Whether the user holds the given permission key in the current tenant.
     */
    public function hasPermission(string $key): bool
    {
        return isset($this->effectivePermissions()[$key]);
    }

    /**
     * Forget the memoized permission set and the loaded role relation, so the
     * next check re-reads the role from the database.
     *
     * Call this after changing this user's role or a role's permissions
     * through a different model instance in the same request (role editor,
     * users page, ownership transfer), when the result must be visible to
     * later checks on this instance, e.g. before building the Inertia
     * `auth.can` share on the redirect-less response. Edits made on this very
     * instance (role_id, is_owner, is_active) are picked up automatically.
     */
    public function flushEffectivePermissions(): void
    {
        $this->effectivePermissionsMemo = null;
        $this->effectivePermissionsFingerprint = null;
        $this->unsetRelation('role');
    }

    /**
     * The role assigned to this user.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The store this user is optionally scoped to. Null means all stores -
     * a soft default for pre-filling transaction forms, not an
     * authorization boundary.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
