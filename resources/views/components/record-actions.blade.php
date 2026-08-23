@props(['state' => null, 'deleteState' => null, 'edit' => null, 'delete' => null, 'deleteLabel' => null])

{{-- Section 9b: "View always; Edit/Delete only when unlocked — otherwise render
     them disabled with the lock reason as a tooltip. Never hide them, or Soran
     will think the feature is missing."

     row-actions is the icon-only version for list rows; this is the labelled
     version for the header of a single record's page. --}}
@php
    $locked = $state && ! $state['allowed'];

    // Delete can be locked when edit is not — a purchase whose stock has been
    // sold may still be corrected, but no longer removed.
    $deleteState ??= $state;
    $deleteLocked = $deleteState && ! $deleteState['allowed'];
@endphp

@if($edit)
    @if($locked)
        <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $state['reason'] }}">
            <button class="btn btn-outline-secondary" disabled>
                <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
            </button>
        </span>
    @else
        <a href="{{ $edit }}" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </a>
    @endif
@endif

@if($delete)
    @if($deleteLocked)
        <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
            <button class="btn btn-outline-danger" disabled>
                <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
            </button>
        </span>
    @else
        <form action="{{ $delete }}" method="POST" class="d-inline"
              onsubmit="return confirm(@js($deleteLabel ?? __('Delete this record?')))">
            @csrf
            @method('DELETE')
            <x-return-to />
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
            </button>
        </form>
    @endif
@endif
