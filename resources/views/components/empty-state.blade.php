@props(['message', 'action' => null, 'actionLabel' => null, 'icon' => 'inbox'])

{{-- Section 9b: "An empty table is an instruction, not a blank space." --}}
<div class="text-center text-secondary py-5">
    <i class="bi bi-{{ $icon }} fs-1 d-block mb-2 opacity-50"></i>
    <p class="mb-3">{{ $message }}</p>
    @if($action && $actionLabel)
        <a href="{{ $action }}" class="btn btn-primary">{{ $actionLabel }}</a>
    @endif
</div>
