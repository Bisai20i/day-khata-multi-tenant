<?php

use App\Models\Customer;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * `PrintLog` is polymorphic and knows nothing about what it is pointed at, so
 * these tests deliberately use plain `Item` and `Customer` rows as the printed
 * documents. Sales, purchases and returns are being rewritten by T04-T07 in
 * parallel; pinning this suite to their constructors would make it fail for
 * reasons that have nothing to do with print logging.
 */
function provisionPrintLogTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsPrintLogAdmin(string $domain, string $email = 'boss@example.com'): void
{
    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    User::factory()->create([
        'email' => $email,
        'password' => 'password',
        'role_id' => Role::query()->where('slug', 'admin')->value('id'),
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

test('record hands out copy numbers 1, 2 then 3 for repeated prints of one document', function () {
    $tenant = provisionPrintLogTestTenant('print-log-copies.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        expect(PrintLog::record($item, $user))->toBe(1)
            ->and(PrintLog::record($item, $user))->toBe(2)
            ->and(PrintLog::record($item, $user))->toBe(3);

        expect(PrintLog::query()->count())->toBe(3)
            ->and(PrintLog::copiesPrinted($item))->toBe(3);
    });

    $tenant->delete();
});

test('copy numbers are counted per document, not globally', function () {
    $tenant = provisionPrintLogTestTenant('print-log-per-document.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create();
        $first = Item::factory()->create();
        $second = Item::factory()->create();

        expect(PrintLog::record($first, $user))->toBe(1)
            ->and(PrintLog::record($second, $user))->toBe(1)
            ->and(PrintLog::record($first, $user))->toBe(2)
            ->and(PrintLog::record($second, $user))->toBe(2);
    });

    $tenant->delete();
});

test('copy numbers are counted per document type, so two types sharing an id do not collide', function () {
    $tenant = provisionPrintLogTestTenant('print-log-per-type.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $customer = Customer::factory()->create();

        PrintLog::record($item, $user);

        // Different morph type; its own series even if the ids match.
        expect(PrintLog::record($customer, $user))->toBe(1);
    });

    $tenant->delete();
});

test('record stores who printed, what was printed and when', function () {
    $tenant = provisionPrintLogTestTenant('print-log-columns.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create(['name' => 'Sita Rai']);
        $item = Item::factory()->create();

        PrintLog::record($item, $user);

        $log = PrintLog::query()->firstOrFail();

        expect($log->printable_type)->toBe(Item::class)
            ->and($log->printable_id)->toBe($item->id)
            ->and($log->copy_number)->toBe(1)
            ->and($log->printed_by)->toBe($user->id)
            ->and($log->printed_at)->not->toBeNull()
            ->and($log->printedBy->name)->toBe('Sita Rai')
            ->and($log->printable->is($item))->toBeTrue();
    });

    $tenant->delete();
});

