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

        // Section 4: creating a user seeds the default set. Any explicit
        // selection from the form wins over it.
        $permissions = $request->has('permissions')
            ? $request->array('permissions')
            : Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')->all();

        if ($problem = $this->refuseCostContradictions($request, $data['cost_visibility'], $permissions)) {
            return back()->withInput()->with('error', $problem);
        }

        DB::transaction(function () use ($data, $permissions) {
            $user = User::create($data);

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

        // The shop has to keep a way back in. An admin who demotes or
        // deactivates themselves has just closed the only screen that could
        // undo it, and on a one-admin shop that is the whole system locked.
        if ($user->is($request->user())
            && ($data['role'] !== User::ROLE_ADMIN || ! ($data['is_active'] ?? false))) {
            return back()
                ->withInput()
                ->with('error', __('You cannot take away your own admin access.'));
        }

        if ($problem = $this->refuseCostContradictions($request, $data['cost_visibility'], $request->array('permissions'))) {
            return back()->withInput()->with('error', $problem);
        }

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)->withoutTrashed()],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            // Absent means the real cost, which is what everybody had before
            // the setting existed.
            'cost_visibility' => ['nullable', Rule::in([User::COST_REAL, User::COST_MARKUP, User::COST_HIDDEN])],
            'cost_markup_percent' => ['nullable', 'integer', 'min:0', 'max:500'],
            'is_active' => ['boolean'],
            'language' => ['required', Rule::in(array_keys(\App\Http\Middleware\SetUserPreferences::LANGUAGES))],
            'theme' => ['required', Rule::in(['light', 'dark', 'auto'])],
            'items_per_page' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        $data['cost_visibility'] ??= User::COST_REAL;

        $data['cost_markup_percent'] = $data['cost_visibility'] === User::COST_MARKUP
            ? (int) ($data['cost_markup_percent'] ?? 0)
            : 0;

        return $data;
    }

    /**
     * The keys nobody can hold alongside a cost they are not shown.
     *
     * Two kinds, and both would make the setting a decoration rather than a
     * rule. Somebody who *types* a cost — a purchase, an adjustment, a
     * product's price — has to be typing the real one, or a marked-up figure
     * gets saved back as fact and the shop's books quietly become wrong.
     * Somebody who opens a screen that is *about* what the shop pays — the
     * purchase documents, the returns against them, the reports — is being
     * handed the figure anyway, because those screens are the accounts
     * themselves and are not masked.
     *
     * Refused here, on the form, rather than left as a trap that looks like it
     * is working.
     *
     * @param  list<int>  $permissionIds
     */
    private function refuseCostContradictions(Request $request, string $visibility, array $permissionIds): ?string
    {
        if ($visibility === User::COST_REAL || $request->input('role') === User::ROLE_ADMIN) {
            return null;
        }

        $keys = Permission::whereIn('id', $permissionIds)->pluck('key');

        $typesCost = $keys->intersect([
            'purchases.create', 'purchases.edit',
            'stock_adjustments.create', 'stock_adjustments.edit',
            'products.create', 'products.edit',
        ]);

        if ($typesCost->isNotEmpty()) {
            return __('Somebody who types what things cost has to see the real one. Give them the real cost, or take away: :keys', [
                'keys' => $typesCost->implode(', '),
            ]);
        }

        $readsCost = $keys->intersect(['purchases.view', 'purchase_returns.view', 'reports.view']);

        if ($readsCost->isNotEmpty()) {
            return __('These screens are what the shop pays, written out in full, and they are not masked. Give them the real cost, or take away: :keys', [
                'keys' => $readsCost->implode(', '),
            ]);
        }

        return null;
    }

    /**
     * The checkboxes an admin may tick.
     *
     * `users.*` is left out. This screen is admin-only at the router (see
     * EnsureAdmin), so ticking users.view for a member of staff would promise
     * a screen they still could not open — and if it did open, whoever holds
     * it could save themselves role = admin. A checkbox that either does
     * nothing or hands over the shop should not be on the page.
     *
     * Rows already granted disappear on the next save: sync() is given what
     * the form posted, and the form no longer posts them.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permission>>
     */
    private function permissionGroups()
    {
        return Permission::whereNot('group', 'users')
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group');
    }
}
