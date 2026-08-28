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

                {{-- What this person is shown when a screen says what
                     something cost. Hidden for an admin, who always sees the
                     real figure and cannot be restricted. --}}
                <div class="mb-3" id="cost-visibility-block">
                    <label for="cost_visibility" class="form-label">{{ __('What they see a thing cost') }}</label>
                    <select id="cost_visibility" name="cost_visibility" class="form-select">
                        <option value="real" @selected(old('cost_visibility', $user->cost_visibility ?? 'real') === 'real')>
                            {{ __('The real cost') }}
                        </option>
                        <option value="markup" @selected(old('cost_visibility', $user->cost_visibility ?? 'real') === 'markup')>
                            {{ __('The real cost plus a percentage') }}
                        </option>
                        <option value="hidden" @selected(old('cost_visibility', $user->cost_visibility ?? 'real') === 'hidden')>
                            {{ __('Nothing — *****') }}
                        </option>
                    </select>
                    @error('cost_visibility')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

                    <div class="mt-2 {{ old('cost_visibility', $user->cost_visibility ?? 'real') === 'markup' ? '' : 'd-none' }}"
                         id="cost-markup-block">
                        <label for="cost_markup_percent" class="form-label small">{{ __('Add this percentage') }}</label>
                        <div class="input-group">
                            <input id="cost_markup_percent" type="number" min="0" max="500" step="1" dir="ltr"
                                   name="cost_markup_percent" class="form-control text-end"
                                   value="{{ old('cost_markup_percent', $user->cost_markup_percent ?? 10) }}">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">
                            {{ __('At 10%, a thing that cost 1,000 is shown to them as 1,100.') }}
                        </div>
                        @error('cost_markup_percent')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-text">
                        {{ __('Somebody who types what things cost — purchases, adjustments, product prices — has to see the real one.') }}
                    </div>
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
                {{-- Sixty checkboxes with no order of importance is not a
                     choice anybody makes well. The shop hires a person at the
                     counter far more often than it invents a new kind of job,
                     so the three jobs it has are here to start from. Ticking
                     one only moves the boxes; what is saved is whatever is
                     ticked when the form is saved. --}}
                <div class="mb-3">
                    <div class="form-label">{{ __('Start from') }}</div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($presets as $preset)
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-preset="{{ json_encode($preset['ids']) }}"
                                    title="{{ $preset['note'] }}">
                                {{ $preset['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="form-text">{{ __('A starting point, not a setting. Adjust the ticks afterwards.') }}</div>
                </div>

                {{-- The question an admin is really asking. Not "which of these
                     sixty keys", but "what will this person see when they log
                     in" — so the answer is the menu itself, kept up to date as
                     the boxes change. --}}
                <div class="border rounded p-2 mb-3 bg-body-tertiary">
                    <div class="fw-semibold small text-uppercase text-secondary mb-1">
                        {{ __('The menu they will see') }}
                    </div>
                    <div id="menu-preview" class="small"
                         data-empty="{{ __('Nothing. They can sign in and go no further.') }}"
                         data-menu="{{ json_encode(collect($menu)->flatMap(fn ($items) => collect($items)->map(fn ($item) => [
                             'label' => $item['label'],
                             'permission' => $item['permission'],
                             'admin' => $item['admin'] ?? false,
                         ]))->values()) }}"></div>
                </div>

                <div class="row g-3">
                    @foreach($groups as $group => $permissions)
                        <div class="col-md-6">
                            <div class="border rounded p-2 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold small text-uppercase text-secondary">
                                        {{ Str::headline($group) }}
                                    </span>
                                    <button type="button" class="btn btn-sm btn-link p-0 small"
                                            data-group-toggle>{{ __('All') }}</button>
                                </div>
                                @foreach($permissions as $permission)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permissions[]"
                                               value="{{ $permission->id }}" id="perm-{{ $permission->id }}"
                                               data-key="{{ $permission->key }}"
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

                // An admin always sees the real cost, so there is nothing to set.
                document.getElementById('cost-visibility-block')
                    ?.classList.toggle('d-none', role.value === 'admin');

                preview();
            }

            const boxes = () => [...editor.querySelectorAll('input[name="permissions[]"]')];

            editor.querySelectorAll('[data-permission-action]').forEach((button) => {
                button.addEventListener('click', () => {
                    const checked = button.dataset.permissionAction === 'all';
                    boxes().forEach((box) => { box.checked = checked; });
                    preview();
                });
            });

            // A starting point: tick exactly this job's keys, untick the rest.
            editor.querySelectorAll('[data-preset]').forEach((button) => {
                button.addEventListener('click', () => {
                    const wanted = new Set(JSON.parse(button.dataset.preset).map(Number));

                    boxes().forEach((box) => { box.checked = wanted.has(Number(box.value)); });
                    preview();
                });
            });

            // Each group's own all-or-nothing, which is the same button twice
            // over: tick them all, unless they already are.
            editor.querySelectorAll('[data-group-toggle]').forEach((button) => {
                button.addEventListener('click', () => {
                    const group = [...button.closest('.border').querySelectorAll('input[name="permissions[]"]')];
                    const turnOn = group.some((box) => ! box.checked);

                    group.forEach((box) => { box.checked = turnOn; });
                    preview();
                });
            });

            /*
             * What this person will see when they sign in, kept up to date as
             * the boxes change.
             *
             * The same map the sidebar itself is drawn from, so this is not a
             * description of the menu — it is the menu.
             */
            const panel = document.getElementById('menu-preview');
            const screens = JSON.parse(panel?.dataset.menu ?? '[]');

            function preview() {
                if (! panel) {
                    return;
                }

                const isAdmin = role.value === 'admin';
                const held = new Set(boxes().filter((box) => box.checked).map((box) => box.dataset.key));

                const visible = screens.filter((screen) =>
                    (isAdmin || ! screen.admin) && (isAdmin || held.has(screen.permission)));

                panel.textContent = '';

                if (! visible.length) {
                    panel.append(Object.assign(document.createElement('span'), {
                        className: 'text-secondary',
                        textContent: panel.dataset.empty,
                    }));

                    return;
                }

                visible.forEach((screen) => {
                    panel.append(Object.assign(document.createElement('span'), {
                        className: 'badge text-bg-light me-1 mb-1 fw-normal',
                        textContent: screen.label,
                    }));
                });
            }

            editor.addEventListener('change', (event) => {
                if (event.target.matches('input[name="permissions[]"]')) {
                    preview();
                }
            });

            preview();

            // The percentage only matters for one of the three.
            const visibility = document.getElementById('cost_visibility');
            const markup = document.getElementById('cost-markup-block');

            visibility?.addEventListener('change', () => {
                markup?.classList.toggle('d-none', visibility.value !== 'markup');
            });

            role.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
