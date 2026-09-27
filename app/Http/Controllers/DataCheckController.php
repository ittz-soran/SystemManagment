<?php

namespace App\Http\Controllers;

use App\Services\DataIntegrityService;
use App\Services\ShopHealth;
use Illuminate\View\View;

/**
 * Section 10b's global assertions, pointed at the shop's real data.
 *
 * Read-only, and deliberately so. It reports and it does not touch: a page that
 * silently "fixed" a contradiction would destroy the evidence of what went
 * wrong, and the difference between a cache to rebuild and two records that
 * cannot both be right is exactly the judgement a person has to make.
 *
 * Where a repair does exist and is safe — Recheck stock adds the batches up
 * again — the finding links to it rather than doing it.
 */
class DataCheckController extends Controller
{
    public function index(DataIntegrityService $integrity, ShopHealth $health): View
    {
        return view('data-check.index', [
            ...$integrity->run(),

            /*
             * ⚠️ The machine's half — the licence, the disk, the code — which
             * until today only `php artisan shop:doctor` could see, and a
             * shopkeeper on cPanel has no terminal to run it in.
             */
            'health' => $health->run(),
        ]);
    }
}