test('the database refuses a second row with the same copy number for one document', function () {
    $tenant = provisionPrintLogTestTenant('print-log-unique.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        PrintLog::record($item, $user);

        // What a lost race would look like: the unique index is the backstop
        // that stops two prints both calling themselves the original.
        expect(fn () => PrintLog::query()->create([
            'printable_type' => Item::class,
            'printable_id' => $item->id,
            'copy_number' => 1,
            'printed_by' => $user->id,
            'printed_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    $tenant->delete();
});

test('the print log report lists prints newest first with both calendars', function () {
    $domain = 'print-log-report.tenant-test';
    $tenant = provisionPrintLogTestTenant($domain);

    $itemId = null;

    $tenant->run(function () use (&$itemId) {
        $user = User::factory()->create(['name' => 'Sita Rai']);
        $item = Item::factory()->create();
        $itemId = $item->id;

        PrintLog::query()->create([
            'printable_type' => Item::class,
            'printable_id' => $item->id,
            'copy_number' => 1,
            'printed_by' => $user->id,
            'printed_at' => '2026-06-01 10:00:00',
        ]);
        PrintLog::query()->create([
            'printable_type' => Item::class,
            'printable_id' => $item->id,
            'copy_number' => 2,
            'printed_by' => $user->id,
            'printed_at' => '2026-06-02 11:30:00',
        ]);
    });

    loginAsPrintLogAdmin($domain);

    $this->get("http://{$domain}/reports/print-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/PrintLog')
            ->has('logs.data', 2)
            // Newest first: the reprint is the row an auditor looks for.
            ->where('logs.data.0.copy_number', 2)
            ->where('logs.data.0.is_copy', true)
            ->where('logs.data.0.document_type', 'Item')
            ->where('logs.data.0.document_number', '#'.$itemId)
            ->where('logs.data.0.printed_by', 'Sita Rai')
            ->where('logs.data.0.printed_at_ad', '2026-06-02 11:30')
            ->where('logs.data.0.printed_at_bs', '2083-02-19')
            ->where('logs.data.1.copy_number', 1)
            ->where('logs.data.1.is_copy', false)
        );

    $tenant->delete();
});

test('the print log report filters by date and by reprints only', function () {
    $domain = 'print-log-filters.tenant-test';
    $tenant = provisionPrintLogTestTenant($domain);

    $tenant->run(function () {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        foreach ([['2026-06-01 09:00:00', 1], ['2026-06-10 09:00:00', 2], ['2026-07-01 09:00:00', 3]] as [$printedAt, $copyNumber]) {
            PrintLog::query()->create([
                'printable_type' => Item::class,
                'printable_id' => $item->id,
                'copy_number' => $copyNumber,
                'printed_by' => $user->id,
                'printed_at' => $printedAt,
            ]);
        }
    });

    loginAsPrintLogAdmin($domain);

    $this->get("http://{$domain}/reports/print-log?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 2));

    $this->get("http://{$domain}/reports/print-log?copies_only=1")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 2)
            ->where('logs.data.0.copy_number', 3)
            ->where('logs.data.1.copy_number', 2)
        );

    $this->get('http://'.$domain.'/reports/print-log?printable_type='.urlencode(Customer::class))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 0));

    $tenant->delete();
});

test('the print log report still names a document that has since been deleted', function () {
    $domain = 'print-log-orphan.tenant-test';
    $tenant = provisionPrintLogTestTenant($domain);

    $tenant->run(function () {
        $user = User::factory()->create();

        PrintLog::query()->create([
            'printable_type' => Item::class,
            'printable_id' => 999999,
            'copy_number' => 1,
            'printed_by' => $user->id,
            'printed_at' => '2026-06-01 09:00:00',
        ]);
    });

    loginAsPrintLogAdmin($domain);

    $this->get("http://{$domain}/reports/print-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.document_number', '#999999')
        );

    $tenant->delete();
});

test('the print log report offers only the document types that appear in it', function () {
    $domain = 'print-log-types.tenant-test';
    $tenant = provisionPrintLogTestTenant($domain);

    $tenant->run(function () {
        $user = User::factory()->create();
        PrintLog::record(Item::factory()->create(), $user);
    });

    loginAsPrintLogAdmin($domain);

    $this->get("http://{$domain}/reports/print-log")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('documentTypes', 1)
            ->where('documentTypes.0.value', Item::class)
            ->where('documentTypes.0.label', 'Item')
        );

    $tenant->delete();
});

test('a non-admin cannot open the print log report', function () {
    $domain = 'print-log-forbidden.tenant-test';
    $tenant = provisionPrintLogTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create([
            'email' => 'clerk@example.com',
            'password' => 'password',
            'role_id' => Role::query()->where('slug', 'staff')->value('id'),
        ]);
    });

    $this->post("http://{$domain}/login", ['email' => 'clerk@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/print-log")->assertForbidden();

    $tenant->delete();
});
