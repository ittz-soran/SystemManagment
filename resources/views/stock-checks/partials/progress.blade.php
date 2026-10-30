{{--
    How far a round has got — PROJECT_DOC Section 9, Stock check: "120 of 450 ·
    confirmed 110 · wrong 6 · skipped 4". $progress is StockCheck::progress().
--}}
@php($total = max(1, $progress['total']))
<div class="progress" role="progressbar" style="height: {{ ($slim ?? false) ? '.4rem' : '.6rem' }}"
     aria-valuenow="{{ $progress['done'] }}" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}">
    <div class="progress-bar bg-success" style="width: {{ $progress['confirmed'] / $total * 100 }}%"></div>
    <div class="progress-bar bg-danger" style="width: {{ $progress['wrong'] / $total * 100 }}%"></div>
    <div class="progress-bar bg-warning" style="width: {{ $progress['skipped'] / $total * 100 }}%"></div>
</div>
<div class="small text-secondary mt-1">
    {{ __(':done of :total', ['done' => $progress['done'], 'total' => $progress['total']]) }}
    · <span class="text-success">{{ __('confirmed :n', ['n' => $progress['confirmed']]) }}</span>
    · <span class="text-danger">{{ __('wrong :n', ['n' => $progress['wrong']]) }}</span>
    · <span class="text-warning-emphasis">{{ __('skipped :n', ['n' => $progress['skipped']]) }}</span>
</div>
