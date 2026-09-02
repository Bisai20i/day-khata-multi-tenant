<?php

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionAgentTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating an agent auto-creates a linked ledger account under Sales Agents', function () {
    $domain = 'agent-ledger.tenant-test';
    $tenant = provisionAgentTestTenant($domain);

    $tenant->run(function () {
        $agent = Agent::factory()->create(['name' => 'Himal Agency', 'mobile_no' => '9800000004']);

        expect($agent->account_id)->not->toBeNull();
        expect($agent->account->name)->toBe('Himal Agency');
        expect($agent->account->subgroup->name)->toBe('Sales Agents');
    });

    $tenant->delete();
});

test('an authenticated user can create, update, and delete an agent', function () {
    $domain = 'agent-crud.tenant-test';
    $tenant = provisionAgentTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/agents", [
        'name' => 'Everest Agents',
        'mobile_no' => '9844444445',
        'commission_rate' => 5,
        'is_active' => true,
    ]);
    $store->assertRedirect("http://{$domain}/agents");

    $agentId = null;
    $tenant->run(function () use (&$agentId) {
        $agent = Agent::query()->where('name', 'Everest Agents')->firstOrFail();
        $agentId = $agent->id;
        expect($agent->account_id)->not->toBeNull();
        expect((float) $agent->commission_rate)->toBe(5.0);
        expect($agent->is_active)->toBeTrue();
    });

    $update = $this->put("http://{$domain}/agents/{$agentId}", [
        'name' => 'Everest Sales Agents',
        'mobile_no' => '9844444445',
        'commission_rate' => 7.5,
        'is_active' => false,
    ]);
    $update->assertRedirect("http://{$domain}/agents");

    $tenant->run(function () use ($agentId) {
        $agent = Agent::query()->findOrFail($agentId);
        expect($agent->name)->toBe('Everest Sales Agents');
        expect($agent->account->name)->toBe('Everest Sales Agents');
        expect((float) $agent->commission_rate)->toBe(7.5);
        expect($agent->is_active)->toBeFalse();
    });

    $destroy = $this->delete("http://{$domain}/agents/{$agentId}");
    $destroy->assertRedirect("http://{$domain}/agents");

    $tenant->run(function () use ($agentId) {
        expect(Agent::query()->find($agentId))->toBeNull();
    });

    $tenant->delete();
});

test('a negative commission rate is rejected', function () {
    $domain = 'agent-invalid-rate.tenant-test';
    $tenant = provisionAgentTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/agents", [
        'name' => 'Bad Agent',
        'commission_rate' => -1,
    ]);
    $store->assertSessionHasErrors('commission_rate');

    $tenant->delete();
});
