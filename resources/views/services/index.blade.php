@extends('layouts.app')

@section('title', __('Services'))
@section('subheading', __('Sold, never stocked — the whole price is profit'))

@section('actions')

    @can('products.create')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#service-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New service') }}
        </button>
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label small">{{ __('Name') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm">
            </div>

            {{-- ⚠️ The two boxes that stop this table and the profit report
                 disagreeing. Sold and Earned are read over this period; without
                 it the table said "all time" and the report said "this month",
                 and neither of them said which. --}}
            <div class="col-6 col-md-3">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" dir="ltr" value="{{ $from->toDateString() }}"
                       class="form-control form-control-sm" {{ $all ? 'disabled' : '' }}>
            </div>

            <div class="col-6 col-md-3">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" dir="ltr" value="{{ $to->toDateString() }}"
                       class="form-control form-control-sm" {{ $all ? 'disabled' : '' }}>
            </div>

            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-sm btn-outline-secondary">{{ __('Filter') }}</button>
            </div>
        </div>
    </form>

    {{-- What the two money columns are counting, said in words above them
         rather than left for the reader to assume. --}}
    <p class="small text-secondary d-flex flex-wrap align-items-center gap-2 mb-3">
        <i class="bi bi-calendar3"></i>
        @if($all)
            <span>{{ __('Sold and Earned count every service ever sold.') }}</span>
            <a href="{{ route('services.index', ['search' => request('search')]) }}">{{ __('Show this month instead') }}</a>
        @else
            <span dir="ltr" class="app-code">{{ $from->format(setting('date_format', 'Y-m-d')) }} → {{ $to->format(setting('date_format', 'Y-m-d')) }}</span>
            <span>{{ __('— Sold and Earned count only what was sold between these dates, the same as the profit report.') }}</span>
            <a href="{{ route('services.index', ['search' => request('search'), 'all' => 1]) }}">{{ __('All time') }}</a>
        @endif
    </p>

    @if($services->isEmpty())
        <x-empty-state icon="magic"
                       :message="__('No services yet. A service is something you do rather than sell — it moves no stock and costs you nothing, so everything it earns is profit.')" />
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Service') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th class="money">{{ __('Price') }}</th>
                        <th class="money">{{ __('Sold') }}</th>
                        <th class="money">{{ __('Earned') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($services as $service)
                        @php($row = $earned[$service->id] ?? null)
                        <tr class="{{ $service->is_active ? '' : 'opacity-50' }}">
                            <td class="fw-medium">
                                {{ $service->name }}
                                <div class="small text-secondary" dir="ltr">{{ $service->sku }}</div>
                                @unless($service->is_active)
                                    <span class="badge text-bg-light">{{ __('Inactive') }}</span>
                                @endunless
                            </td>
                            <td class="small text-secondary">{{ $service->category->name }}</td>
                            <td class="money">{{ money($service->sale_price, in: $lens) }}</td>
                            <td class="money text-secondary">{{ number_format((int) ($row->units ?? 0)) }}</td>
                            {{-- Earned, not revenue: a service has no cost, so
                                 the two are the same number. --}}
                            <td class="money fw-semibold text-success">
                                {{ money((int) ($row->revenue ?? 0), in: $lens) }}
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    @can('products.edit')
                                        <button class="btn btn-outline-secondary" data-bs-toggle="modal"
                                                data-bs-target="#service-modal"
                                                data-service="{{ $service->id }}"
                                                data-name="{{ $service->name }}"
                                                {{-- ⚠️ Plain, in the currency the box takes: a
                                                     separator empties a number input, and the modal
                                                     posts this same string back as
                                                     `sale_price_shown`. See App\Support\MoneyInput. --}}
                                                data-price="{{ \App\Support\Money::plain($service->sale_price, $lens) }}"
                                                data-category="{{ $service->category_id }}"
                                                data-active="{{ $service->is_active ? 1 : 0 }}"
                                                title="{{ __('Edit') }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    @endcan
                                    @can('products.delete')
                                        <form action="{{ route('services.destroy', $service) }}" method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm(@js(__('Delete :name?', ['name' => $service->name])))">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline-danger" title="{{ __('Delete') }}">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $services->links() }}</div>
    @endif

    @can('products.create')
        <div class="modal fade" id="service-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" id="service-form" action="{{ route('services.store') }}"
                      method="POST" data-guard-submit>
                    @csrf
                    <input type="hidden" name="_method" id="service-method" value="POST">

                    <div class="modal-header">
                        <h5 class="modal-title" id="service-modal-title">{{ __('New service') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('Close') }}"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="service-name" class="form-label">{{ __('Name') }}</label>
                            <input id="service-name" name="name" class="form-control" required
                                   placeholder="{{ __('Create an email account') }}">
                        </div>

                        <div class="mb-3">
                            <label for="service-price" class="form-label">{{ __('Price') }}</label>
                            <x-money-input id="service-price" name="sale_price" :lens="$lens" :min="0"
                                           required :data-numpad="__('Price')" />
                            <div class="form-text">
                                {{ __('There is no cost to set against it, so this is what it earns.') }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="service-category" class="form-label">{{ __('Category') }}</label>
                            <select id="service-category" name="category_id" class="form-select"
                                    data-default="{{ $defaultCategory->id }}">
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}"
                                        @selected($category->id === $defaultCategory->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-check" id="service-active-row" hidden>
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="service-active" checked>
                            <label class="form-check-label" for="service-active">{{ __('Active') }}</label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            {{ __('Cancel') }}
                        </button>
                        <button class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        @push('scripts')
            <script>
                // One modal for both, told apart by the button that opened it.
                document.getElementById('service-modal')?.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    const form = document.getElementById('service-form');
                    const editing = button?.dataset.service;

                    form.action = editing
                        ? '{{ url('services') }}/' + editing
                        : '{{ route('services.store') }}';
                    document.getElementById('service-method').value = editing ? 'PUT' : 'POST';
                    document.getElementById('service-modal-title').textContent =
                        editing ? @js(__('Edit service')) : @js(__('New service'));

                    document.getElementById('service-name').value = button?.dataset.name ?? '';
                    const price = button?.dataset.price ?? '';
                    document.getElementById('service-price').value = price;

                    // ⚠️ And the companion field, so a save that never touched
                    // the box keeps the stored figure exactly. See MoneyInput.
                    const shownPrice = document.querySelector('[name="sale_price_shown"]');

                    if (shownPrice) shownPrice.value = price;
                    document.getElementById('service-active-row').hidden = ! editing;
                    document.getElementById('service-active').checked = button?.dataset.active !== '0';

                    const category = document.getElementById('service-category');

                    category.value = button?.dataset.category ?? category.dataset.default;
                });
            </script>
        @endpush
    @endcan
@endsection
