<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">{{ __('Account') }}</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('Name') }}</label>
                    <input id="name" name="name" value="{{ old('name', $user->name) }}"
                           class="form-control @error('name') is-invalid @enderror" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('Email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" dir="ltr"
                           class="form-control @error('email') is-invalid @enderror" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">{{ __('Password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="new-password"
                           class="form-control @error('password') is-invalid @enderror"
                           @required(! $user->exists)>
                    @if($user->exists)
                        <div class="form-text">{{ __('Leave blank to keep the current password.') }}</div>
                    @endif
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">{{ __('Confirm password') }}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="form-control" autocomplete="new-password" @required(! $user->exists)>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">{{ __('Role') }}</label>
                    <select id="role" name="role" class="form-select" required>
                        <option value="user" @selected(old('role', $user->role) === 'user')>{{ __('User') }}</option>
                        <option value="admin" @selected(old('role', $user->role) === 'admin')>{{ __('Admin') }}</option>
                    </select>
                    {{-- Section 2: admin has full access, always, and cannot be
                         restricted — so the permission editor is hidden for them. --}}
                    <div class="form-text">{{ __('An admin always has everything and cannot be restricted.') }}</div>
                </div>

                <div class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $user->is_active ?? true))>
                    <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">{{ __('Preferences') }}</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="language" class="form-label">{{ __('Language') }}</label>
                    <select id="language" name="language" class="form-select">
                        @foreach(\App\Http\Middleware\SetUserPreferences::LANGUAGES as $code => $label)
                            <option value="{{ $code }}" @selected(old('language', $user->language ?? 'en') === $code)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="theme" class="form-label">{{ __('Theme') }}</label>
                    <select id="theme" name="theme" class="form-select">
                        @foreach(['light' => __('Light'), 'dark' => __('Dark'), 'auto' => __('Auto')] as $value => $label)
                            <option value="{{ $value }}" @selected(old('theme', $user->theme ?? 'auto') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="items_per_page" class="form-label">{{ __('Rows per page') }}</label>
                    <input id="items_per_page" type="number" min="5" max="200" name="items_per_page" dir="ltr"
                           value="{{ old('items_per_page', $user->items_per_page ?? 25) }}"
                           class="form-control text-end">
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        {{-- Section 9: per-user permission checkboxes. The role sets the
             defaults; the admin then adds or removes individually. --}}
        <div class="card" id="permission-editor">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>{{ __('Permissions') }}</span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" data-permission-action="all">{{ __('All') }}</button>
                    <button type="button" class="btn btn-outline-secondary" data-permission-action="none">{{ __('None') }}</button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach($groups as $group => $permissions)
                        <div class="col-md-6">
                            <div class="border rounded p-2 h-100">
                                <div class="fw-semibold small text-uppercase text-secondary mb-2">
                                    {{ Str::headline($group) }}
                                </div>
                                @foreach($permissions as $permission)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissions[]"
                                               value="{{ $permission->id }}" id="perm-{{ $permission->id }}"
                                               @checked(in_array($permission->id, old('permissions', $selected)))>
                                        <label class="form-check-label small" for="perm-{{ $permission->id }}">
                                            {{ __($permission->label) }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Saving…') }}">{{ __('Save user') }}</button>
    <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
</div>

@push('scripts')
    <script>
        (() => {
            const editor = document.getElementById('permission-editor');
            const role = document.getElementById('role');

            function sync() {
                // An admin's permission rows are never consulted, so hide the
                // editor rather than showing choices that do nothing.
                editor.classList.toggle('d-none', role.value === 'admin');
            }

            editor.querySelectorAll('[data-permission-action]').forEach((button) => {
                button.addEventListener('click', () => {
                    const checked = button.dataset.permissionAction === 'all';
                    editor.querySelectorAll('input[name="permissions[]"]').forEach((box) => {
                        box.checked = checked;
                    });
                });
            });

            role.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
