<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Payment;
use App\Models\PrintLog;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-07 (receipts print, with a C9 copy stamp) and G-14 (a receipt or
 * payment cannot settle a document dated after it; only a bank payment keeps
 * a bank account).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionReceiptPrintTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function receiptPrintAdmin(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create(['email' => 'owner@example.com', 'name' => 'Asha Admin', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function receiptPrintCreditSale(User $admin, Customer $customer, string $date, string $rate = '1000'): Sale
{
    return Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => $date, 'payment_mode' => 'credit'],
        [['item_id' => Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id, 'quantity' => 1, 'rate' => $rate, 'discount' => 0]],
        $admin,
    );
}

function renderReceiptPrint(string $domain, int $receiptId): string
{
    $captured = null;
    $fakePdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));
    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data) use (&$captured) {
            $captured = [$view, $data];

            return $view === 'pdf.receipt';
        })
        ->andReturn($fakePdf);

    test()->get("http://{$domain}/receipts/{$receiptId}/print")->assertOk();

    return view($captured[0], $captured[1])->render();
}

test('a receipt prints its number, customer, the invoices it settles and the amount in words, and each print is a copy', function () {
    $domain = 'receipt-print.tenant-test';
    $tenant = provisionReceiptPrintTenant($domain);

    $receiptId = null;
    $invoiceNumber = null;
    $tenant->run(function () use (&$receiptId, &$invoiceNumber) {
        $admin = receiptPrintAdmin();
        $customer = Customer::factory()->create(['name' => 'Ram Stores']);
        $sale = receiptPrintCreditSale($admin, $customer, '2026-06-01');
        $invoiceNumber = $sale->invoice_number;

        $receiptId = Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-05', 'amount' => '750', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => '600']],
        ], $admin)->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $html = renderReceiptPrint($domain, $receiptId);

    expect($html)->toContain('Money Receipt')
        ->toContain('Receipt #')
        ->toContain('Ram Stores')
        ->toContain($invoiceNumber)
        ->toContain('600.00')
        // 750 received, 600 applied: 150 stays on account.
        ->toContain('150.00')
        ->toContain('750.00')
        ->toContain('Amount in Words')
        ->toContain('Original');

    expect(renderReceiptPrint($domain, $receiptId))->toContain('Copy of Original - 1');

    $tenant->run(function () use ($receiptId) {
        expect(PrintLog::where('printable_type', (new Receipt)->getMorphClass())->where('printable_id', $receiptId)->count())->toBe(2);
    });

    $tenant->delete();
});

test('a cancelled receipt still prints, marked cancelled with the reason', function () {
    $domain = 'receipt-print-cancelled.tenant-test';
    $tenant = provisionReceiptPrintTenant($domain);

    $receiptId = null;
    $tenant->run(function () use (&$receiptId) {
        $admin = receiptPrintAdmin();
        $receipt = Receipt::post(['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-05', 'amount' => '100', 'payment_mode' => 'cash'], $admin);
        $receipt->cancel($admin, 'Cheque bounced');
        $receiptId = $receipt->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    expect(renderReceiptPrint($domain, $receiptId))
        ->toContain('Cancelled')
        ->toContain('by Asha Admin')
        ->toContain('Cheque bounced')
        ->toContain('Received on account');

    $tenant->delete();
});

test('a receipt cannot be allocated to an invoice dated after it', function () {
    $domain = 'receipt-allocation-date.tenant-test';
    $tenant = provisionReceiptPrintTenant($domain);

    $customerId = null;
    $saleId = null;
    $tenant->run(function () use (&$customerId, &$saleId) {
        $admin = receiptPrintAdmin();
        $customer = Customer::factory()->create();
        $customerId = $customer->id;
        $saleId = receiptPrintCreditSale($admin, $customer, '2026-06-10')->id;

        // The model refuses it too, whatever the form sends.
        expect(fn () => Receipt::post([
            'customer_id' => $customer->id, 'date' => '2026-06-05', 'amount' => '100', 'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $saleId, 'amount' => '100']],
        ], $admin))->toThrow(InvalidArgumentException::class, 'after this receipt');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId, 'date' => '2026-06-05', 'amount' => '100', 'payment_mode' => 'cash',
        'allocations' => [['sale_id' => $saleId, 'amount' => '100']],
    ])->assertSessionHasErrors('allocations.0.sale_id');

    // Same day is fine.
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId, 'date' => '2026-06-10', 'amount' => '100', 'payment_mode' => 'cash',
        'allocations' => [['sale_id' => $saleId, 'amount' => '100']],
    ])->assertSessionHasNoErrors();

    $tenant->delete();
});

test('a payment cannot be allocated to a bill dated after it, and a cash payment keeps no bank account', function () {
    $domain = 'payment-allocation-date.tenant-test';
    $tenant = provisionReceiptPrintTenant($domain);

    $supplierId = null;
    $purchaseId = null;
    $bankId = null;
    $tenant->run(function () use (&$supplierId, &$purchaseId, &$bankId) {
        $admin = receiptPrintAdmin();
        $supplier = Supplier::factory()->create();
        $supplierId = $supplier->id;
        $bankId = Account::where('code', 'AS31')->value('id') ?? Account::factory()->create()->id;
        $purchaseId = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'credit'],
            [['item_id' => Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        )->id;

        $cashPayment = Payment::post([
            'supplier_id' => $supplier->id, 'date' => '2026-06-12', 'amount' => '50', 'payment_mode' => 'cash', 'bank_account_id' => $bankId,
        ], $admin);

        expect($cashPayment->bank_account_id)->toBeNull();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/payments", [
        'supplier_id' => $supplierId, 'date' => '2026-06-05', 'amount' => '100', 'payment_mode' => 'cash',
        'allocations' => [['purchase_id' => $purchaseId, 'amount' => '100']],
    ])->assertSessionHasErrors('allocations.0.purchase_id');

    $tenant->run(function () {
        // Only the cash payment above was posted.
        expect(Payment::count())->toBe(1);
    });

    $tenant->delete();
});

test('every page tells the frontend the signed-in user\'s role, not just role-gated ones', function () {
    $domain = 'shared-user-role.tenant-test';
    $tenant = provisionReceiptPrintTenant($domain);

    $tenant->run(function () {
        receiptPrintAdmin();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    // /receipts has no role middleware, which is what used to leave the
    // relation unloaded and hide the admin's Cancel button.
    $this->get("http://{$domain}/receipts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.role.slug', 'admin'));

    $tenant->delete();
});
