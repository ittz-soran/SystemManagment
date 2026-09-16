@extends('layouts.app')

@section('title', __('Move stock'))

@section('content')
    {{--
        **Soran, 2026-09-15:** *"when purchased book at main storage then do
        transfer to another storage"*, and any room to any room.

        ⚠️ The picker offers only what the FROM room actually holds. A list of
        the whole catalogue would let somebody fill a transfer with things that
        are not in that room and find out one line at a time. The engine refuses
        either way; this is about not wasting a morning.
    --}}
    <form method="POST" action="{{ route('stock-transfers.store') }}" id="transfer-form" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label for="from_room_id" class="form-label">{{ __('From') }}</label>
                                <select id="from_room_id" name="from_room_id" class="form-select" required
                                        data-stock-url="{{ route('stock-transfers.stock', ['stockRoom' => '__ROOM__']) }}">
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}" @selected(old('from_room_id', $fromId) == $room->id)>
                                            {{ $room->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-2 d-none d-md-flex align-items-end justify-content-center pb-2">
                                {{-- Mirrors in RTL with the rest of the page, which
                                     is why it is an icon rather than an arrow drawn
                                     into the label. --}}
                                <i class="bi bi-arrow-right fs-4 text-secondary" aria-hidden="true"></i>
                            </div>

                            <div class="col-md-5">
                                <label for="to_room_id" class="form-label">{{ __('To') }}</label>
                                {{-- ⚠️ Never the same room this is coming FROM.
                                     Both dropdowns listing every room in the
                                     same order meant "To" opened on the room
                                     already chosen in "From", so the first save
                                     anybody tried was always refused. --}}
                                @php($toDefault = old('to_room_id') ?: $rooms->first(fn ($r) => $r->is_active && $r->id !== (int) $fromId)?->id)
                                <select id="to_room_id" name="to_room_id" class="form-select" required>
                                    @foreach($rooms as $room)
                                        @continue(! $room->is_active)
                                        <option value="{{ $room->id }}" @selected($toDefault == $room->id)>
                                            {{ $room->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Closed rooms are not offered.') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="transferred_at" class="form-label">{{ __('Date') }}</label>
                                <input id="transferred_at" type="date" name="transferred_at" class="form-control"
                                       value="{{ old('transferred_at', today()->toDateString()) }}" required>
                            </div>

                            <div class="col-md-6">
                                <label for="note" class="form-label">{{ __('Note') }}</label>
                                <input id="note" name="note" class="form-control" maxlength="255" value="{{ old('note') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ __('What is moving') }}</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="transfer-lines">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="text-end" style="width: 10rem">{{ __('In this room') }}</th>
                                <th class="text-end" style="width: 11rem">{{ __('Move') }}</th>
                            </tr>
                            </thead>
                            <tbody id="transfer-rows">
                                {{-- Drawn by the script below from what the room
                                     holds, and redrawn whenever the room changes. --}}
                            </tbody>
                        </table>
                    </div>

                    <div id="transfer-empty" class="p-4 text-center text-secondary small d-none">
                        {{ __('This room is empty.') }}
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="text-secondary small">{{ __('Units moving') }}</div>
                        <div class="running-total" id="transfer-total">0</div>
                        <div class="small text-secondary" id="transfer-lines-count"></div>
                    </div>
                </div>

                {{-- ⚠️ Not sticky. A bottom-sticky block is pinned to the bottom
                     of the window and paints over whatever the last field is —
                     see sales/create.blade.php for the measurement. --}}
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" id="transfer-save" disabled>
                        {{ __('Move stock') }}
                    </button>
                    <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    /**
     * The transfer cart.
     *
     * ⚠️ Rows are built with createElement and textContent, never innerHTML: a
     * product name is typed by a shopkeeper and drawn on somebody else's screen.
     */
    (function () {
        const from = document.getElementById('from_room_id');
        const rows = document.getElementById('transfer-rows');
        const empty = document.getElementById('transfer-empty');
        const total = document.getElementById('transfer-total');
        const counted = document.getElementById('transfer-lines-count');
        const save = document.getElementById('transfer-save');

        const words = {
            lines: @json(__(':count products')),
        };

        let stock = @json($stock);

        function sum() {
            let units = 0;
            let lines = 0;

            rows.querySelectorAll('input[data-role="move"]').forEach((box) => {
                const value = Number(box.value) || 0;

                if (value > 0) { units += value; lines += 1; }
            });

            total.textContent = new Intl.NumberFormat('en-US').format(units);
            counted.textContent = words.lines.replace(':count', String(lines));
            save.disabled = units === 0;
        }

        function draw() {
            rows.replaceChildren();
            empty.classList.toggle('d-none', stock.length > 0);

            stock.forEach((item, index) => {
                const tr = document.createElement('tr');

                const name = document.createElement('td');
                const strong = document.createElement('div');
                strong.textContent = item.name;
                const sku = document.createElement('div');
                sku.className = 'small text-secondary app-code';
                sku.textContent = item.sku ?? '';
                name.append(strong, sku);

                const has = document.createElement('td');
                has.className = 'text-end';
                has.textContent = new Intl.NumberFormat('en-US').format(item.units)
                    + (item.unit ? ' ' + item.unit : '');

                const cell = document.createElement('td');
                const id = document.createElement('input');
                id.type = 'hidden';
                id.name = `lines[${index}][product_id]`;
                id.value = item.id;

                const box = document.createElement('input');
                box.type = 'number';
                box.className = 'form-control form-control-sm text-end';
                box.dir = 'ltr';
                box.min = '0';
                box.max = String(item.units);
                box.value = '0';
                box.name = `lines[${index}][quantity]`;
                box.dataset.role = 'move';
                box.setAttribute('data-english-digits', '');
                box.addEventListener('input', sum);

                cell.append(id, box);
                tr.append(name, has, cell);
                rows.append(tr);
            });

            sum();
        }

        from.addEventListener('change', async () => {
            const url = from.dataset.stockUrl.replace('__ROOM__', from.value);

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                stock = response.ok ? await response.json() : [];
            } catch {
                stock = [];
            }

            draw();
        });

        draw();
    })();
</script>
@endpush
