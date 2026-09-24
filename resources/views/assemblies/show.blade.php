@extends('layouts.app')

@section('title', $assembly->document_no)
@section('subheading')
    {{ $assembly->assembled_at->format(setting('date_format', 'Y-m-d')) }}
    · {{ $assembly->isApart() ? __('Taken apart') : __('Built from parts') }}
@endsection

@section('back')
    <x-back-link :to="route('assemblies.index')" :label="__('Take apart & build')" remember="assemblies" permission="assemblies.view" />
@endsection

@section('actions')
    @can('assemblies.create')
        @if($deleteState['allowed'])
            <a href="{{ route('assemblies.edit', $assembly) }}" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
            </a>
        @else
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-primary" disabled>
                    <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
                </button>
            </span>
        @endif
    @endcan

    @can('assemblies.delete')
        {{-- ⚠️ Undoing puts the pieces back into the batches they were made
             from and takes them off the shelf, which cannot happen once one has
             been sold. The button says why rather than failing when pressed. --}}
        @if(! $deleteState['allowed'])
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled>
                    <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
                </button>
            </span>
        @else
            <form action="{{ route('assemblies.destroy', $assembly) }}" method="POST"
                  onsubmit="return confirm(@js(__('Delete :document? The pieces come off the shelf and what they were made of goes back on.', [
                      'document' => $assembly->document_no,
                  ])))">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
                </button>
            </form>
        @endif
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    @php
        $whole = $assembly->whole();
        $pieces = $assembly->pieces();
    @endphp

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">{{ $assembly->isApart() ? __('What went in') : __('What came out') }}</div>
                <div class="card-body">
                    @if($whole?->product)
                        <a href="{{ route('products.show', $whole->product) }}" class="fs-5 text-decoration-none">
                            {{ $whole->product->name }}
                        </a>
                        <div class="small text-secondary" dir="ltr">{{ $whole->product->sku }}</div>
                        <div class="mt-3 d-flex justify-content-between">
                            <span class="text-secondary">{{ __('Quantity') }}</span>
                            <span>{{ qty($whole->quantity, $whole->product->unit) }}</span>
                        </div>
                        <div class="d-flex justify-content-between fw-semibold">
                            <span>{{ __('Worth') }}</span>
                            <span class="money">{{ money($whole->lineTotal(), false, $lens) }}</span>
                        </div>
                    @else
                        <div class="text-secondary">—</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">{{ $assembly->isApart() ? __('What came out') : __('What went in') }}</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="money">{{ __('Quantity') }}</th>
                            <th class="money">{{ __('Cost each') }}</th>
                            <th class="money">{{ __('Worth') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($pieces as $piece)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $piece->product) }}" class="text-decoration-none">
                                        {{ $piece->product->name }}
                                    </a>
                                    <div class="small text-secondary" dir="ltr">{{ $piece->product->sku }}</div>
                                </td>
                                <td class="money">{{ qty($piece->quantity, $piece->product->unit) }}</td>
                                <td class="money">{{ money($piece->unit_cost, false, $lens) }}</td>
                                <td class="money fw-semibold">{{ money($piece->lineTotal(), false, $lens) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">{{ __('Total') }}</td>
                            <td class="money">{{ money($assembly->total_cost, false, $lens) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body small text-secondary d-flex align-items-start gap-2">
            <i class="bi bi-info-circle mt-1"></i>
            <div>
                {{-- ⚠️ The one thing a reader is most likely to doubt, said on
                     the document rather than left to be worked out. --}}
                {{ __('Nothing was earned or lost here. What came out is worth exactly what went in — the same :amount, sitting in different places. Your profit report does not count this.', [
                    'amount' => money($assembly->total_cost, false, $lens),
                ]) }}
                @if($assembly->user)
                    <div class="mt-1">{{ __('Done by') }}: {{ $assembly->user->name }}</div>
                @endif
            </div>
        </div>

        @if($assembly->note)
            <div class="card-footer small">
                <span class="text-secondary">{{ __('Note') }}:</span> {{ $assembly->note }}
            </div>
        @endif
    </div>
@endsection
