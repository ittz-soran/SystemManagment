@props(['kind', 'for', 'ignore' => null])

{{--
    The words this shop writes, handed to the box that will finish them —
    Soran, 2026-09-27. Section 9, "Finishing the word as you type".

    ⚠️ **It ships WITH the page, not a request per keystroke.** A shop counts
    at a counter with a customer waiting; a suggestion that arrives after the
    next letter has been typed is worse than no suggestion at all.

    ⚠️ **The reading half is in app.js**, which finds the box by the attribute
    below. Deliberately that way round: app.js is a deferred module, so a page
    that calls INTO it runs first and silently does nothing — which is exactly
    what happened to the cart leave-guard.

    Usage, on the input and once anywhere on the page:

        <input id="name" data-word-help="words-name" data-word-help-row="row-name" …>
        <x-word-help kind="products" for="name" />
--}}
@php($list = ['w' => App\Support\WordList::for($kind, $ignore), 'after' => App\Support\WordList::pairs($kind, $ignore)])

@if($list['w'] !== [])
    {{-- ⚠️ **NOT `@@json`, AND THAT IS NOT A STYLE CHOICE.** `@@json` escapes
         quotes to `\u0022` for dropping into HTML, which turns the structural
         quotes of the document itself into escapes — `[{\u0022w\u0022:…}]` is
         not JSON, `JSON.parse` throws, and every box goes quiet with nothing
         in the console to say why. HEX_TAG and HEX_AMP escape only `<`, `>`
         and `&`, which appear solely inside the strings, so a product called
         `</script>` cannot close this block and the JSON stays valid. --}}
    <script type="application/json" id="words-{{ $for }}">{!! json_encode($list, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif

{{-- Always drawn, so the box below it does not jump when the first word
     appears. app.js fills it and empties it. --}}
<div id="row-{{ $for }}" class="app-word-row" role="listbox"
     aria-label="{{ __('Words you already use') }}"></div>
