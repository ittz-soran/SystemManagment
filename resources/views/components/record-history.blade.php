{{--
    Who touched this record, when, and what it was before.

    Section 4 already records all of it — activity_logs holds every create, edit
    and delete with the previous version of whatever changed — and the only way
    to read it used to be the whole shop's log, in one list, with this record's
    entries somewhere in it. Here it is on the record's own page, which is where
    somebody stands when they ask why a figure is what it is.

    Whatever a document's own tables say happened to the stock or the money,
    this says what happened to the record.
--}}
<div class="card {{ $bordered ? 'mt-3' : '' }}">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history"></i>
        {{ __('History') }}
    </div>

    @if($entries === [])
        <x-empty-state icon="clock-history"
                       :message="__('Nothing recorded yet. Changes from here on are kept.')" />
    @else
        <ul class="list-group list-group-flush">
            @foreach($entries as $entry)
                <li class="list-group-item">
                    <div class="d-flex flex-wrap align-items-baseline gap-2">
                        <span class="badge {{ match($entry['action']) {
                            'create' => 'text-bg-success',
                            'delete' => 'text-bg-danger',
                            'restore' => 'text-bg-info',
                            default => 'text-bg-secondary',
                        } }}">
                            {{ match($entry['action']) {
                                'create' => __('Created'),
                                'update' => __('Edited'),
                                'delete' => __('Deleted'),
                                'restore' => __('Brought back'),
                                default => Str::headline($entry['action']),
                            } }}
                        </span>

                        <span class="fw-medium">{{ $entry['by'] }}</span>

                        <span class="text-secondary small" dir="ltr">
                            {{ $entry['at']->format(setting('date_format', 'Y-m-d')) }}
                            {{ $entry['at']->format('H:i') }}
                        </span>

                        @if($entry['ip'])
                            <span class="text-secondary small" dir="ltr">· {{ $entry['ip'] }}</span>
                        @endif
                    </div>

                    {{-- Not everything worth recording is a column. Changing
                         somebody's permissions moves rows in another table
                         entirely, so that entry carries a sentence rather than
                         a list of before-and-afters. --}}
                    @if(! $entry['changes'] && $entry['description'])
                        <div class="small mt-1 text-secondary">{{ $entry['description'] }}</div>
                    @endif

                    @if($entry['changes'])
                        <div class="small mt-2">
                            @foreach($entry['changes'] as $change)
                                <div>
                                    <span class="text-secondary">{{ $change['label'] }}</span>
                                    <span class="text-decoration-line-through text-secondary ms-1">{{ $change['from'] }}</span>
                                    <i class="bi bi-arrow-right text-secondary mx-1"></i>
                                    <span class="fw-medium">{{ $change['to'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
