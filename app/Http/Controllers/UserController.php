<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Section 9: Users — CRUD, admin only, with per-user permission checkboxes.
 * The role sets the defaults; the admin then adds or removes individually.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        return view('users.index', [
            'users' => User::withCount('permissions')
                ->orderBy('name')
                ->paginate($request->user()->items_per_page),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'user' => new User(['role' => User::ROLE_USER, 'is_active' => true, 'items_per_page' => 25]),
            'groups' => $this->permissionGroups(),
            'selected' => Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        DB::transaction(function () use ($request, $data) {
            $user = User::create($data);

            // Section 4: creating a user seeds the default set. Any explicit
            // selection from the form wins over it.
            $permissions = $request->has('permissions')
                ? $request->array('permissions')
                : Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')->all();

            $user->permissions()->sync($user->isAdmin() ? [] : $permissions);
        });

        return redirect()->route('users.index')->with('success', __('User saved'));
    }

    public function edit(User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'groups' => $this->permissionGroups(),
            'selected' => $user->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->rules($request, $user);

        DB::transaction(function () use ($request, $user, $data) {
            if (blank($data['password'] ?? null)) {
                unset($data['password']);
            }

            $user->update($data);

            // Section 4: never consulted for admin accounts, so an admin carries
            // no permission rows at all.
            $user->permissions()->sync($user->isAdmin() ? [] : $request->array('permissions'));
        });

        return redirect()->route('users.index')->with('success', __('User saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account here.'));
        }

        // Section 5: users are always `restrict` — a deleted employee would
        // erase who made every document. Soft delete keeps the row.
        $user->delete();

        return back()->with('success', __('User deleted'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)->withoutTrashed()],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'is_active' => ['boolean'],
            'language' => ['required', Rule::in(array_keys(\App\Http\Middleware\SetUserPreferences::LANGUAGES))],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
        ]);
    }

    /** @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permission>> */
    private function permissionGroups()
    {
        return Permission::orderBy('group')->orderBy('key')->get()->groupBy('group');
    }
}
