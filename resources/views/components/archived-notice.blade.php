@props(['count' => 0])

{{-- Archiving hides a period; it never deletes it. So the list says how much it
     is not showing, and offers to show it. --}}
@if($count > 0)
    <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2">
        <i class="bi bi-archive"></i>

        @if(request()->boolean('archived'))
            <span>{{ trans_choice(
                '{1}Showing the archived period as well — :count older record.'
                .'|[2,*]Showing the archived period as well — :count older records.',
                $count, ['count' => number_format($count)],
            ) }}</span>

            <a class="ms-auto" href="{{ request()->fullUrlWithQuery(['archived' => null, 'page' => null]) }}">
                {{ __('Hide them again') }}
            </a>
        @else
            <span>{{ trans_choice(
                '{1}:count older record is archived and not shown.'
                .'|[2,*]:count older records are archived and not shown.',
                $count, ['count' => number_format($count)],
            ) }}</span>

            <a class="ms-auto" href="{{ request()->fullUrlWithQuery(['archived' => 1, 'page' => null]) }}">
                {{ __('Show them') }}
            </a>
        @endif
    </div>
@endif
