{{--
    A polymorphic reference, rendered as something the reader can follow.

    Pass the eager-loaded relation as :document — it supplies the document
    number, which is what a person recognises, rather than the row id. :type and
    :id are the raw columns, and are what the component falls back to when the
    document itself is gone.

    App\Support\DocumentLink decides whether a link is offered at all: documents
    with no detail page (payments, adjustments) and readers without the
    permission to open one both get plain text, never a link into a 404 or a 403.
--}}
@props(['document' => null, 'type' => null, 'id' => null, 'kind' => true])

@php
    $linker = \App\Support\DocumentLink::class;
    $url = $linker::url($document, auth()->user());
    $label = $linker::label($document, $type, $id);
@endphp

<span class="d-inline-flex align-items-center gap-1 flex-wrap">
    @if($kind)
        <span class="badge text-bg-light">{{ $linker::kind($type) }}</span>
    @endif

    @if($url)
        <a href="{{ $url }}" class="text-decoration-none" dir="ltr">{{ $label }}</a>
    @else
        <span class="text-secondary" dir="ltr">{{ $label }}</span>
    @endif
</span>
