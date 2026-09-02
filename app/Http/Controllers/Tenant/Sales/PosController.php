<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Store;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS / walk-in quick-sale screen. Frontend-only: the actual sale is
 * submitted through the existing `POST /sales` route
 * (App\Http\Controllers\Tenant\Sales\SaleController::store()), so this
 * controller only needs to hand the page the same reference lists
 * SaleController::index() already provides.
 */
class PosController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Sales/Pos', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
