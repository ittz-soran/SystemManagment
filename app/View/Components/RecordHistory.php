<?php

namespace App\View\Components;

use App\Support\RecordHistory as Trail;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * What has happened to this record, on the record's own page.
 *
 * A component rather than nine copies of the same twenty lines, and it fetches
 * its own rows rather than making nine controllers remember to. The permission
 * is asked here too, so a page cannot get the section by accident and cannot
 * forget to withhold it: no key, no query, no markup.
 */
class RecordHistory extends Component
{
    /** @var list<array<string, mixed>>|null */
    public ?array $entries;

    public function __construct(public Model $for, public bool $bordered = true)
    {
        $this->entries = auth()->user()?->hasPermission('activity_logs.view')
            ? Trail::for($for)
            : null;
    }

    public function shouldRender(): bool
    {
        return $this->entries !== null;
    }

    public function render(): View
    {
        return view('components.record-history');
    }
}
