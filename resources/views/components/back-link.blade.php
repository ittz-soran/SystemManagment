{{--
    The way out of a detail page.

    It names its destination instead of saying "Back", so it is clear where it
    lands before it is clicked, and the arrow follows the reading direction.

    `remember` carries the first path segment of a list page ("sales"). The
    script in app.js swaps the href for the last URL actually visited under that
    segment, so the filters, search and page number the reader had open survive
    the round trip — and it restores the scroll position on arrival, which is the
    part the browser's own Back button gets right and a plain link does not.
--}}
@props(['to', 'label', 'remember' => null, 'permission' => null])

@if(filled($to) && ($permission === null || auth()->user()?->hasPermission($permission)))
    <a href="{{ $to }}"
       class="back-link d-inline-flex align-items-center gap-1 small text-decoration-none"
       data-back-generic="{{ __('Back') }}"
       @if($remember) data-back-to="{{ $remember }}" @endif>
        <i class="bi bi-arrow-{{ $isRtl ? 'right' : 'left' }}"></i>
        <span>{{ $label }}</span>
    </a>
@endif
