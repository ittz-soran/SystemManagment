@props(['state'])

{{-- Section 9b: "a single quiet banner stating the reason and what would unlock
     it. Not a red alert; it is normal, not an error." --}}
@if(! $state['allowed'])
    <div class="alert alert-secondary d-flex align-items-center gap-2 py-2">
        <i class="bi bi-lock"></i>
        <span>{{ $state['reason'] }}</span>
    </div>
@endif
