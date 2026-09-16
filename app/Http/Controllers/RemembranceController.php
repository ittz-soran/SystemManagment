<?php

namespace App\Http\Controllers;

use App\Support\Adhkar;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The remembrances — أذكار.
 *
 * ⚠️ **No permission, and no writes.** There is nothing here to save: the list
 * belongs to the shop and lives in Settings, and the tally beside each line
 * lives in the reader's own browser for today only. A page that stores nothing
 * is a page that cannot be wrong about anybody's data.
 */
class RemembranceController extends Controller
{
    public function index(Request $request): View
    {
        return view('remembrance.index', [
            // Every list, not only the one whose hour it is: somebody opening
            // this page at noon may well want to read the morning ones, and a
            // page that hid them would be a page that looked broken.
            'lists' => Adhkar::all(),
            'window' => Adhkar::now(),
            'off' => (bool) ($request->user()->getAttributes()['adhkar_off'] ?? false),
        ]);
    }
}
