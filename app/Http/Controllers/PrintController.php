<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Swap;
use App\Services\SetupProgress;
use App\Support\GovernmentInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Section 9b: printable invoices on a separate minimal layout — no sidebar, no
 * buttons, black on white, logo and shop info from Settings.
 */
class PrintController extends Controller
{
    public function sale(Request $request, Sale $sale, SetupProgress $setup): View
    {
        // The last step of the setup checklist, and the only one that cannot be
        // read from the shop's own data: printing leaves no trace of its own.
        // Written at most once, ever, and only from the page that does it.
        $setup->recordPrinted();

        $sale->load('customer', 'user', 'items.product');

        /*
         * A government customer's invoice is A5, laid out as Soran's legal
         * invoice book — PROJECT_DOC Section 9, 2026-10-20. `?paper=a4` prints
         * the ordinary one instead, for the day somebody asks for it.
         */
        if (! $sale->customer->isGovernment() || $request->query('paper') === 'a4') {
            return view('print.sale', ['sale' => $sale]);
        }

        /*
         * Written on the shop's own paper when it has uploaded one — Soran,
         * 2026-10-20: *"please use this without change any thing"* — and on
         * the drawn page when it has not.
         */
        $paper = BrandingController::paperUrl();

        return $paper
            ? view('print.sale-government-paper', ['sale' => $sale, 'paper' => $paper, 'fill' => GovernmentInvoice::fill($sale)])
            : view('print.sale-government', ['sale' => $sale, 'inWords' => GovernmentInvoice::words($sale->total_amount)]);
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

    /**
     * ⚠️ A swap prints as a one-line document, because a swap IS one line —
     * one product, handed over again. It is on paper for the same reason the
     * two returns are: the customer may be given it, and a shop that can hand
     * over a printed SRT but not a printed SWP looks like a shop that is not
     * sure a swap is a real document.
     */
    public function swap(Swap $swap): View
    {
        return view('print.swap', [
            'swap' => $swap->load('sale.customer', 'product', 'user', 'purchaseReturn.purchase.supplier'),
        ]);
    }
}
