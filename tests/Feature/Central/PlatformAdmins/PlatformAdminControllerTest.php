<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

test('any platform admin can view the platform admin list', function () {
    $owner = PlatformAdmin::factory()->create();
    $support = PlatformAdmin::factory()->support()->create();

    $this->actingAs($owner, 'platform')->get(route('central.platform-admins.index'))->assertOk();
    $this->actingAs($support, 'platform')->get(route('central.platform-admins.index'))->assertOk();
});

test('an owner can create a new platform admin', function () {
    $owner = PlatformAdmin::factory()->create();

    $this->actingAs($owner, 'platform')
        ->post(route('central.platform-admins.store'), [
            'name' => 'New Support',
            'email' => 'support@platform.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'support',
        ])
        ->assertRedirect(route('central.platform-admins.index'));

    $created = PlatformAdmin::where('email', 'support@platform.test')->firstOrFail();

    expect($created->role->value)->toBe('support')
        ->and($created->is_active)->toBeTrue();

    expect(PlatformAdminActivityLog::where('action', 'platform_admin.create')
        ->where('platform_admin_id', $owner->id)
        ->exists())->toBeTrue();
});

test('a support admin cannot create, edit, or update platform admins', function () {
    $support = PlatformAdmin::factory()->support()->create();
    $other = PlatformAdmin::factory()->create();

    $this->actingAs($support, 'platform')->get(route('central.platform-admins.create'))->assertForbidden();

    $this->actingAs($support, 'platform')->post(route('central.platform-admins.store'), [
        'name' => 'Nope',
        'email' => 'nope@platform.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'support',
    ])->assertForbidden();

    $this->actingAs($support, 'platform')->get(route('central.platform-admins.edit', $other))->assertForbidden();

    $this->actingAs($support, 'platform')->put(route('central.platform-admins.update', $other), [
        'name' => $other->name,
        'email' => $other->email,
        'role' => 'support',
        'is_active' => true,
    ])->assertForbidden();
});

test('an owner can update another admin\'s role and status', function () {
    $owner = PlatformAdmin::factory()->create();
    $target = PlatformAdmin::factory()->support()->create();

    $this->actingAs($owner, 'platform')
        ->put(route('central.platform-admins.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'owner',
            'is_active' => false,
        ])
        ->assertRedirect(route('central.platform-admins.index'));

    $target->refresh();

    expect($target->role->value)->toBe('owner')
        ->and($target->is_active)->toBeFalse();
});

test('demoting or deactivating the last active owner is rejected', function () {
    $onlyOwner = PlatformAdmin::factory()->create();

    $this->actingAs($onlyOwner, 'platform')
        ->put(route('central.platform-admins.update', $onlyOwner), [
            'name' => $onlyOwner->name,
            'email' => $onlyOwner->email,
            'role' => 'support',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs($onlyOwner, 'platform')
        ->put(route('central.platform-admins.update', $onlyOwner), [
            'name' => $onlyOwner->name,
            'email' => $onlyOwner->email,
            'role' => 'owner',
            'is_active' => false,
        ])
        ->assertSessionHasErrors('role');

    expect($onlyOwner->fresh()->role->value)->toBe('owner')
        ->and($onlyOwner->fresh()->is_active)->toBeTrue();
});

test('demoting the last active owner is allowed when another active owner exists', function () {
    $ownerOne = PlatformAdmin::factory()->create();
    PlatformAdmin::factory()->create();

    $this->actingAs($ownerOne, 'platform')
        ->put(route('central.platform-admins.update', $ownerOne), [
            'name' => $ownerOne->name,
            'email' => $ownerOne->email,
            'role' => 'support',
            'is_active' => true,
        ])
        ->assertRedirect(route('central.platform-admins.index'));

    expect($ownerOne->fresh()->role->value)->toBe('support');
});

test('guests cannot access any platform admin management route', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->get(route('central.platform-admins.index'))->assertRedirect(route('login'));
    $this->get(route('central.platform-admins.create'))->assertRedirect(route('login'));
    $this->get(route('central.platform-admins.edit', $admin))->assertRedirect(route('login'));
});

test('a tenant-side web guard user cannot access platform admin management routes', function () {
    Auth::guard('platform')->logout();

    $webUser = User::factory()->make();

    $this->actingAs($webUser, 'web')->get(route('central.platform-admins.index'))->assertRedirect(route('login'));
});
