<?php

use App\Models\Customer;
use App\Models\SaleNoteTemplate;
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

function provisionSaleNoteTemplateTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSaleNoteTemplateTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can save and delete a sale note template', function () {
    $domain = 'sale-note-template-crud.tenant-test';
    $tenant = provisionSaleNoteTemplateTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginSaleNoteTemplateTestUser($domain);

    $store = $this->post("http://{$domain}/sales/note-templates", [
        'text' => 'Thank you for your business.',
    ]);
    $store->assertRedirect();

    $templateId = null;
    $tenant->run(function () use (&$templateId) {
        $template = SaleNoteTemplate::query()->where('text', 'Thank you for your business.')->firstOrFail();
        $templateId = $template->id;
    });

    $destroy = $this->delete("http://{$domain}/sales/note-templates/{$templateId}");
    $destroy->assertRedirect();

    $tenant->run(function () use ($templateId) {
        expect(SaleNoteTemplate::find($templateId))->toBeNull();
    });

    $tenant->delete();
});

test('a sale note template requires text', function () {
    $domain = 'sale-note-template-validation.tenant-test';
    $tenant = provisionSaleNoteTemplateTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginSaleNoteTemplateTestUser($domain);

    $this->post("http://{$domain}/sales/note-templates", ['text' => ''])
        ->assertSessionHasErrors('text');

    $tenant->delete();
});

test('the sales create form data carries saved note templates and the walk-in customer', function () {
    $domain = 'sales-note-templates-prop.tenant-test';
    $tenant = provisionSaleNoteTemplateTestTenant($domain);

    $walkInId = null;
    $tenant->run(function () use (&$walkInId) {
        User::factory()->create(['email' => 'owner@example.com']);
        SaleNoteTemplate::create(['text' => 'Goods once sold are not returnable.']);
        $walkInId = Customer::walkIn()->id;
    });

    loginSaleNoteTemplateTestUser($domain);

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('noteTemplates', 1)
            ->where('noteTemplates.0.text', 'Goods once sold are not returnable.')
            ->where('walkInCustomerId', $walkInId)
        );

    $tenant->delete();
});

test('the pos page data carries the walk-in customer id', function () {
    $domain = 'pos-walk-in-prop.tenant-test';
    $tenant = provisionSaleNoteTemplateTestTenant($domain);

    $walkInId = null;
    $tenant->run(function () use (&$walkInId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $walkInId = Customer::walkIn()->id;
    });

    loginSaleNoteTemplateTestUser($domain);

    $this->get("http://{$domain}/pos")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('walkInCustomerId', $walkInId));

    $tenant->delete();
});
