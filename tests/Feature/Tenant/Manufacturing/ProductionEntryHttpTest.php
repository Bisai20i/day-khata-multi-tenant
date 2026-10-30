<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\MaterialIssueNote;
use App\Models\PrintLog;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * HTTP-level coverage of the production entry routes (M04b). The model's own
 * accounting is covered by the M04a tests; here the routes, permission gates,
 * validation messages and the happy path through the controller are checked.
 */
function productionHttpTenant(string $domain): Tenant
{
    // Manufacturing is not a default module, so the tenant is entitled to it
    // explicitly or every manufacturing route answers 403.
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => [...config('permissions.default_modules'), 'manufacturing'],
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function productionHttpLogin(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

/**
 * Seeds an open year, an owner, 100 kg of raw material at 10.00, a posted and
 * unattached issue note for 40 kg (cost 400.00), and a finished good.
 *
 * @return array{admin: User, store: Store, raw: Item, finished: Item, note: MaterialIssueNote}
 */
function productionHttpSeed(): array
{
    FiscalYear::firstOrCreate(
        ['name' => '2026'],
        ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open],
    );

    $admin = User::factory()->create(['email' => 'owner@example.com']);
    $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();
    $raw = Item::factory()->rawMaterial()->create(['is_stockable' => true, 'name' => 'Flour']);
    $finished = Item::factory()->finishedGood()->create(['is_stockable' => true, 'name' => 'Biscuit']);

    $raw->recordStockMovement(StockMovementType::Opening, '100', '2026-06-01', $store->id, null, '10', '1000.00', null, '1000.00');

    $note = MaterialIssueNote::post(
        ['date' => '2026-06-10', 'store_id' => $store->id, 'narration' => 'Batch 1'],
        [['item_id' => $raw->id, 'quantity' => '40']],
        $admin,
    );

    return compact('admin', 'store', 'raw', 'finished', 'note');
}

/**
 * @return array<string, mixed>
 */
function productionHttpPayload(array $seed, array $overrides = []): array
{
    return array_merge([
        'date' => '2026-06-15',
        'store_id' => $seed['store']->id,
        'finished_item_id' => $seed['finished']->id,
        'planned_quantity' => '100',
        'narration' => 'First run',
        'material_issue_note_ids' => [$seed['note']->id],
    ], $overrides);
}

test('a production entry goes through the full life cycle via the routes', function () {
    $domain = 'production-http-happy.tenant-test';
    $tenant = productionHttpTenant($domain);

    $seed = null;
    $tenant->run(function () use (&$seed) {
        $seed = productionHttpSeed();
    });
    productionHttpLogin($domain);

    $cashId = null;
    $tenant->run(function () use (&$cashId) {
        $cashId = Account::where('code', 'AS1')->value('id');
    });

    $this->get("http://{$domain}/production-entries")->assertOk();
    $this->get("http://{$domain}/production-entries/create")->assertOk();

    $this->post("http://{$domain}/production-entries", productionHttpPayload($seed, [
        'direct_costs' => [[
            'date' => '2026-06-15',
            'cost_type' => 'labor',
            'description' => 'Baker wages',
            'amount' => '100.00',
            'credit_account_id' => $cashId,
        ]],
    ]))->assertSessionHasNoErrors();

    $entryId = null;
    $tenant->run(function () use (&$entryId, $seed) {
        $entry = ProductionEntry::firstOrFail();
        $entryId = $entry->id;
        expect($entry->status)->toBe('in_progress')
            ->and($entry->directCosts()->count())->toBe(1)
            ->and($seed['note']->fresh()->production_entry_id)->toBe($entry->id);
    });

    $this->get("http://{$domain}/production-entries/{$entryId}")->assertOk();

    $this->post("http://{$domain}/production-entries/{$entryId}/direct-costs", [
        'date' => '2026-06-16',
        'cost_type' => 'utilities',
        'description' => 'Power',
        'amount' => '50.00',
        'credit_account_id' => $cashId,
    ])->assertSessionHasNoErrors();

    $this->post("http://{$domain}/production-entries/{$entryId}/return-material", [
        'item_id' => $seed['raw']->id,
        'quantity' => '5',
        'date' => '2026-06-16',
    ])->assertSessionHasNoErrors();

    $this->post("http://{$domain}/production-entries/{$entryId}/complete", [
        'date' => '2026-06-17',
        'quantity' => '40',
        'is_final' => false,
    ])->assertSessionHasNoErrors();

    $this->post("http://{$domain}/production-entries/{$entryId}/complete", [
        'date' => '2026-06-18',
        'quantity' => '55',
        'is_final' => true,
    ])->assertSessionHasNoErrors();

    $this->get("http://{$domain}/production-entries/{$entryId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->run(function () use ($entryId) {
        $entry = ProductionEntry::findOrFail($entryId);
        expect($entry->status)->toBe('completed')
            ->and($entry->completions()->count())->toBe(2)
            ->and($entry->costSheet()['wip_balance'])->toBe('0.00')
            ->and(PrintLog::where('printable_type', $entry->getMorphClass())->where('printable_id', $entryId)->count())->toBe(1);
    });

    $this->get("http://{$domain}/production-entries?status=completed")->assertOk();

    $tenant->delete();
});

