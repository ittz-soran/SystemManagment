{{-- Section 9b: inline field errors for validation, never a toast — a toast
     vanishes before the form can be fixed. Toasts are for success only. --}}
@if($errors->any() && $errors->count() > 1)
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">{{ __('Please fix the following:') }}</div>
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
