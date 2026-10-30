{{--
    Previous and next, by number — PROJECT_DOC Section 9b, Document detail
    page. Soran, 2026-10-30: "for ex i am in INV-00110 and i want go to after
    this or before this", drawn like the list pages' pagination as he asked.

    At either end the arrow stays, greyed, so the pair does not move under the
    reader's hand. ‹ and › are mirrored by the browser in a right-to-left
    language, so "previous" points the way that reader goes back.
--}}
@props(['document'])

@php
    $steps = App\Support\DocumentSteps::around($document);
@endphp

<nav aria-label="{{ __('Previous and next') }}" class="no-print">
    <ul class="pagination mb-0">
        <li class="page-item {{ $steps['previous'] ? '' : 'disabled' }}">
            @if($steps['previous'])
                <a class="page-link" href="{{ $steps['previous']['url'] }}" rel="prev"
                   aria-label="{{ __('Previous: :number', ['number' => $steps['previous']['number']]) }}">
                    &lsaquo; {{ $steps['previous']['number'] }}
                </a>
            @else
                <span class="page-link" aria-disabled="true" title="{{ __('This is the first') }}">&lsaquo;</span>
            @endif
        </li>
        <li class="page-item {{ $steps['next'] ? '' : 'disabled' }}">
            @if($steps['next'])
                <a class="page-link" href="{{ $steps['next']['url'] }}" rel="next"
                   aria-label="{{ __('Next: :number', ['number' => $steps['next']['number']]) }}">
                    {{ $steps['next']['number'] }} &rsaquo;
                </a>
            @else
                <span class="page-link" aria-disabled="true" title="{{ __('This is the last') }}">&rsaquo;</span>
            @endif
        </li>
    </ul>
</nav>
