<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\View\View;

/**
 * Section 9b: printable invoices on a separate minimal layout — no sidebar, no
 * buttons, black on white, logo and shop info from Settings.
 */
class PrintController extends Controller
{
    public function sale(Sale $sale): View
    {
        return view('print.sale', [
            'sale' => $sale->load('customer', 'user', 'items.product'),
        ]);
    }

    public function purchase(Purchase $purchase): View
    {
        return view('print.purchase', [
            'purchase' => $purchase->load('supplier', 'user', 'items.product'),
        ]);
    }

    public function saleReturn(SaleReturn $saleReturn): View
    {
        return view('print.sale-return', [
            'return' => $saleReturn->load('sale', 'customer', 'user', 'items.product'),
        ]);
    }

    public function purchaseReturn(PurchaseReturn $purchaseReturn): View
    {
        return view('print.purchase-return', [
            'return' => $purchaseReturn->load('purchase', 'supplier', 'user', 'items.product'),
        ]);
    }
}
