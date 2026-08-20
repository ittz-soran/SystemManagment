@props(['status'])

{{-- Section 9b: status badges so the list answers questions without opening rows. --}}
@php
    $map = [
        'active' => ['secondary', __('Active')],
        'partly_returned' => ['warning', __('Partly returned')],
        'returned' => ['danger', __('Returned')],
        'paid' => ['success', __('Paid')],
        'partly_paid' => ['warning', __('Partly paid')],
        'unpaid' => ['danger', __('Unpaid')],
    ];
    [$variant, $label] = $map[$status] ?? ['secondary', $status];
@endphp

<span class="badge text-bg-{{ $variant }}">{{ $label }}</span>
