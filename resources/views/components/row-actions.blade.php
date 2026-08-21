@props(['view' => null, 'edit' => null, 'state' => null, 'deleteState' => null, 'delete' => null, 'deleteLabel' => null])

{{-- Section 9b: "View always; Edit/Delete only when unlocked — otherwise render
     them disabled with the lock reason as a tooltip. Never hide them, or Soran
     will think the feature is missing." --}}
@php
    $locked = $state && ! $state['allowed'];

    // Delete can be locked when edit is not — a purchase whose stock has been
    // sold may still be corrected, but no longer removed.
    $deleteState ??= $state;
    $deleteLocked = $deleteState && ! $deleteState['allowed'];
@endphp

<div class="btn-group btn-group-sm">
    @if($view)
        <a href="{{ $view }}" class="btn btn-outline-secondary" title="{{ __('View') }}">
            <i class="bi bi-eye"></i>
        </a>
    @endif

    @if($edit)
        @if($locked)
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $state['reason'] }}">
                <button class="btn btn-outline-secondary" disabled><i class="bi bi-pencil"></i></button>
            </span>
        @else
            <a href="{{ $edit }}" class="btn btn-outline-secondary" title="{{ __('Edit') }}">
                <i class="bi bi-pencil"></i>
            </a>
        @endif
    @endif

    @if($delete)
        @if($deleteLocked)
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled><i class="bi bi-trash"></i></button>
            </span>
        @else
            <form action="{{ $delete }}" method="POST" class="d-inline"
                  onsubmit="return confirm(@js($deleteLabel ?? __('Delete this record?')))">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger" title="{{ __('Delete') }}">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        @endif
    @endif
</div>
