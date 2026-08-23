@extends('layouts.app')

@section('title', __('Categories'))

@section('actions')
    @can('categories.create')
        {{-- Section 9b: modal for a single short form. --}}
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#category-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New category') }}
        </button>
    @endcan
@endsection

@section('content')
    @if($categories->isEmpty())
        <div class="card">
            <x-empty-state icon="tags" :message="__('No categories yet. Every product needs one.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Parent') }}</th>
                        <th class="money">{{ __('Products') }}</th>
                        <th>{{ __('Also holds') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($categories as $category)
                        <tr>
                            <td class="fw-medium">{{ $category->name }}</td>
                            <td class="text-secondary">{{ $category->parent?->name ?? '—' }}</td>
                            <td class="money">
                                @if($category->stocked_count > 0)
                                    <a href="{{ route('products.index', ['categories' => [$category->id]]) }}"
                                       class="text-decoration-none">{{ number_format($category->stocked_count) }}</a>
                                @else
                                    <span class="text-secondary">0</span>
                                @endif
                            </td>
                            {{-- Second-hand items and services are products too,
                                 but they are not on the product list, so their
                                 counts lead to the screens that do show them. --}}
                            <td class="small">
                                @if($category->used_count > 0)
                                    <a href="{{ route('second-hand.index') }}" class="text-decoration-none">
                                        {{ trans_choice('{1}:count second-hand item|[2,*]:count second-hand items',
                                            $category->used_count, ['count' => number_format($category->used_count)]) }}
                                    </a>
                                @endif
                                @if($category->service_count > 0)
                                    <a href="{{ route('services.index') }}" class="text-decoration-none d-block">
                                        {{ trans_choice('{1}:count service|[2,*]:count services',
                                            $category->service_count, ['count' => number_format($category->service_count)]) }}
                                    </a>
                                @endif
                                @if($category->used_count === 0 && $category->service_count === 0)
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    @can('categories.edit')
                                        <button class="btn btn-outline-secondary" data-bs-toggle="modal"
                                                data-bs-target="#category-modal"
                                                data-category="{{ $category->id }}"
                                                data-name="{{ $category->name }}"
                                                data-parent="{{ $category->parent_id }}"
                                                title="{{ __('Edit') }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    @endcan
                                    @can('categories.delete')
                                        <form action="{{ route('categories.destroy', $category) }}" method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm(@js(__('Delete :name?', ['name' => $category->name])))">
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

        <div class="mt-3">{{ $categories->links() }}</div>
    @endif

    @canany(['categories.create', 'categories.edit'])
        <div class="modal fade" id="category-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" id="category-form" action="{{ route('categories.store') }}"
                      method="POST" data-guard-submit>
                    @csrf
                    <input type="hidden" name="_method" id="category-method" value="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="category-modal-title">{{ __('New category') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category-name" class="form-label">{{ __('Name') }}</label>
                            <input id="category-name" name="name" class="form-control" required>
                        </div>
                        <div>
                            <label for="category-parent" class="form-label">{{ __('Parent category') }}</label>
                            <select id="category-parent" name="parent_id" class="form-select">
                                <option value="">{{ __('None — top level') }}</option>
                                @foreach($parents as $parent)
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save category') }}</button>
                    </div>
                </form>
            </div>
        </div>

        @push('scripts')
            <script>
                // One modal for both, told apart by the button that opened it.
                document.getElementById('category-modal')?.addEventListener('show.bs.modal', (event) => {
                    const button = event.relatedTarget;
                    const editing = button?.dataset.category;
                    const form = document.getElementById('category-form');

                    form.action = editing
                        ? '{{ url('categories') }}/' + editing
                        : '{{ route('categories.store') }}';
                    document.getElementById('category-method').value = editing ? 'PUT' : 'POST';
                    document.getElementById('category-modal-title').textContent =
                        editing ? @js(__('Edit category')) : @js(__('New category'));

                    document.getElementById('category-name').value = button?.dataset.name ?? '';

                    const parent = document.getElementById('category-parent');
                    parent.value = button?.dataset.parent ?? '';

                    // A category cannot be its own parent, so it is not offered.
                    [...parent.options].forEach((option) => {
                        option.hidden = Boolean(editing) && option.value === editing;
                    });
                });
            </script>
        @endpush
    @endcanany
@endsection