test('cancelling a direct cost and abandoning or cancelling an entry work through the routes', function () {
    $domain = 'production-http-cancel.tenant-test';
    $tenant = productionHttpTenant($domain);

    $seed = null;
    $cashId = null;
    $tenant->run(function () use (&$seed, &$cashId) {
        $seed = productionHttpSeed();
        $cashId = Account::where('code', 'AS1')->value('id');
    });
    productionHttpLogin($domain);

    $this->post("http://{$domain}/production-entries", productionHttpPayload($seed))->assertSessionHasNoErrors();
    $entryId = $tenant->run(fn () => ProductionEntry::firstOrFail()->id);

    $this->post("http://{$domain}/production-entries/{$entryId}/direct-costs", [
        'date' => '2026-06-16', 'cost_type' => 'machine', 'description' => 'Oven', 'amount' => '70.00', 'credit_account_id' => $cashId,
    ])->assertSessionHasNoErrors();
    $costId = $tenant->run(fn () => ProductionEntry::firstOrFail()->directCosts()->firstOrFail()->id);

    $this->post("http://{$domain}/production-entries/{$entryId}/direct-costs/{$costId}/cancel", ['reason' => 'Wrong amount'])
        ->assertSessionHasNoErrors();
    $this->post("http://{$domain}/production-entries/{$entryId}/cancel", [])->assertSessionHasErrors('reason');
    $this->post("http://{$domain}/production-entries/{$entryId}/abandon", ['reason' => 'Machine broke'])
        ->assertSessionHasNoErrors();

    $tenant->run(function () use ($entryId) {
        $entry = ProductionEntry::findOrFail($entryId);
        expect($entry->status)->toBe('abandoned')
            ->and($entry->directCosts()->firstOrFail()->cancelled_at)->not->toBeNull();
    });

    $tenant->delete();
});

test('every production route is forbidden without its permission', function () {
    $domain = 'production-http-403.tenant-test';
    $tenant = productionHttpTenant($domain);

    $entryId = null;
    $costId = null;
    $tenant->run(function () use (&$entryId, &$costId) {
        $seed = productionHttpSeed();
        $entry = ProductionEntry::factory()->create([
            'store_id' => $seed['store']->id,
            'finished_item_id' => $seed['finished']->id,
            'created_by' => $seed['admin']->id,
        ]);
        $entryId = $entry->id;
        // Route binding runs before the can: gate, so the cost must exist
        // or the request would 404 instead of 403.
        $costId = ProductionDirectCost::factory()->create(['production_entry_id' => $entry->id])->id;
    });

    $all = ['production_entries.view', 'production_entries.create', 'production_entries.edit', 'production_entries.cancel', 'production_entries.print'];

    // method, uri, the one permission the route is gated by.
    $routes = [
        ['get', '/production-entries', 'production_entries.view'],
        ['get', "/production-entries/{$entryId}", 'production_entries.view'],
        ['get', '/production-entries/create', 'production_entries.create'],
        ['post', '/production-entries', 'production_entries.create'],
        ['post', "/production-entries/{$entryId}/attach-notes", 'production_entries.edit'],
        ['post', "/production-entries/{$entryId}/direct-costs", 'production_entries.edit'],
        ['post', "/production-entries/{$entryId}/direct-costs/{$costId}/cancel", 'production_entries.edit'],
        ['post', "/production-entries/{$entryId}/return-material", 'production_entries.edit'],
        ['post', "/production-entries/{$entryId}/complete", 'production_entries.edit'],
        ['post', "/production-entries/{$entryId}/abandon", 'production_entries.cancel'],
        ['post', "/production-entries/{$entryId}/cancel", 'production_entries.cancel'],
        ['get', "/production-entries/{$entryId}/print", 'production_entries.print'],
    ];

    foreach ($routes as [$method, $uri, $permission]) {
        $email = 'no-'.str_replace('.', '-', $permission).'@example.com';

        $tenant->run(function () use ($all, $permission, $email) {
            if (User::where('email', $email)->exists()) {
                return;
            }
            User::factory()->create([
                'email' => $email,
                'role_id' => roleWithPermissions(array_values(array_diff($all, [$permission])), 'Role '.$permission)->id,
                'is_owner' => false,
            ]);
        });

        auth()->forgetGuards();
        productionHttpLogin($domain, $email);

        test()->{$method}("http://{$domain}{$uri}", [])->assertForbidden();

        test()->post("http://{$domain}/logout");
        auth()->forgetGuards();
    }

    $tenant->delete();
});

test('creating an entry without an issue note selection fails with a clear message', function () {
    $domain = 'production-http-no-notes.tenant-test';
    $tenant = productionHttpTenant($domain);

    $seed = null;
    $tenant->run(function () use (&$seed) {
        $seed = productionHttpSeed();
    });
    productionHttpLogin($domain);

    $this->post("http://{$domain}/production-entries", productionHttpPayload($seed, ['material_issue_note_ids' => []]))
        ->assertSessionHasErrors(['material_issue_note_ids' => 'Select at least one material issue note: a production entry needs issued material to work on.']);

    $tenant->run(fn () => expect(ProductionEntry::count())->toBe(0));

    $tenant->delete();
});

test('returning more material than is in WIP is rejected as a form error', function () {
    $domain = 'production-http-over-return.tenant-test';
    $tenant = productionHttpTenant($domain);

    $seed = null;
    $tenant->run(function () use (&$seed) {
        $seed = productionHttpSeed();
    });
    productionHttpLogin($domain);

    $this->post("http://{$domain}/production-entries", productionHttpPayload($seed))->assertSessionHasNoErrors();
    $entryId = $tenant->run(fn () => ProductionEntry::firstOrFail()->id);

    // 40 kg went into WIP; 41 cannot come back.
    $this->post("http://{$domain}/production-entries/{$entryId}/return-material", [
        'item_id' => $seed['raw']->id,
        'quantity' => '41',
        'date' => '2026-06-16',
    ])->assertSessionHasErrors();

    $tenant->run(fn () => expect(ProductionEntry::findOrFail($entryId)->costSheet()['wip_balance'])->toBe('400.00'));

    $tenant->delete();
});
